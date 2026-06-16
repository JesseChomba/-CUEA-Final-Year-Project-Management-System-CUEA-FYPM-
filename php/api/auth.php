<?php
/**
 * CUEA FYPM – Authentication API
 *
 * Endpoints (all via POST/GET with ?action=...):
 *   POST ?action=login     – Authenticate by University ID + password
 *   POST ?action=register  – Student self-registration
 *   POST ?action=logout    – Destroy session
 *   GET  ?action=session   – Return current session user info
 */

require_once '../../includes/db.php';
require_once '../../includes/helpers.php';
require_once '../../includes/schema.php';
require_once '../../includes/audit.php';
require_once '../../includes/mailer.php';

//  CORS & session setup 
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize($_GET['action'] ?? '');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

function generateTemporaryPassword(int $length = 12): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $password = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

//  LOGIN 
if ($method === 'POST' && $action === 'login') {
    $universityId = sanitize($body['university_id'] ?? '');
    $password     = $body['password'] ?? '';

    if (empty($universityId) || empty($password)) {
        jsonResponse(400, 'University ID and password are required.');
    }

    $db   = DB::connect();
    ensureSystemUpgradeSchema($db);

    // Coordinators and supervisors log in by email; students log in by university_id_number
    $stmt = $db->prepare(
        'SELECT * FROM users
         WHERE (university_id_number = ? OR email = ?) AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$universityId, $universityId]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role']    = $user['role'];
        $_SESSION['name']    = $user['full_name'];
        $_SESSION['email']   = $user['email'];
        $_SESSION['last_activity'] = time();

        // Never send the hash to the client
        unset($user['password']);
        auditLog((int)$user['user_id'], 'login', 'user', (int)$user['user_id']);

        // Check if student has a supervisor assigned
        if ($user['role'] === 'student') {
            $stmtProj = $db->prepare('SELECT supervisor_id FROM projects WHERE student_id = ? LIMIT 1');
            $stmtProj->execute([$user['user_id']]);
            $proj = $stmtProj->fetch();
            $user['has_supervisor'] = ($proj && !empty($proj['supervisor_id']));
            $user['has_pending_supervisor_preferences'] = false;
            if ($proj && empty($proj['supervisor_id'])) {
                $stmtPending = $db->prepare('
                    SELECT COUNT(*) AS pref_count
                    FROM supervisor_preferences sp
                    JOIN projects p ON sp.project_id = p.project_id
                    WHERE p.student_id = ?
                ');
                $stmtPending->execute([$user['user_id']]);
                $user['has_pending_supervisor_preferences'] = ((int)($stmtPending->fetch()['pref_count'] ?? 0) > 0);
            }
        }

        jsonResponse(200, 'Login successful', $user);
    }

    jsonResponse(401, 'Invalid University ID or password.');
}

//  LOGOUT 
if ($method === 'POST' && $action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    session_destroy();
    jsonResponse(200, 'Logged out successfully.');
}

//  KEEP SESSION ALIVE 
if (($method === 'GET' || $method === 'POST') && $action === 'keep_alive') {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(401, 'Your session has expired. Please log in again.', ['reason' => 'session_expired']);
    }
    $_SESSION['last_activity'] = time();
    jsonResponse(200, 'Session extended.', ['expires_in' => 900]);
}

//  SESSION CHECK 
if ($method === 'GET' && $action === 'session') {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(401, 'Your session has expired. Please log in again.', ['reason' => 'session_expired']);
    }
    $_SESSION['last_activity'] = time();
    $db   = DB::connect();
    ensureSystemUpgradeSchema($db);
    $stmt = $db->prepare('SELECT user_id, full_name, email, role, university_id_number, department, created_at FROM users WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        jsonResponse(401, 'Your session is no longer valid. Please log in again.', ['reason' => 'session_invalid']);
    }

    if ($user['role'] === 'student') {
        $stmtProj = $db->prepare('SELECT project_id, supervisor_id FROM projects WHERE student_id = ? LIMIT 1');
        $stmtProj->execute([$user['user_id']]);
        $proj = $stmtProj->fetch();
        $user['has_supervisor'] = ($proj && !empty($proj['supervisor_id']));
        $user['has_pending_supervisor_preferences'] = false;
        if ($proj && empty($proj['supervisor_id'])) {
            $stmtPending = $db->prepare('SELECT COUNT(*) AS pref_count FROM supervisor_preferences WHERE project_id = ?');
            $stmtPending->execute([$proj['project_id']]);
            $user['has_pending_supervisor_preferences'] = ((int)($stmtPending->fetch()['pref_count'] ?? 0) > 0);
        }
    }

    jsonResponse(200, 'Authenticated', $user);
}

//  FORGOT PASSWORD 
if ($method === 'POST' && $action === 'forgot_password') {
    $identifier = sanitize($body['identifier'] ?? '');
    if ($identifier === '') {
        jsonResponse(400, 'Please enter your University ID or email address.');
    }

    $db = DB::connect();
    ensureSystemUpgradeSchema($db);

    $stmt = $db->prepare('
        SELECT user_id, full_name, email
        FROM users
        WHERE (email = ? OR university_id_number = ?)
          AND is_active = 1
        LIMIT 1
    ');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user || empty($user['email'])) {
        jsonResponse(404, 'No active account with an email address was found for that identifier.');
    }

    $temporaryPassword = generateTemporaryPassword();
    $hash = password_hash($temporaryPassword, PASSWORD_BCRYPT);

    try {
        $db->beginTransaction();
        $update = $db->prepare('UPDATE users SET password = ? WHERE user_id = ?');
        $update->execute([$hash, $user['user_id']]);

        $bodyText = "Hello {$user['full_name']},\n\n"
            . "Your CUEA FYPM password has been reset.\n\n"
            . "Temporary password: {$temporaryPassword}\n\n"
            . "Please log in and change this password from Edit Profile immediately.";
        if (!sendSystemEmail($user['email'], $user['full_name'], 'CUEA FYPM Password Reset', $bodyText)) {
            throw new RuntimeException('Password reset email failed.');
        }

        auditLog((int)$user['user_id'], 'forgot_password_reset', 'user', (int)$user['user_id']);
        $db->commit();
        jsonResponse(200, 'A temporary password has been emailed to your registered address.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        jsonResponse(500, 'Password reset email could not be sent. Please check SMTP settings.');
    }
}

//  UPDATE PROFILE
if ($method === 'POST' && $action === 'update_profile') {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(401, 'Not authenticated.');
    }
    
    $fullName = sanitize($body['full_name'] ?? '');
    $newPassword = $body['password'] ?? '';
    
    if (empty($fullName)) {
        jsonResponse(400, 'Full name is required.');
    }
    
    $db = DB::connect();
    ensureSystemUpgradeSchema($db);
    
    if (!empty($newPassword)) {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET full_name = ?, password = ? WHERE user_id = ?');
        $stmt->execute([$fullName, $hashed, $_SESSION['user_id']]);
    } else {
        $stmt = $db->prepare('UPDATE users SET full_name = ? WHERE user_id = ?');
        $stmt->execute([$fullName, $_SESSION['user_id']]);
    }
    
    $_SESSION['name'] = $fullName;
    jsonResponse(200, 'Profile updated successfully.');
}

//  FALLBACK 
jsonResponse(400, 'Unknown action.');
