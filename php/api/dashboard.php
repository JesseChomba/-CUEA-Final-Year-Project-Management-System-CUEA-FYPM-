<?php
/**
 * CUEA FYPM – Student Dashboard API
 */

require_once '../../includes/db.php';
require_once '../../includes/helpers.php';
require_once '../../includes/auth_check.php';
require_once '../../includes/schema.php';
require_once '../../includes/audit.php';

// Only students can access this
checkRole(['student']);

$userId = $_SESSION['user_id'];
$db     = DB::connect();
$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize($_GET['action'] ?? '');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

ensureSystemUpgradeSchema($db);

function projectColumnExists(PDO $db, string $column): bool {
    $stmt = $db->prepare('
        SELECT COUNT(*) AS found
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = "projects"
          AND COLUMN_NAME = ?
    ');
    $stmt->execute([$column]);
    return (int)($stmt->fetch()['found'] ?? 0) > 0;
}

function ensureProjectAbstractColumn(PDO $db): void {
    if (!projectColumnExists($db, 'abstract')) {
        $db->exec('ALTER TABLE projects ADD COLUMN abstract text NULL AFTER title');
    }
}

function assignNextDefenseIfMissed(PDO $db, array $project): ?array {
    $projectId = (int)$project['project_id'];
    $cohortId = (int)$project['cohort_id'];

    $done = $db->prepare('
        SELECT 1
        FROM milestone_submissions s
        JOIN milestones m ON s.milestone_id = m.milestone_id
        WHERE s.project_id = ? AND m.type = "defense" AND s.status = "satisfactory"
        LIMIT 1
    ');
    $done->execute([$projectId]);
    if ($done->fetch()) {
        return null;
    }

    $deadlineStmt = $db->prepare('
        SELECT defense_deadline
        FROM cohort_defense_settings
        WHERE cohort_id = ?
        LIMIT 1
    ');
    $deadlineStmt->execute([$cohortId]);
    $deadline = $deadlineStmt->fetch();
    if (!$deadline || $deadline['defense_deadline'] >= date('Y-m-d')) {
        return null;
    }

    $existing = $db->prepare('
        SELECT pda.*, c.name AS assigned_cohort_name, m.due_date
        FROM project_defense_assignments pda
        JOIN cohorts c ON pda.assigned_cohort_id = c.cohort_id
        JOIN milestones m ON pda.defense_milestone_id = m.milestone_id
        WHERE pda.project_id = ?
        ORDER BY pda.created_at DESC
        LIMIT 1
    ');
    $existing->execute([$projectId]);
    $row = $existing->fetch();
    if ($row) {
        return $row;
    }

    $next = $db->prepare('
        SELECT cds.cohort_id, c.name AS cohort_name, cds.defense_milestone_id, cds.defense_deadline, m.due_date
        FROM cohort_defense_settings cds
        JOIN cohorts c ON cds.cohort_id = c.cohort_id
        JOIN milestones m ON cds.defense_milestone_id = m.milestone_id
        WHERE cds.cohort_id <> ?
          AND cds.defense_deadline >= CURDATE()
        ORDER BY cds.defense_deadline ASC
        LIMIT 1
    ');
    $next->execute([$cohortId]);
    $target = $next->fetch();
    if (!$target) {
        return [
            'missed' => true,
            'message' => 'Defense deadline missed. No later defense cohort has been configured yet.'
        ];
    }

    $insert = $db->prepare('
        INSERT INTO project_defense_assignments
            (project_id, original_cohort_id, assigned_cohort_id, defense_milestone_id, reason)
        VALUES (?, ?, ?, ?, "missed_deadline")
    ');
    $insert->execute([$projectId, $cohortId, $target['cohort_id'], $target['defense_milestone_id']]);

    $msg = 'Your original Defense deadline was missed. You have been assigned to the '
         . $target['cohort_name'] . ' Defense cycle.';
    $notif = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'status_change', ?)");
    $notif->execute([$project['student_id'], $msg]);
    auditLog(null, 'auto_assign_next_defense', 'project', $projectId, $msg);

    return [
        'missed' => true,
        'assigned_cohort_id' => $target['cohort_id'],
        'assigned_cohort_name' => $target['cohort_name'],
        'due_date' => $target['due_date'],
        'message' => $msg
    ];
}

try {
    if ($method === 'POST' && $action === 'update_project_details') {
        ensureProjectAbstractColumn($db);
        $title = sanitize($body['title'] ?? '');
        $abstract = sanitize($body['abstract'] ?? '');

        if ($title === '') {
            jsonResponse(400, 'Project title is required.');
        }

        $stmt = $db->prepare('
            SELECT project_id, status
            FROM projects
            WHERE student_id = ?
            LIMIT 1
        ');
        $stmt->execute([$userId]);
        $project = $stmt->fetch();

        if (!$project) {
            jsonResponse(404, 'No project found for this student.');
        }

        $editableStatuses = ['draft', 'revision_required'];
        if (!in_array($project['status'], $editableStatuses, true)) {
            jsonResponse(403, 'Project details cannot be edited at the current project status.');
        }

        $stmt = $db->prepare('
            UPDATE projects
            SET title = ?, abstract = ?
            WHERE project_id = ? AND student_id = ?
        ');
        $stmt->execute([$title, $abstract, $project['project_id'], $userId]);

        jsonResponse(200, 'Project details updated successfully.');
    }

    if ($method === 'GET' && $action === 'get_feedback') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtProject = $db->prepare('SELECT project_id FROM projects WHERE student_id = ? LIMIT 1');
        $stmtProject->execute([$userId]);
        $feedbackProject = $stmtProject->fetch();
        if (!$feedbackProject) {
            jsonResponse(404, 'No project found for this student.');
        }

        $countStmt = $db->prepare('
            SELECT COUNT(*) AS total
            FROM milestone_submissions
            WHERE project_id = ?
              AND (supervisor_feedback IS NOT NULL OR coordinator_feedback IS NOT NULL)
        ');
        $countStmt->execute([$feedbackProject['project_id']]);
        $total = (int)($countStmt->fetch()['total'] ?? 0);

        $stmt = $db->prepare('
            SELECT s.supervisor_feedback, s.coordinator_feedback, m.name as milestone_name, s.submitted_at
            FROM milestone_submissions s
            JOIN milestones m ON s.milestone_id = m.milestone_id
            WHERE s.project_id = ?
              AND (s.supervisor_feedback IS NOT NULL OR s.coordinator_feedback IS NOT NULL)
            ORDER BY s.submitted_at DESC
            LIMIT 10 OFFSET ?
        ');
        $stmt->bindValue(1, (int)$feedbackProject['project_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(200, 'Feedback fetched.', [
            'messages' => $stmt->fetchAll(),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int)ceil($total / $limit)
        ]);
    }

    // 1. Get Project Info (joined with cohorts and supervisor)
    $stmt = $db->prepare('
        SELECT p.*, c.name as cohort_name, c.start_date, c.end_date,
               s.full_name as supervisor_name, s.email as supervisor_email
        FROM projects p
        JOIN cohorts c ON p.cohort_id = c.cohort_id
        LEFT JOIN users s ON p.supervisor_id = s.user_id
        WHERE p.student_id = ? LIMIT 1
    ');
    $stmt->execute([$userId]);
    $project = $stmt->fetch();

    if (!$project) {
        jsonResponse(404, 'No project found for this student.');
    }

    $projectId = $project['project_id'];
    $defenseFlag = assignNextDefenseIfMissed($db, $project);

    // 2. Get Milestones & Submissions
    // Joining unified milestones with submissions for this project
    // Ensure we only fetch global cohort milestones (supervisor_id IS NULL)
    // OR sub-milestones explicitly created by this student's supervisor
    $stmtM = $db->prepare('
        SELECT m.*, 
               s.status as submission_status, 
               s.supervisor_feedback, 
               s.coordinator_feedback, 
               s.file_path, 
               s.student_text,
               s.version,
               s.submitted_at
        FROM milestones m
        LEFT JOIN project_defense_assignments pda
          ON pda.defense_milestone_id = m.milestone_id
         AND pda.project_id = ?
        LEFT JOIN milestone_submissions s
          ON s.sub_id = (
              SELECT s2.sub_id
              FROM milestone_submissions s2
              WHERE s2.milestone_id = m.milestone_id
                AND s2.project_id = ?
              ORDER BY s2.version DESC, s2.submitted_at DESC
              LIMIT 1
          )
        WHERE (
              (m.cohort_id = ? AND (m.supervisor_id IS NULL OR m.supervisor_id = ?))
              OR pda.project_id IS NOT NULL
          )
        ORDER BY m.due_date ASC, m.sequence_order ASC, m.milestone_id ASC
    ');
    $stmtM->execute([$projectId, $projectId, $project['cohort_id'], $project['supervisor_id']]);
    $milestones = $stmtM->fetchAll();
    $approvedStatuses = ['supervisor_approved', 'coordinator_approved', 'approved', 'satisfactory'];
    $previousApproved = true;
    foreach ($milestones as &$milestone) {
        $status = $milestone['submission_status'] ?? null;
        $isDefense = ($milestone['type'] ?? '') === 'defense';
        $clearedForDefense = $isDefense && in_array($status, ['cleared_for_defense', 'satisfactory', 'satisfactory_with_corrections', 'fail_redo'], true);
        $milestone['is_completed'] = in_array($status, $approvedStatuses, true);
        $milestone['is_locked'] = false;
        $milestone['locked_reason'] = null;
        $milestone['can_submit'] = true;

        if (!$previousApproved) {
            $milestone['is_locked'] = true;
            $milestone['can_submit'] = false;
            $milestone['locked_reason'] = 'Locked: Complete previous milestone first.';
        } elseif ($isDefense && !$clearedForDefense) {
            $milestone['is_locked'] = true;
            $milestone['can_submit'] = false;
            $milestone['locked_reason'] = 'Locked: Await supervisor clearance for Defense.';
        }

        if ($milestone['is_completed']) {
            $milestone['can_submit'] = false;
        }
        if (!$isDefense) {
            $previousApproved = $milestone['is_completed'];
        }
    }
    unset($milestone);

    // 3. Get Recent Feedback
    // Feedback is now directly in milestone_submissions instead of a comments table
    $stmtF = $db->prepare('
        SELECT s.supervisor_feedback, s.coordinator_feedback, m.name as milestone_name, s.submitted_at
        FROM milestone_submissions s
        JOIN milestones m ON s.milestone_id = m.milestone_id
        WHERE s.project_id = ? 
          AND (s.supervisor_feedback IS NOT NULL OR s.coordinator_feedback IS NOT NULL)
        ORDER BY s.submitted_at DESC 
        LIMIT 3
    ');
    $stmtF->execute([$projectId]);
    $feedback = $stmtF->fetchAll();

    // 4. Get Pending Actions (from notifications)
    $stmtN = $db->prepare('
        SELECT * FROM notifications 
        WHERE user_id = ? AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 5
    ');
    $stmtN->execute([$userId]);
    $notifications = $stmtN->fetchAll();

    // 5. Recent Files
    // Proposals are no longer separate, everything is a milestone submission
    $stmtFiles = $db->prepare('
        SELECT s.file_path, s.submitted_at, m.name as type 
        FROM milestone_submissions s
        JOIN milestones m ON s.milestone_id = m.milestone_id
        WHERE s.project_id = ? 
        ORDER BY s.submitted_at DESC 
        LIMIT 5
    ');
    $stmtFiles->execute([$projectId]);
    $files = $stmtFiles->fetchAll();

    $stmtTotal = $db->prepare('
        SELECT COUNT(*) AS total
        FROM milestones
        WHERE cohort_id = ?
          AND (supervisor_id IS NULL OR supervisor_id = ?)
    ');
    $stmtTotal->execute([$project['cohort_id'], $project['supervisor_id']]);
    $totalMilestones = (int)($stmtTotal->fetch()['total'] ?? 0);
    $completedMilestones = 0;
    foreach ($milestones as $milestone) {
        if (in_array($milestone['submission_status'] ?? '', ['supervisor_approved', 'coordinator_approved', 'approved', 'satisfactory'], true)) {
            $completedMilestones++;
        }
    }

    $progress = [
        'total_milestones' => $totalMilestones,
        'completed_milestones' => $completedMilestones,
        'percent' => $totalMilestones > 0 ? (int)round(($completedMilestones / $totalMilestones) * 100) : 0,
        'project_status' => $project['status'] ?? 'draft',
        'can_edit_project' => in_array($project['status'] ?? 'draft', ['draft', 'revision_required'], true),
    ];

    jsonResponse(200, 'Dashboard data retrieved', [
        'project'       => $project,
        'milestones'    => $milestones,
        'feedback'      => $feedback,
        'notifications' => $notifications,
        'files'         => $files,
        'progress'      => $progress,
        'defense_flag'  => $defenseFlag
    ]);

} catch (PDOException $e) {
    jsonResponse(500, 'Database error: ' . $e->getMessage());
}
