<?php
/**
 * CUEA FYPM – Submissions API
 */
require_once '../../includes/db.php';
require_once '../../includes/helpers.php';
require_once '../../includes/auth_check.php';
require_once '../../includes/schema.php';
require_once '../../includes/audit.php';
require_once '../../includes/mailer.php';

// Only students or supervisors can access some endpoints
checkRole(['student', 'supervisor']);

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize($_GET['action'] ?? '');
$db     = DB::connect();
$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'];

ensureSystemUpgradeSchema($db);

function normalizeMilestoneType(string $type): string {
    $normalized = strtolower(trim($type));
    $normalized = str_replace([' ', '-'], '_', $normalized);

    $aliases = [
        'proposal' => 'proposal',
        'project_proposal' => 'proposal',
        'standard' => 'standard_milestone',
        'milestone' => 'standard_milestone',
        'standard_milestone' => 'standard_milestone',
        'sub' => 'sub_milestone',
        'sub_milestone' => 'sub_milestone',
        'submilestone' => 'sub_milestone',
        'defense' => 'defense',
        'project_defense' => 'defense',
    ];

    return $aliases[$normalized] ?? 'standard_milestone';
}

function safeUploadOriginalName(string $originalName): string {
    $baseName = basename($originalName);
    $baseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $baseName);
    $baseName = preg_replace('/_+/', '_', $baseName);
    return trim($baseName, '._') ?: 'submission_file';
}

