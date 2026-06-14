<?php
/**
 * CUEA FYPM - Profile Management API
 */
require_once '../../includes/db.php';
require_once '../../includes/helpers.php';
require_once '../../includes/auth_check.php';

checkRole(['student', 'supervisor', 'coordinator']);

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize($_GET['action'] ?? '');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = DB::connect();
$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'];

try {
    if ($method === 'GET' && $action === 'get_profile') {
        $stmt = $db->prepare('
            SELECT user_id, full_name, email, role, university_id_number, department, created_at
            FROM users
            WHERE user_id = ? AND role IN ("student", "supervisor", "coordinator") AND is_active = 1
            LIMIT 1
        ');
        $stmt->execute([$userId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            jsonResponse(404, 'Profile not found.');
        }

        jsonResponse(200, 'Profile retrieved.', $profile);
    }

    if ($method === 'POST' && $action === 'update_profile') {
        $fullName = sanitize($body['full_name'] ?? '');
        $email = sanitize($body['email'] ?? '');
        $department = sanitize($body['department'] ?? '');
        $currentPassword = $body['current_password'] ?? '';
        $newPassword = $body['new_password'] ?? '';
        $confirmPassword = $body['confirm_password'] ?? '';

        if ($fullName === '' || $email === '') {
            jsonResponse(400, 'Full name and email are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(400, 'Please provide a valid email address.');
        }

        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                jsonResponse(400, 'New password must be at least 8 characters.');
            }

            if ($newPassword !== $confirmPassword) {
                jsonResponse(400, 'New password and confirmation do not match.');
            }

            if ($currentPassword === '') {
                jsonResponse(400, 'Current password is required to set a new password.');
            }
        }

        $stmt = $db->prepare('
            SELECT user_id, password
            FROM users
            WHERE user_id = ? AND role = ? AND is_active = 1
            LIMIT 1
        ');
        $stmt->execute([$userId, $role]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(404, 'Profile not found.');
        }

        if ($newPassword !== '' && !password_verify($currentPassword, $user['password'])) {
            jsonResponse(403, 'Current password is incorrect.');
        }

        $emailCheck = $db->prepare('SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
        $emailCheck->execute([$email, $userId]);
        if ($emailCheck->fetch()) {
            jsonResponse(409, 'That email address is already in use.');
        }

        if ($newPassword !== '') {
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare('
                UPDATE users
                SET full_name = ?, email = ?, department = ?, password = ?
                WHERE user_id = ? AND role = ?
            ');
            $stmt->execute([$fullName, $email, $department, $passwordHash, $userId, $role]);
        } else {
            $stmt = $db->prepare('
                UPDATE users
                SET full_name = ?, email = ?, department = ?
                WHERE user_id = ? AND role = ?
            ');
            $stmt->execute([$fullName, $email, $department, $userId, $role]);
        }

        $_SESSION['name'] = $fullName;
        $_SESSION['email'] = $email;

        jsonResponse(200, 'Profile updated successfully.');
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        jsonResponse(409, 'That email address is already in use.');
    }

    jsonResponse(500, 'Database error: ' . $e->getMessage());
}

jsonResponse(400, 'Invalid action.');
