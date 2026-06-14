<?php
/**
 * Server-side page guard for protected PHP views.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function redirectToLogin(string $reason = 'session_expired'): void {
    header('Location: index.html?reason=' . rawurlencode($reason));
    exit;
}

function requirePageRole(array $allowedRoles): array {
    if (empty($_SESSION['user_id'])) {
        redirectToLogin('session_expired');
    }

    $db = DB::connect();
    $stmt = $db->prepare('
        SELECT user_id, full_name, email, role, university_id_number, department
        FROM users
        WHERE user_id = ? AND is_active = 1
        LIMIT 1
    ');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        redirectToLogin('session_invalid');
    }

    if (!empty($allowedRoles) && !in_array($user['role'], $allowedRoles, true)) {
        redirectToLogin('role_changed');
    }

    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];

    return $user;
}

function studentSupervisorState(int $studentId): array {
    $db = DB::connect();
    $stmt = $db->prepare('
        SELECT p.project_id, p.supervisor_id,
               COUNT(sp.pref_id) AS preference_count
        FROM projects p
        LEFT JOIN supervisor_preferences sp ON p.project_id = sp.project_id
        WHERE p.student_id = ?
        GROUP BY p.project_id, p.supervisor_id
        LIMIT 1
    ');
    $stmt->execute([$studentId]);
    $state = $stmt->fetch();

    if (!$state) {
        return ['has_project' => false, 'has_supervisor' => false, 'has_pending_preferences' => false];
    }

    return [
        'has_project' => true,
        'has_supervisor' => !empty($state['supervisor_id']),
        'has_pending_preferences' => ((int)($state['preference_count'] ?? 0) > 0),
    ];
}

function requireStudentSupervisorAssigned(array $user): void {
    if (($user['role'] ?? '') !== 'student') {
        return;
    }

    $state = studentSupervisorState((int)$user['user_id']);
    if (!$state['has_supervisor']) {
        header('Location: ' . ($state['has_pending_preferences'] ? 'pending-supervisor.php' : 'supervisor-selection.html'));
        exit;
    }
}
