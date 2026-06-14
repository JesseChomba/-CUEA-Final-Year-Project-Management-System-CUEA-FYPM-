<?php
/**
 * CUEA FYPM – Student Supervisor Selection API
 */

require_once '../../includes/db.php';
require_once '../../includes/helpers.php';
require_once '../../includes/auth_check.php';

// Only students can access this
checkRole(['student']);

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize($_GET['action'] ?? '');
$db     = DB::connect();
$userId = $_SESSION['user_id'];

function ensureSupervisorChangeRequestsTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
          setting_key varchar(100) PRIMARY KEY,
          setting_value varchar(255) NOT NULL,
          updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $db->exec("
        INSERT IGNORE INTO system_settings (setting_key, setting_value)
        VALUES ('max_total_students_per_supervisor', '12')
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS supervisor_cohorts (
          supervisor_cohort_id int PRIMARY KEY AUTO_INCREMENT,
          supervisor_id int NOT NULL,
          cohort_id int NOT NULL,
          assigned_at timestamp DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_supervisor_cohort (supervisor_id, cohort_id),
          FOREIGN KEY (supervisor_id) REFERENCES users(user_id) ON DELETE CASCADE,
          FOREIGN KEY (cohort_id) REFERENCES cohorts(cohort_id) ON DELETE CASCADE
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS supervisor_change_requests (
          request_id int PRIMARY KEY AUTO_INCREMENT,
          project_id int NOT NULL,
          student_id int NOT NULL,
          current_supervisor_id int DEFAULT NULL,
          requested_supervisor_id int DEFAULT NULL,
          reason text NOT NULL,
          status ENUM ('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
          coordinator_comment text,
          created_at timestamp DEFAULT CURRENT_TIMESTAMP,
          resolved_at timestamp NULL DEFAULT NULL,
          FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
          FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
          FOREIGN KEY (current_supervisor_id) REFERENCES users(user_id) ON DELETE SET NULL,
          FOREIGN KEY (requested_supervisor_id) REFERENCES users(user_id) ON DELETE SET NULL
        )
    ");
    $db->exec("
        INSERT IGNORE INTO supervisor_cohorts (supervisor_id, cohort_id)
        SELECT DISTINCT supervisor_id, cohort_id
        FROM projects
        WHERE supervisor_id IS NOT NULL
    ");
}

function getSetting(PDO $db, string $key, string $default): string {
    $stmt = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    return $stmt->fetch()['setting_value'] ?? $default;
}

try {
    ensureSupervisorChangeRequestsTable($db);

    // 1. Get available supervisors with availability calculation
    if ($method === 'GET' && $action === 'get_available') {
        
        // Find the student's project and cohort
        $stmtProj = $db->prepare('
            SELECT p.project_id, p.cohort_id, c.max_students_per_supervisor
            FROM projects p
            JOIN cohorts c ON p.cohort_id = c.cohort_id
            WHERE p.student_id = ? LIMIT 1
        ');
        $stmtProj->execute([$userId]);
        $project = $stmtProj->fetch();

        if (!$project) {
            jsonResponse(404, 'No project/cohort found for this student.');
        }

        $cohortId = $project['cohort_id'];
        $maxCapacity = (int)getSetting($db, 'max_total_students_per_supervisor', (string)$project['max_students_per_supervisor']);

        // Get active supervisors associated with this cohort
        $stmtSup = $db->prepare("
            SELECT user_id, full_name, department 
            FROM users u
            JOIN supervisor_cohorts sc ON u.user_id = sc.supervisor_id
            WHERE u.role = 'supervisor' AND u.is_active = 1 AND sc.cohort_id = ?
            ORDER BY full_name ASC
        ");
        $stmtSup->execute([$cohortId]);
        $supervisors = $stmtSup->fetchAll();

        // Get allocation counts for this cohort
        $stmtAlloc = $db->prepare("
            SELECT supervisor_id, COUNT(project_id) as assigned_count
            FROM projects
            WHERE supervisor_id IS NOT NULL
            GROUP BY supervisor_id
        ");
        $stmtAlloc->execute();
        
        $allocMap = [];
        foreach ($stmtAlloc->fetchAll() as $row) {
            $allocMap[$row['supervisor_id']] = (int) $row['assigned_count'];
        }

        // Merge the data
        foreach ($supervisors as &$sup) {
            $assigned = $allocMap[$sup['user_id']] ?? 0;
            $sup['assigned'] = $assigned;
            $sup['capacity'] = $maxCapacity;
            $sup['is_full']  = ($assigned >= $maxCapacity);
            
            // Generate some pseudo-interests based on department for UI purposes
            // since research_interests aren't in our current DB schema
            $dept = strtolower($sup['department']);
            if (str_contains($dept, 'computer')) {
                $sup['interests'] = ['AI/ML', 'Software Eng'];
            } else if (str_contains($dept, 'info')) {
                $sup['interests'] = ['Networks', 'Cybersecurity'];
            } else {
                $sup['interests'] = ['General Research'];
            }
        }

        jsonResponse(200, 'Supervisors fetched', [
            'project_id' => $project['project_id'],
            'supervisors' => $supervisors
        ]);
    }

    // 2. Submit Preferences
    if ($method === 'POST' && $action === 'submit_preferences') {
        $body = json_decode(file_get_contents('php://input'), true);
        $projectId = sanitize($body['project_id'] ?? '');
        $preferences = $body['preferences'] ?? []; // Array of supervisor IDs ordered by rank

        if (!$projectId || empty($preferences) || count($preferences) < 3) {
            jsonResponse(400, 'Invalid request. Please select at least 3 preferences.');
        }

        $db->beginTransaction();

        // Clear existing preferences for this project
        $stmtClear = $db->prepare('DELETE FROM supervisor_preferences WHERE project_id = ?');
        $stmtClear->execute([$projectId]);

        // Insert new preferences
        $stmtInsert = $db->prepare('INSERT INTO supervisor_preferences (project_id, supervisor_id, priority_rank) VALUES (?, ?, ?)');
        
        $rank = 1;
        foreach ($preferences as $supId) {
            $stmtInsert->execute([$projectId, $supId, $rank]);
            $rank++;
        }

        $db->commit();
        jsonResponse(200, 'Preferences submitted successfully!');
    }

    // 3. Request a supervisor change after assignment
    if ($method === 'POST' && $action === 'request_change') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $requestedSupervisorId = !empty($body['requested_supervisor_id']) ? sanitize($body['requested_supervisor_id']) : null;
        $reason = sanitize($body['reason'] ?? '');

        if ($reason === '') {
            jsonResponse(400, 'Please provide a reason for the supervisor change request.');
        }

        $stmtProj = $db->prepare('
            SELECT project_id, supervisor_id
            FROM projects
            WHERE student_id = ?
            LIMIT 1
        ');
        $stmtProj->execute([$userId]);
        $project = $stmtProj->fetch();
        if (!$project || empty($project['supervisor_id'])) {
            jsonResponse(400, 'You can only request a new supervisor after an official supervisor has been assigned.');
        }

        if ($requestedSupervisorId && (int)$requestedSupervisorId === (int)$project['supervisor_id']) {
            jsonResponse(400, 'Requested supervisor must be different from your current supervisor.');
        }

        if ($requestedSupervisorId) {
            $stmtSup = $db->prepare('SELECT user_id FROM users WHERE user_id = ? AND role = "supervisor" AND is_active = 1');
            $stmtSup->execute([$requestedSupervisorId]);
            if (!$stmtSup->fetch()) {
                jsonResponse(404, 'Requested supervisor was not found.');
            }
        }

        $stmtPending = $db->prepare('
            SELECT request_id
            FROM supervisor_change_requests
            WHERE project_id = ? AND status = "pending"
            LIMIT 1
        ');
        $stmtPending->execute([$project['project_id']]);
        if ($stmtPending->fetch()) {
            jsonResponse(409, 'You already have a pending supervisor change request.');
        }

        $stmt = $db->prepare('
            INSERT INTO supervisor_change_requests
                (project_id, student_id, current_supervisor_id, requested_supervisor_id, reason)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $project['project_id'],
            $userId,
            $project['supervisor_id'],
            $requestedSupervisorId,
            $reason
        ]);

        $msg = "{$_SESSION['name']} has requested a supervisor change.";
        $coordStmt = $db->prepare('SELECT user_id FROM users WHERE role = "coordinator" AND is_active = 1');
        $coordStmt->execute();
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'general', ?)");
        foreach ($coordStmt->fetchAll() as $coord) {
            $notifStmt->execute([$coord['user_id'], $msg]);
        }

        jsonResponse(201, 'Supervisor change request submitted.');
    }

    // 4. Latest supervisor change request for the signed-in student
    if ($method === 'GET' && $action === 'change_request_status') {
        $stmt = $db->prepare('
            SELECT scr.*, rs.full_name AS requested_supervisor_name
            FROM supervisor_change_requests scr
            LEFT JOIN users rs ON scr.requested_supervisor_id = rs.user_id
            WHERE scr.student_id = ?
            ORDER BY scr.created_at DESC
            LIMIT 1
        ');
        $stmt->execute([$userId]);
        jsonResponse(200, 'Supervisor change request status fetched.', $stmt->fetch() ?: []);
    }

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(500, 'Database error: ' . $e->getMessage());
}

jsonResponse(400, 'Invalid action.');
