<?php
/**
 * CUEA FYPM – Supervisor API
 */
require_once '../../includes/db.php';
require_once '../../includes/helpers.php';
require_once '../../includes/auth_check.php';
require_once '../../includes/schema.php';
require_once '../../includes/audit.php';
require_once '../../includes/mailer.php';

// Only supervisors
checkRole(['supervisor']);

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize($_GET['action'] ?? '');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = DB::connect();
$supId  = $_SESSION['user_id'];

function ensureSupervisorPhase34Schema(PDO $db): void {
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
        CREATE TABLE IF NOT EXISTS submission_comments (
          comment_id int PRIMARY KEY AUTO_INCREMENT,
          sub_id int NOT NULL,
          author_id int NOT NULL,
          body text NOT NULL,
          created_at timestamp DEFAULT CURRENT_TIMESTAMP,
          updated_at timestamp NULL DEFAULT NULL,
          FOREIGN KEY (sub_id) REFERENCES milestone_submissions(sub_id) ON DELETE CASCADE,
          FOREIGN KEY (author_id) REFERENCES users(user_id) ON DELETE CASCADE
        )
    ");
    $db->exec("
        INSERT IGNORE INTO supervisor_cohorts (supervisor_id, cohort_id)
        SELECT DISTINCT supervisor_id, cohort_id
        FROM projects
        WHERE supervisor_id IS NOT NULL
    ");
}

function supervisorHasCohort(PDO $db, int $supervisorId, int $cohortId): bool {
    $stmt = $db->prepare('SELECT 1 FROM supervisor_cohorts WHERE supervisor_id = ? AND cohort_id = ? LIMIT 1');
    $stmt->execute([$supervisorId, $cohortId]);
    return (bool)$stmt->fetch();
}

function resequenceMilestones(PDO $db, int $cohortId): void {
    $stmt = $db->prepare('
        SELECT milestone_id
        FROM milestones
        WHERE cohort_id = ?
        ORDER BY due_date ASC, milestone_id ASC
    ');
    $stmt->execute([$cohortId]);
    $update = $db->prepare('UPDATE milestones SET sequence_order = ? WHERE milestone_id = ?');
    $order = 1;
    foreach ($stmt->fetchAll() as $row) {
        $update->execute([$order++, $row['milestone_id']]);
    }
}

ensureSupervisorPhase34Schema($db);
ensureSystemUpgradeSchema($db);

function saveSupervisorInstructionUpload(string $fieldName): ?string {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'doc', 'docx'], true)) {
        jsonResponse(400, 'Instruction file must be PDF, DOC, or DOCX.');
    }
    $dir = '../../uploads/instructions/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES[$fieldName]['name']));
    $file = 'instruction_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dir . $file)) {
        jsonResponse(500, 'Failed to save instruction file.');
    }
    return 'uploads/instructions/' . $file;
}