try {
    // 1. Submit Milestone (Student)
    if ($method === 'POST' && $action === 'submit_milestone') {
        checkRole(['student']);
        
        $milestoneId = sanitize($_POST['milestone_id'] ?? '');
        $studentText = sanitize($_POST['student_text'] ?? '');
        
        if (!$milestoneId) jsonResponse(400, 'Milestone ID required.');

        // Get student's project
        $stmt = $db->prepare('SELECT project_id, supervisor_id FROM projects WHERE student_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $proj = $stmt->fetch();
        if (!$proj) jsonResponse(400, 'No active project found.');
        
        $projectId = $proj['project_id'];

        $mStmt = $db->prepare('
            SELECT milestone_id, name, type
            FROM milestones
            WHERE milestone_id = ?
              AND (
                    cohort_id = (SELECT cohort_id FROM projects WHERE project_id = ?)
                    OR supervisor_id = ?
                  )
            LIMIT 1
        ');
        $mStmt->execute([$milestoneId, $projectId, $proj['supervisor_id']]);
        $milestone = $mStmt->fetch();
        if (!$milestone) {
            jsonResponse(404, 'Milestone not found for your active project.');
        }

        $canonicalType = normalizeMilestoneType($milestone['type'] ?? ($_POST['type'] ?? 'standard_milestone'));

        $latestStmt = $db->prepare('
            SELECT status
            FROM milestone_submissions
            WHERE milestone_id = ? AND project_id = ?
            ORDER BY version DESC, submitted_at DESC
            LIMIT 1
        ');
        $latestStmt->execute([$milestoneId, $projectId]);
        $latestSubmission = $latestStmt->fetch();
        if ($canonicalType === 'defense' && !$latestSubmission) {
            jsonResponse(403, 'Defense submissions are available only after supervisor clearance.');
        }
        if ($canonicalType === 'defense' && !in_array($latestSubmission['status'] ?? '', ['cleared_for_defense', 'satisfactory_with_corrections', 'fail_redo'], true)) {
            jsonResponse(403, 'Defense submissions are available only after supervisor clearance.');
        }

        $orderedStmt = $db->prepare('
            SELECT m.milestone_id, m.type,
                   (
                     SELECT s2.status
                     FROM milestone_submissions s2
                     WHERE s2.milestone_id = m.milestone_id
                       AND s2.project_id = ?
                     ORDER BY s2.version DESC, s2.submitted_at DESC
                     LIMIT 1
                   ) AS latest_status
            FROM milestones m
            LEFT JOIN project_defense_assignments pda
              ON pda.defense_milestone_id = m.milestone_id
             AND pda.project_id = ?
            WHERE (
                  (m.cohort_id = (SELECT cohort_id FROM projects WHERE project_id = ?)
                   AND (m.supervisor_id IS NULL OR m.supervisor_id = ?))
                  OR pda.project_id IS NOT NULL
            )
            ORDER BY m.due_date ASC, m.sequence_order ASC, m.milestone_id ASC
        ');
        $orderedStmt->execute([$projectId, $projectId, $projectId, $proj['supervisor_id']]);
        $approvedStatuses = ['supervisor_approved', 'coordinator_approved', 'approved', 'satisfactory'];
        $previousApproved = true;
        foreach ($orderedStmt->fetchAll() as $orderedMilestone) {
            if ((int)$orderedMilestone['milestone_id'] === (int)$milestoneId) {
                if (!$previousApproved) {
                    jsonResponse(403, 'Locked: Complete previous milestone first.');
                }
                break;
            }
            if (($orderedMilestone['type'] ?? '') !== 'defense') {
                $previousApproved = in_array($orderedMilestone['latest_status'] ?? '', $approvedStatuses, true);
            }
        }

        if ($latestSubmission && in_array($latestSubmission['status'], ['supervisor_approved', 'coordinator_approved', 'satisfactory'], true)) {
            jsonResponse(409, 'This milestone has been completed and cannot be resubmitted.');
        }

        $filePath = null;
        $hasUpload = isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;
        if (!$hasUpload && empty($studentText)) {
            jsonResponse(400, 'Please provide either a file or a text submission.');
        }

        // Get next version
        $vStmt = $db->prepare('SELECT IFNULL(MAX(version), 0) + 1 as next_v FROM milestone_submissions WHERE milestone_id = ? AND project_id = ?');
        $vStmt->execute([$milestoneId, $projectId]);
        $version = $vStmt->fetch()['next_v'];

        $db->beginTransaction();
        $filePath = null;
        $insStmt = $db->prepare('
            INSERT INTO milestone_submissions (milestone_id, project_id, file_path, student_text, version, status)
            VALUES (?, ?, NULL, ?, ?, "submitted")
        ');
        $insStmt->execute([$milestoneId, $projectId, $studentText, $version]);
        $submissionId = (int)$db->lastInsertId();

        if ($hasUpload) {
            $uploadDir = '../../uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $safeOriginal = safeUploadOriginalName($_FILES['file']['name']);
            $fileName = 'submission_' . $submissionId . '_' . time() . '_' . $safeOriginal;
            $destPath = $uploadDir . $fileName;

            if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
                $db->rollBack();
                jsonResponse(500, 'Failed to save uploaded file.');
            }

            $filePath = 'uploads/' . $fileName;
            $updStmt = $db->prepare('UPDATE milestone_submissions SET file_path = ? WHERE sub_id = ?');
            $updStmt->execute([$filePath, $submissionId]);
        }

        // Notify Supervisor
        if ($proj['supervisor_id']) {
            $mName = $milestone['name'] ?? 'a milestone';
            
            $msg = "{$_SESSION['name']} has submitted Version {$version} for '{$mName}' ({$canonicalType}).";
            $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'general', ?)");
            $notifStmt->execute([$proj['supervisor_id'], $msg]);

            $supStmt = $db->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
            $supStmt->execute([$proj['supervisor_id']]);
            $supervisor = $supStmt->fetch();
            if ($supervisor && !empty($supervisor['email'])) {
                sendSystemEmail(
                    $supervisor['email'],
                    $supervisor['full_name'],
                    'New CUEA FYPM Submission',
                    $msg . "\n\nPlease log in to review the submission."
                );
            }
        }

        auditLog((int)$userId, 'submit_milestone', 'submission', $submissionId, $canonicalType);
        $db->commit();

        jsonResponse(200, 'Submission successful.');
    }

    // 2. Get Submissions for a Milestone (Student/Supervisor)
    if ($method === 'GET' && $action === 'get_submissions') {
        $milestoneId = sanitize($_GET['milestone_id'] ?? '');
        $projectId   = sanitize($_GET['project_id'] ?? '');

        if ($role === 'student') {
            $stmt = $db->prepare('SELECT project_id FROM projects WHERE student_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $proj = $stmt->fetch();
            if (!$proj) jsonResponse(400, 'No project found.');
            $projectId = $proj['project_id'];
        }

        if (!$milestoneId || !$projectId) jsonResponse(400, 'Missing parameters.');

        $stmt = $db->prepare('
            SELECT * FROM milestone_submissions 
            WHERE milestone_id = ? AND project_id = ? 
            ORDER BY version DESC
        ');
        $stmt->execute([$milestoneId, $projectId]);
        $subs = $stmt->fetchAll();

        jsonResponse(200, 'Submissions fetched', $subs);
    }

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(500, 'Database error: ' . $e->getMessage());
}

jsonResponse(400, 'Invalid action.');
