<?php
/**
 * CUEA FYPM – Notifications API
 */

require_once '../../includes/db.php';
require_once '../../includes/helpers.php';

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

if (empty($_SESSION['user_id'])) {
    jsonResponse(401, 'Unauthenticated');
}

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize($_GET['action'] ?? '');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

$db = DB::connect();
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];

//  LAZY OVERDUE CHECK 
if ($method === 'POST' && $action === 'lazy_overdue_check') {
    // Check if there are overdue milestones for projects involving this user
    
    // We only trigger this logic once per session to avoid spamming the DB
    if (isset($_SESSION['overdue_checked'])) {
        jsonResponse(200, 'Already checked.');
    }
    $_SESSION['overdue_checked'] = true;
    
    $today = date('Y-m-d');
    
    if ($role === 'student') {
        // Find student project
        $stmt = $db->prepare('SELECT project_id, cohort_id FROM projects WHERE student_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $proj = $stmt->fetch();
        if ($proj) {
            // Find overdue milestones with no submission
            $sql = "SELECT m.milestone_id, m.name 
                    FROM milestones m 
                    LEFT JOIN milestone_submissions ms ON m.milestone_id = ms.milestone_id AND ms.project_id = ?
                    WHERE m.cohort_id = ? AND m.due_date < ? AND ms.sub_id IS NULL";
            $mStmt = $db->prepare($sql);
            $mStmt->execute([$proj['project_id'], $proj['cohort_id'], $today]);
            $overdue = $mStmt->fetchAll();
            
            foreach($overdue as $m) {
                // Insert notification if not exists
                $msg = "OVERDUE: The milestone '{$m['name']}' is past due.";
                $checkStmt = $db->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND message = ?");
                $checkStmt->execute([$userId, $msg]);
                if (!$checkStmt->fetch()) {
                    $ins = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'reminder', ?)");
                    $ins->execute([$userId, $msg]);
                }
            }
        }
    }
    
    jsonResponse(200, 'Overdue check complete.');
}

jsonResponse(400, 'Unknown action.');