try {
    // 1. Get My Supervisees
    if ($method === 'GET' && $action === 'get_supervisees') {
        $cohortFilter = sanitize($_GET['cohort_id'] ?? '');
        $sql = "
            SELECT u.user_id, u.full_name, u.email, u.university_id_number,
                   p.project_id, p.title, p.status as project_status, p.cohort_id,
                   c.name as cohort_name
            FROM users u
            JOIN projects p ON u.user_id = p.student_id
            JOIN cohorts c ON p.cohort_id = c.cohort_id
            WHERE p.supervisor_id = ?
        ";
        $params = [$supId];
        if ($cohortFilter) {
            $sql .= ' AND p.cohort_id = ?';
            $params[] = $cohortFilter;
        }
        $sql .= ' ORDER BY c.start_date DESC, u.full_name ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $supervisees = $stmt->fetchAll();

        // Find the defense milestone_id for a given cohort (helper closure)
        $defenseCache = [];
        $getDefenseMilestoneId = function(int $cohortId) use ($db, &$defenseCache): ?int {
            if (!isset($defenseCache[$cohortId])) {
                $dStmt = $db->prepare("SELECT milestone_id FROM milestones WHERE cohort_id = ? AND type = 'defense' LIMIT 1");
                $dStmt->execute([$cohortId]);
                $row = $dStmt->fetch();
                $defenseCache[$cohortId] = $row ? (int)$row['milestone_id'] : null;
            }
            return $defenseCache[$cohortId];
        };

        foreach ($supervisees as &$s) {
            $cohortId = (int)($s['cohort_id'] ?? 0);
            $projectId = (int)$s['project_id'];

            // Overall progress
            $mStmt = $db->prepare("SELECT COUNT(*) as total FROM milestones WHERE cohort_id = ?");
            $mStmt->execute([$cohortId]);
            $total = $mStmt->fetch()['total'] ?? 0;

            $sStmt = $db->prepare("SELECT COUNT(DISTINCT milestone_id) as completed FROM milestone_submissions WHERE project_id = ? AND status IN ('supervisor_approved', 'coordinator_approved', 'satisfactory', 'satisfactory_with_corrections')");
            $sStmt->execute([$projectId]);
            $completed = $sStmt->fetch()['completed'] ?? 0;

            $s['progress'] = $total > 0 ? round(($completed / $total) * 100) : 0;

            // is_cleared_for_defense: already has a defense submission
            $defenseMilestoneId = $getDefenseMilestoneId($cohortId);
            if ($defenseMilestoneId) {
                $exStmt = $db->prepare('SELECT 1 FROM milestone_submissions WHERE project_id = ? AND milestone_id = ? LIMIT 1');
                $exStmt->execute([$projectId, $defenseMilestoneId]);
                $s['is_cleared_for_defense'] = (bool)$exStmt->fetch();
            } else {
                $s['is_cleared_for_defense'] = false;
            }

            // can_clear_for_defense: all non-defense milestones owned by coordinator OR
            // this supervisor must have an approved submission.
            // Check: any non-defense milestone (cohort-level or this supervisor's sub-milestones)
            // that does NOT have an approved submission.
            $incompleteStmt = $db->prepare("
                SELECT COUNT(*) as cnt
                FROM milestones m
                WHERE m.cohort_id = ?
                  AND m.type != 'defense'
                  AND (
                      m.supervisor_id IS NULL         -- coordinator-level milestones
                      OR m.supervisor_id = ?          -- this supervisor's sub-milestones
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM milestone_submissions ms
                      WHERE ms.milestone_id = m.milestone_id
                        AND ms.project_id   = ?
                        AND ms.status IN ('supervisor_approved','coordinator_approved',
                                          'satisfactory','satisfactory_with_corrections',
                                          'cleared_for_defense')
                  )
            ");
            $incompleteStmt->execute([$cohortId, (int)$supId, $projectId]);
            $incompleteCount = (int)($incompleteStmt->fetch()['cnt'] ?? 0);
            $s['can_clear_for_defense'] = ($incompleteCount === 0);
        }

        jsonResponse(200, 'Supervisees fetched', $supervisees);
    }

    // 2. Get Submissions for a Project
    if ($method === 'GET' && $action === 'get_submissions') {
        $projectId = sanitize($_GET['project_id'] ?? '');
        $cohortId = sanitize($_GET['cohort_id'] ?? '');
        if (!$projectId) jsonResponse(400, 'project_id required');
        
        // Ensure this project belongs to this supervisor
        $chkSql = "SELECT project_id FROM projects WHERE project_id = ? AND supervisor_id = ?";
        $chkParams = [$projectId, $supId];
        if ($cohortId) {
            $chkSql .= " AND cohort_id = ?";
            $chkParams[] = $cohortId;
        }
        $chk = $db->prepare($chkSql);
        $chk->execute($chkParams);
        if (!$chk->fetch()) jsonResponse(403, 'Unauthorized access to this project.');

        $stmt = $db->prepare("
            SELECT s.sub_id, s.file_path, s.student_text, s.version, s.submitted_at, s.status, s.supervisor_feedback, s.coordinator_feedback,
                   m.name as milestone_name, m.due_date, m.type
            FROM milestone_submissions s
            JOIN milestones m ON s.milestone_id = m.milestone_id
            WHERE s.project_id = ?
            ORDER BY m.due_date DESC, s.version DESC
        ");
        $stmt->execute([$projectId]);
        jsonResponse(200, 'Submissions fetched', $stmt->fetchAll());
    }

    if ($method === 'GET' && $action === 'get_activity_logs') {
        $stmt = $db->prepare('
            SELECT al.*, u.full_name
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            WHERE al.user_id = ?
               OR al.action IN ("submit_milestone", "grade_submission", "clear_for_defense", "create_sub_milestone")
            ORDER BY al.timestamp DESC
            LIMIT 20
        ');
        $stmt->execute([$supId]);
        jsonResponse(200, 'Activity logs fetched.', $stmt->fetchAll());
    }

    // 3. Grade Submission (Inject Feedback & Update Status)
    if ($method === 'POST' && $action === 'grade_submission') {
        $subId    = sanitize($body['sub_id'] ?? '');
        $status   = sanitize($body['status'] ?? '');
        $feedback = sanitize($body['supervisor_feedback'] ?? '');

        if (!$subId || !$status) jsonResponse(400, 'sub_id and status are required.');

        // Verify supervisor owns the project this submission belongs to
        $chk = $db->prepare("
            SELECT s.sub_id 
            FROM milestone_submissions s
            JOIN projects p ON s.project_id = p.project_id
            WHERE s.sub_id = ? AND p.supervisor_id = ?
        ");
        $chk->execute([$subId, $supId]);
        if (!$chk->fetch()) jsonResponse(403, 'Unauthorized access to this submission.');

        $stmtType = $db->prepare('
            SELECT m.type, p.student_id, p.project_id
            FROM milestone_submissions s
            JOIN milestones m ON s.milestone_id = m.milestone_id
            JOIN projects p ON s.project_id = p.project_id
            WHERE s.sub_id = ?
        ');
        $stmtType->execute([$subId]);
        $submissionMeta = $stmtType->fetch();
        $isDefense = (($submissionMeta['type'] ?? '') === 'defense');
        $defenseStatuses = ['cleared_for_defense', 'satisfactory', 'satisfactory_with_corrections', 'fail_redo'];
        $standardStatuses = ['submitted', 'revision_required', 'supervisor_approved', 'rejected'];
        if ($isDefense && !in_array($status, $defenseStatuses, true)) {
            jsonResponse(400, 'Defense submissions can only use Defense grading statuses.');
        }
        if (!$isDefense && !in_array($status, $standardStatuses, true)) {
            jsonResponse(400, 'Defense grading statuses are not available for standard milestones.');
        }

        $db->beginTransaction();
        $stmt = $db->prepare("
            UPDATE milestone_submissions 
            SET status = ?, supervisor_feedback = ?
            WHERE sub_id = ?
        ");
        $stmt->execute([$status, $feedback, $subId]);

        if ($isDefense && $status === 'satisfactory') {
            $stmtComplete = $db->prepare('
                UPDATE projects p
                JOIN users u ON p.student_id = u.user_id
                SET p.status = "completed", u.is_active = 0
                WHERE p.project_id = ?
            ');
            $stmtComplete->execute([$submissionMeta['project_id']]);
        }

        // Get student ID and milestone name to notify
        $nStmt = $db->prepare("
            SELECT p.student_id, m.name, u.full_name, u.email
            FROM milestone_submissions s
            JOIN projects p ON s.project_id = p.project_id
            JOIN milestones m ON s.milestone_id = m.milestone_id
            JOIN users u ON p.student_id = u.user_id
            WHERE s.sub_id = ?
        ");
        $nStmt->execute([$subId]);
        $subInfo = $nStmt->fetch();

        if ($subInfo) {
            $msg = "Your supervisor has reviewed your submission for '{$subInfo['name']}' and updated its status to '" . str_replace('_', ' ', $status) . "'.";
            $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'status_change', ?)");
            $notifStmt->execute([$subInfo['student_id'], $msg]);
            if (!empty($subInfo['email'])) {
                sendSystemEmail($subInfo['email'], $subInfo['full_name'], 'CUEA FYPM Feedback Alert', $msg);
            }
        }

        auditLog((int)$supId, 'grade_submission', 'submission', (int)$subId, $status);
        $db->commit();
        jsonResponse(200, 'Feedback saved and status updated.');
    }

    if ($method === 'POST' && $action === 'clear_for_defense') {
        $projectId = sanitize($body['project_id'] ?? '');
        if (!$projectId) jsonResponse(400, 'project_id required.');

        $stmtProject = $db->prepare('SELECT project_id, student_id, cohort_id FROM projects WHERE project_id = ? AND supervisor_id = ? LIMIT 1');
        $stmtProject->execute([$projectId, $supId]);
        $project = $stmtProject->fetch();
        if (!$project) jsonResponse(403, 'Unauthorized project.');

        $stmtDefense = $db->prepare('
            SELECT m.milestone_id
            FROM milestones m
            LEFT JOIN project_defense_assignments pda ON pda.defense_milestone_id = m.milestone_id AND pda.project_id = ?
            WHERE m.type = "defense"
              AND (m.cohort_id = ? OR pda.project_id IS NOT NULL)
            ORDER BY pda.assignment_id DESC, m.due_date ASC
            LIMIT 1
        ');
        $stmtDefense->execute([$projectId, $project['cohort_id']]);
        $defense = $stmtDefense->fetch();
        if (!$defense) jsonResponse(404, 'No Defense milestone has been configured for this cohort.');

        // Guard: already cleared
        $stmtExisting = $db->prepare('SELECT sub_id FROM milestone_submissions WHERE project_id = ? AND milestone_id = ? LIMIT 1');
        $stmtExisting->execute([$projectId, $defense['milestone_id']]);
        if ($stmtExisting->fetch()) {
            jsonResponse(409, 'This student has already been cleared for Defense.');
        }

        // Guard: pre-flight — all non-defense milestones (coordinator-level + this supervisor's
        // sub-milestones) must have an approved submission. Sub-milestones from OTHER supervisors
        // are intentionally excluded (they are considered orphaned after transfer).
        $incompleteStmt = $db->prepare("
            SELECT m.name
            FROM milestones m
            WHERE m.cohort_id = ?
              AND m.type != 'defense'
              AND (
                  m.supervisor_id IS NULL
                  OR m.supervisor_id = ?
              )
              AND NOT EXISTS (
                  SELECT 1 FROM milestone_submissions ms
                  WHERE ms.milestone_id = m.milestone_id
                    AND ms.project_id   = ?
                    AND ms.status IN ('supervisor_approved','coordinator_approved',
                                      'satisfactory','satisfactory_with_corrections',
                                      'cleared_for_defense')
              )
        ");
        $incompleteStmt->execute([$project['cohort_id'], (int)$supId, (int)$projectId]);
        $incomplete = $incompleteStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($incomplete)) {
            $names = implode(', ', array_map('htmlspecialchars', $incomplete));
            jsonResponse(409, 'Cannot clear for defense: the following milestones are not yet completed: ' . $names);
        }

        $stmt = $db->prepare('
            INSERT INTO milestone_submissions (milestone_id, project_id, student_text, version, status)
            VALUES (?, ?, "Cleared for Defense by supervisor.", 1, "cleared_for_defense")
        ');
        $stmt->execute([$defense['milestone_id'], $projectId]);
        $notif = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'status_change', ?)");
        $notif->execute([$project['student_id'], 'You have been cleared for Project Defense.']);
        auditLog((int)$supId, 'clear_for_defense', 'project', (int)$projectId);
        jsonResponse(201, 'Student cleared for Defense.');
    }

    // 4. Get Supervisor Cohorts
    if ($method === 'GET' && $action === 'get_cohorts') {
        // Fetch cohorts explicitly associated with this supervisor
        $stmt = $db->prepare("
            SELECT c.cohort_id, c.name
            FROM supervisor_cohorts sc
            JOIN cohorts c ON sc.cohort_id = c.cohort_id
            WHERE sc.supervisor_id = ? AND c.is_active = 1
            ORDER BY c.start_date DESC
        ");
        $stmt->execute([$supId]);
        jsonResponse(200, 'Cohorts fetched', $stmt->fetchAll());
    }

    if ($method === 'GET' && $action === 'get_dashboard_summary') {
        $cohortId = sanitize($_GET['cohort_id'] ?? '');
        $params = [$supId];
        $cohortWhere = '';
        if ($cohortId) {
            $cohortWhere = ' AND p.cohort_id = ?';
            $params[] = $cohortId;
        }

        $pending = $db->prepare("
            SELECT COUNT(*) AS c
            FROM milestone_submissions s
            JOIN projects p ON s.project_id = p.project_id
            WHERE p.supervisor_id = ?
              AND s.status = 'submitted'
              {$cohortWhere}
        ");
        $pending->execute($params);

        $recent = $db->prepare("
            SELECT s.sub_id, s.file_path, s.submitted_at, s.status,
                   m.name AS milestone_name, u.full_name AS student_name, c.name AS cohort_name
            FROM milestone_submissions s
            JOIN milestones m ON s.milestone_id = m.milestone_id
            JOIN projects p ON s.project_id = p.project_id
            JOIN users u ON p.student_id = u.user_id
            JOIN cohorts c ON p.cohort_id = c.cohort_id
            WHERE p.supervisor_id = ?
              AND s.file_path IS NOT NULL
              {$cohortWhere}
            ORDER BY s.submitted_at DESC
            LIMIT 6
        ");
        $recent->execute($params);

        $metrics = $db->prepare("
            SELECT c.cohort_id, c.name,
                   COUNT(DISTINCT p.project_id) AS active_projects,
                   SUM(CASE WHEN ms.status = 'submitted' THEN 1 ELSE 0 END) AS pending_submissions
            FROM supervisor_cohorts sc
            JOIN cohorts c ON sc.cohort_id = c.cohort_id
            LEFT JOIN projects p ON p.cohort_id = c.cohort_id AND p.supervisor_id = sc.supervisor_id
            LEFT JOIN milestone_submissions ms ON ms.project_id = p.project_id
            WHERE sc.supervisor_id = ?
            GROUP BY c.cohort_id, c.name
            ORDER BY c.name ASC
        ");
        $metrics->execute([$supId]);

        jsonResponse(200, 'Dashboard summary fetched.', [
            'pending_actions' => (int)($pending->fetch()['c'] ?? 0),
            'recent_files' => $recent->fetchAll(),
            'cohort_metrics' => $metrics->fetchAll()
        ]);
    }

    // 5. Get Parent Milestones & Sub Milestones
    if ($method === 'GET' && $action === 'get_parent_milestones') {
        $cohort_id = sanitize($_GET['cohort_id'] ?? '');
        if (!$cohort_id) jsonResponse(400, 'cohort_id required');
        if (!supervisorHasCohort($db, (int)$supId, (int)$cohort_id)) {
            jsonResponse(403, 'You are not associated with this cohort.');
        }
        
        $stmt = $db->prepare("SELECT milestone_id, parent_milestone_id, name, description, due_date, type FROM milestones WHERE cohort_id = ? AND supervisor_id IS NULL");
        $stmt->execute([$cohort_id]);
        $coord = $stmt->fetchAll();

        $stmt2 = $db->prepare("SELECT milestone_id, parent_milestone_id, name, description, due_date, type FROM milestones WHERE cohort_id = ? AND type = 'sub_milestone' AND supervisor_id = ?");
        $stmt2->execute([$cohort_id, $supId]);
        $sup = $stmt2->fetchAll();

        jsonResponse(200, 'Milestones fetched', array_merge($coord, $sup));
    }

    // 6. Create Sub-Milestone
    if ($method === 'POST' && $action === 'create_sub_milestone') {
        $cohortId = sanitize($body['cohort_id'] ?? '');
        $name     = sanitize($body['name'] ?? '');
        $due      = sanitize($body['due_date'] ?? '');
        $desc     = sanitize($body['description'] ?? '');
        $parent   = !empty($body['parent_id']) ? sanitize($body['parent_id']) : null;

        if (!$cohortId || !$name || !$due) {
            jsonResponse(400, 'Missing required fields.');
        }
        if (!supervisorHasCohort($db, (int)$supId, (int)$cohortId)) {
            jsonResponse(403, 'You are not associated with this cohort.');
        }

        $stmt = $db->prepare('INSERT INTO milestones (cohort_id, parent_milestone_id, supervisor_id, name, description, type, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$cohortId, $parent, $supId, $name, $desc, 'sub_milestone', $due, $supId]);
        resequenceMilestones($db, (int)$cohortId);
        $message = "A new sub-milestone '{$name}' is due on {$due}.";
        $students = $db->prepare('
            SELECT u.full_name, u.email
            FROM projects p
            JOIN users u ON p.student_id = u.user_id
            WHERE p.supervisor_id = ? AND p.cohort_id = ?
        ');
        $students->execute([$supId, $cohortId]);
        foreach ($students->fetchAll() as $student) {
            if (!empty($student['email'])) {
                sendSystemEmail($student['email'], $student['full_name'], 'New CUEA FYPM Sub-Milestone', $message);
            }
        }
        auditLog((int)$supId, 'create_sub_milestone', 'milestone', (int)$db->lastInsertId(), $name);
        jsonResponse(201, 'Sub-Milestone created successfully.');
    }

    if ($method === 'POST' && $action === 'upload_sub_milestone_instruction') {
        $milestoneId = sanitize($_POST['milestone_id'] ?? '');
        if (!$milestoneId) jsonResponse(400, 'milestone_id required.');
        $path = saveSupervisorInstructionUpload('instruction_file');
        if (!$path) jsonResponse(400, 'Instruction file required.');
        $stmt = $db->prepare('UPDATE milestones SET instruction_file_path = ? WHERE milestone_id = ? AND supervisor_id = ?');
        $stmt->execute([$path, $milestoneId, $supId]);
        auditLog((int)$supId, 'upload_sub_milestone_instruction', 'milestone', (int)$milestoneId, $path);
        jsonResponse(200, 'Instruction file uploaded.', ['path' => $path]);
    }

    if ($method === 'POST' && $action === 'update_sub_milestone') {
        $milestoneId = sanitize($body['milestone_id'] ?? '');
        $cohortId = sanitize($body['cohort_id'] ?? '');
        $name = sanitize($body['name'] ?? '');
        $due = sanitize($body['due_date'] ?? '');
        $desc = sanitize($body['description'] ?? '');
        $parent = !empty($body['parent_id']) ? sanitize($body['parent_id']) : null;

        if (!$milestoneId || !$cohortId || !$name || !$due) {
            jsonResponse(400, 'Missing required fields.');
        }
        if (!supervisorHasCohort($db, (int)$supId, (int)$cohortId)) {
            jsonResponse(403, 'You are not associated with this cohort.');
        }

        $stmt = $db->prepare('
            UPDATE milestones
            SET cohort_id = ?, parent_milestone_id = ?, name = ?, description = ?, due_date = ?
            WHERE milestone_id = ? AND supervisor_id = ? AND type = "sub_milestone"
        ');
        $stmt->execute([$cohortId, $parent, $name, $desc, $due, $milestoneId, $supId]);
        resequenceMilestones($db, (int)$cohortId);
        jsonResponse(200, 'Sub-milestone updated successfully.');
    }

    if ($method === 'POST' && $action === 'delete_sub_milestone') {
        $milestoneId = sanitize($body['milestone_id'] ?? '');
        if (!$milestoneId) jsonResponse(400, 'milestone_id required.');

        $stmtCheck = $db->prepare('SELECT COUNT(*) AS submission_count FROM milestone_submissions WHERE milestone_id = ?');
        $stmtCheck->execute([$milestoneId]);
        if ((int)($stmtCheck->fetch()['submission_count'] ?? 0) > 0) {
            jsonResponse(409, 'Cannot delete a sub-milestone that already has submissions.');
        }

        $stmtMeta = $db->prepare('SELECT cohort_id FROM milestones WHERE milestone_id = ? AND supervisor_id = ? AND type = "sub_milestone" LIMIT 1');
        $stmtMeta->execute([$milestoneId, $supId]);
        $meta = $stmtMeta->fetch();
        if (!$meta) jsonResponse(404, 'Sub-milestone not found.');

        $stmt = $db->prepare('DELETE FROM milestones WHERE milestone_id = ? AND supervisor_id = ? AND type = "sub_milestone"');
        $stmt->execute([$milestoneId, $supId]);
        resequenceMilestones($db, (int)$meta['cohort_id']);
        jsonResponse(200, 'Sub-milestone deleted successfully.');
    }

    if ($method === 'GET' && $action === 'get_comments') {
        $subId = sanitize($_GET['sub_id'] ?? '');
        if (!$subId) jsonResponse(400, 'sub_id required.');

        $chk = $db->prepare("
            SELECT s.sub_id
            FROM milestone_submissions s
            JOIN projects p ON s.project_id = p.project_id
            WHERE s.sub_id = ? AND p.supervisor_id = ?
        ");
        $chk->execute([$subId, $supId]);
        if (!$chk->fetch()) jsonResponse(403, 'Unauthorized access to this submission.');

        $stmt = $db->prepare('
            SELECT sc.*, u.full_name AS author_name
            FROM submission_comments sc
            JOIN users u ON sc.author_id = u.user_id
            WHERE sc.sub_id = ?
            ORDER BY sc.created_at ASC
        ');
        $stmt->execute([$subId]);
        jsonResponse(200, 'Comments fetched.', $stmt->fetchAll());
    }

    if ($method === 'POST' && $action === 'add_comment') {
        $subId = sanitize($body['sub_id'] ?? '');
        $comment = sanitize($body['body'] ?? '');
        if (!$subId || !$comment) jsonResponse(400, 'Submission and comment are required.');

        $chk = $db->prepare("
            SELECT s.sub_id, p.student_id, m.name
            FROM milestone_submissions s
            JOIN projects p ON s.project_id = p.project_id
            JOIN milestones m ON s.milestone_id = m.milestone_id
            WHERE s.sub_id = ? AND p.supervisor_id = ?
        ");
        $chk->execute([$subId, $supId]);
        $sub = $chk->fetch();
        if (!$sub) jsonResponse(403, 'Unauthorized access to this submission.');

        $stmt = $db->prepare('INSERT INTO submission_comments (sub_id, author_id, body) VALUES (?, ?, ?)');
        $stmt->execute([$subId, $supId, $comment]);

        $msg = "Your supervisor added a comment on '{$sub['name']}'.";
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'comment', ?)");
        $notifStmt->execute([$sub['student_id'], $msg]);

        jsonResponse(201, 'Comment added successfully.');
    }

    if ($method === 'POST' && $action === 'update_comment') {
        $commentId = sanitize($body['comment_id'] ?? '');
        $comment = sanitize($body['body'] ?? '');
        if (!$commentId || !$comment) jsonResponse(400, 'Comment ID and body are required.');

        $stmt = $db->prepare('
            UPDATE submission_comments
            SET body = ?, updated_at = NOW()
            WHERE comment_id = ? AND author_id = ?
        ');
        $stmt->execute([$comment, $commentId, $supId]);
        jsonResponse(200, 'Comment updated successfully.');
    }

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(500, 'Database error: ' . $e->getMessage());
}

jsonResponse(400, 'Invalid action.');
