<?php
/**
 * CUEA FYPM – Auth Check Middleware
 *
 * Usage at the top of any protected endpoint:
 *   require_once '../../includes/auth_check.php';
 *   checkRole(['coordinator']);            // only coordinators
 *   checkRole(['student', 'supervisor']);  // multiple roles
 *   checkRole([]);                         // any authenticated user
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Abort with 401/403 JSON if the caller lacks the required role.
 * Pass an empty array to allow any authenticated user.
 *
 * @param string[] $allowedRoles
 */
function checkRole(array $allowedRoles): void {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 401,
            'message' => 'Your session has expired. Please log in again.',
            'data' => ['reason' => 'session_expired']
        ]);
        exit;
    }

    if (!empty($allowedRoles) && !in_array($_SESSION['role'], $allowedRoles, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 403,
            'message' => 'You are signed in as a different role. Please log in again.',
            'data' => ['reason' => 'role_changed']
        ]);
        exit;
    }
}
