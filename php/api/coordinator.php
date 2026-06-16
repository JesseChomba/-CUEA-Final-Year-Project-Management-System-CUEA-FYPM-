<?php
/**
 * CUEA FYPM – Coordinator API
 */
require_once '../../includes/db.php';
require_once '../../includes/helpers.php';
require_once '../../includes/auth_check.php';
require_once '../../includes/schema.php';
require_once '../../includes/audit.php';
require_once '../../includes/mailer.php';

// Only coordinators
checkRole(['coordinator']);

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize($_GET['action'] ?? '');
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$db     = DB::connect();

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

function saveInstructionUpload(string $fieldName): ?string {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowedExtensions = ['pdf', 'doc', 'docx'];
    $original = basename($_FILES[$fieldName]['name']);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        jsonResponse(400, 'Instruction file must be a PDF, DOC, or DOCX.');
    }

    $dir = '../../uploads/instructions/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
    $fileName = 'instruction_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
    $dest = $dir . $fileName;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dest)) {
        jsonResponse(500, 'Failed to save instruction file.');
    }

    return 'uploads/instructions/' . $fileName;
}

function ensurePhase34Schema(PDO $db): void {
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

function getSetting(PDO $db, string $key, string $default): string {
    $stmt = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    return $stmt->fetch()['setting_value'] ?? $default;
}

function supervisorLoad(PDO $db, int $supervisorId): int {
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM projects WHERE supervisor_id = ?');
    $stmt->execute([$supervisorId]);
    return (int)($stmt->fetch()['total'] ?? 0);
}

function ensureSupervisorCanTakeProject(PDO $db, int $supervisorId): void {
    $maxTotal = (int)getSetting($db, 'max_total_students_per_supervisor', '12');
    if (supervisorLoad($db, $supervisorId) >= $maxTotal) {
        jsonResponse(409, 'This supervisor has reached the maximum total student workload.');
    }
}

/**
 * After a supervisor transfer, auto-complete any sub-milestones owned by the
 * new supervisor whose parent milestone the student has already passed.
 */
function autoCompleteTransferredSubMilestones(PDO $db, int $projectId, int $newSupervisorId): void {
    try {
        $stmt = $db->prepare("
            INSERT IGNORE INTO milestone_submissions
                (milestone_id, project_id, student_text, version, status)
            SELECT sm.milestone_id,
                   :pid,
                   'Auto-completed: student transferred with parent milestone already approved.',
                   1,
                   'supervisor_approved'
            FROM milestones sm
            JOIN milestone_submissions ms
                ON ms.milestone_id = sm.parent_milestone_id
               AND ms.project_id   = :pid2
            WHERE sm.supervisor_id = :sid
              AND sm.type          = 'sub_milestone'
              AND ms.status IN ('supervisor_approved','coordinator_approved','satisfactory','satisfactory_with_corrections')
        ");
        $stmt->execute([':pid' => $projectId, ':pid2' => $projectId, ':sid' => $newSupervisorId]);
    } catch (Throwable $e) {
        // Non-fatal – log but don't break the transfer
        error_log('autoCompleteTransferredSubMilestones error: ' . $e->getMessage());
    }
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

function notifyCohortMilestone(PDO $db, int $cohortId, string $subject, string $message): void {
    $stmt = $db->prepare('
        SELECT u.full_name, u.email
        FROM projects p
        JOIN users u ON p.student_id = u.user_id
        WHERE p.cohort_id = ?
    ');
    $stmt->execute([$cohortId]);
    foreach ($stmt->fetchAll() as $student) {
        if (!empty($student['email'])) {
            sendSystemEmail($student['email'], $student['full_name'], $subject, $message);
        }
    }
}

ensurePhase34Schema($db);
ensureSystemUpgradeSchema($db);

try {
    if ($method === 'GET' && $action === 'get_templates') {
        $stmt = $db->query('
            SELECT t.*, u.full_name AS creator_name
            FROM milestone_templates t
            JOIN users u ON t.created_by = u.user_id
            ORDER BY t.created_at DESC
        ');
        $templates = $stmt->fetchAll();
        foreach ($templates as &$template) {
            $stmtItems = $db->prepare('SELECT * FROM milestone_template_items WHERE template_id = ? ORDER BY display_order ASC');
            $stmtItems->execute([$template['template_id']]);
            $template['items'] = $stmtItems->fetchAll();
        }
        jsonResponse(200, 'Templates fetched', $templates);
    }

    if ($method === 'GET' && $action === 'get_activity_logs') {
        $stmt = $db->query('
            SELECT al.*, u.full_name
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            ORDER BY al.timestamp DESC
            LIMIT 30
        ');
        jsonResponse(200, 'Activity logs fetched.', $stmt->fetchAll());
    }

    if ($method === 'POST' && $action === 'create_template') {
        $name = sanitize($body['name'] ?? '');
        $desc = sanitize($body['description'] ?? '');
        $items = $body['items'] ?? [];
        if (!$name || !is_array($items) || count($items) === 0) {
            jsonResponse(400, 'Template name and at least one milestone item are required.');
        }

        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO milestone_templates (name, description, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$name, $desc, $_SESSION['user_id']]);
        $templateId = (int)$db->lastInsertId();
        $itemStmt = $db->prepare('
            INSERT INTO milestone_template_items (template_id, name, description, type, display_order)
            VALUES (?, ?, ?, ?, ?)
        ');
        $order = 1;
        foreach ($items as $item) {
            $itemName = sanitize((string)($item['name'] ?? ''));
            if ($itemName === '') continue;
            $itemStmt->execute([
                $templateId,
                $itemName,
                sanitize((string)($item['description'] ?? '')),
                normalizeMilestoneType((string)($item['type'] ?? 'standard_milestone')),
                $order++
            ]);
        }
        $db->commit();
        auditLog((int)$_SESSION['user_id'], 'create_template', 'milestone_template', $templateId, $name);
        jsonResponse(201, 'Template created successfully.');
    }

    if ($method === 'POST' && $action === 'apply_template_to_cohort') {
        $templateId = sanitize($body['template_id'] ?? '');
        $cohortId = sanitize($body['cohort_id'] ?? '');
        $dates = $body['dates'] ?? [];
        if (!$templateId || !$cohortId || !is_array($dates)) {
            jsonResponse(400, 'Template, cohort, and date mapping are required.');
        }

        $stmtItems = $db->prepare('SELECT * FROM milestone_template_items WHERE template_id = ? ORDER BY display_order ASC');
        $stmtItems->execute([$templateId]);
        $items = $stmtItems->fetchAll();
        if (!$items) jsonResponse(404, 'Template has no items.');

        $db->beginTransaction();
        $stmt = $db->prepare('
            INSERT INTO milestones (cohort_id, name, type, due_date, description, instruction_file_path, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($items as $item) {
            $due = sanitize((string)($dates[$item['item_id']] ?? ''));
            if (!$due) {
                $db->rollBack();
                jsonResponse(400, "Missing date for template item: {$item['name']}");
            }
            $stmt->execute([
                $cohortId,
                $item['name'],
                $item['type'],
                $due,
                $item['description'],
                $item['instruction_file_path'],
                $_SESSION['user_id']
            ]);
            notifyCohortMilestone(
                $db,
                (int)$cohortId,
                'New CUEA FYPM Milestone',
                "A new milestone '{$item['name']}' is due on {$due}."
            );
        }
        resequenceMilestones($db, (int)$cohortId);
        $db->commit();
        auditLog((int)$_SESSION['user_id'], 'apply_template_to_cohort', 'cohort', (int)$cohortId, 'template_id=' . $templateId);
        jsonResponse(201, 'Template milestones created for cohort.');
    }

    if ($method === 'POST' && $action === 'set_defense_deadline') {
        $cohortId = sanitize($body['cohort_id'] ?? '');
        $deadline = sanitize($body['defense_deadline'] ?? '');
        if (!$cohortId || !$deadline) jsonResponse(400, 'Cohort and defense deadline are required.');

        $stmtMilestone = $db->prepare('SELECT milestone_id FROM milestones WHERE cohort_id = ? AND type = "defense" AND supervisor_id IS NULL LIMIT 1');
        $stmtMilestone->execute([$cohortId]);
        $milestone = $stmtMilestone->fetch();
        if (!$milestone) {
            $stmtCreate = $db->prepare('
                INSERT INTO milestones (cohort_id, name, type, due_date, description, created_by)
                VALUES (?, "Project Defense", "defense", ?, "Final project defense milestone.", ?)
            ');
            $stmtCreate->execute([$cohortId, $deadline, $_SESSION['user_id']]);
            $milestoneId = (int)$db->lastInsertId();
        } else {
            $milestoneId = (int)$milestone['milestone_id'];
            $stmtUpdate = $db->prepare('UPDATE milestones SET due_date = ? WHERE milestone_id = ?');
            $stmtUpdate->execute([$deadline, $milestoneId]);
        }

        $stmt = $db->prepare('
            INSERT INTO cohort_defense_settings (cohort_id, defense_deadline, defense_milestone_id, created_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE defense_deadline = VALUES(defense_deadline), defense_milestone_id = VALUES(defense_milestone_id)
        ');
        $stmt->execute([$cohortId, $deadline, $milestoneId, $_SESSION['user_id']]);
        resequenceMilestones($db, (int)$cohortId);
        notifyCohortMilestone($db, (int)$cohortId, 'CUEA FYPM Defense Deadline', "Project Defense is scheduled for {$deadline}.");
        auditLog((int)$_SESSION['user_id'], 'set_defense_deadline', 'cohort', (int)$cohortId, $deadline);
        jsonResponse(200, 'Defense deadline saved.');
    }

    if ($method === 'POST' && $action === 'upload_milestone_instruction') {
        $milestoneId = sanitize($_POST['milestone_id'] ?? '');
        if (!$milestoneId) jsonResponse(400, 'Milestone ID required.');
        $path = saveInstructionUpload('instruction_file');
        if (!$path) jsonResponse(400, 'Instruction file required.');
        $stmt = $db->prepare('UPDATE milestones SET instruction_file_path = ? WHERE milestone_id = ?');
        $stmt->execute([$path, $milestoneId]);
        auditLog((int)$_SESSION['user_id'], 'upload_instruction_file', 'milestone', (int)$milestoneId, $path);
        jsonResponse(200, 'Instruction file uploaded.', ['path' => $path]);
    }
    if ($method === 'GET' && $action === 'get_settings') {
        jsonResponse(200, 'Settings fetched', [
            'max_total_students_per_supervisor' => (int)getSetting($db, 'max_total_students_per_supervisor', '12')
        ]);
    }

    if ($method === 'POST' && $action === 'update_settings') {
        $maxTotal = max(1, (int)($body['max_total_students_per_supervisor'] ?? 12));
        $stmt = $db->prepare('
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES ("max_total_students_per_supervisor", ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ');
        $stmt->execute([(string)$maxTotal]);
        jsonResponse(200, 'Settings updated successfully.');
    }

    if ($method === 'GET' && $action === 'get_eagle_metrics') {
        $metrics = [];
        $metrics['total_students'] = (int)$db->query("SELECT COUNT(*) AS c FROM users WHERE role = 'student' AND is_active = 1")->fetch()['c'];
        $metrics['total_lecturers'] = (int)$db->query("SELECT COUNT(*) AS c FROM users WHERE role = 'supervisor' AND is_active = 1")->fetch()['c'];
        $metrics['active_projects'] = (int)$db->query("SELECT COUNT(*) AS c FROM projects WHERE status != 'completed'")->fetch()['c'];
        $metrics['pending_transfers'] = (int)$db->query("SELECT COUNT(*) AS c FROM supervisor_change_requests WHERE status = 'pending'")->fetch()['c'];
        jsonResponse(200, 'Metrics fetched', $metrics);
    }

    // Get Cohorts (Utility endpoint for dropdowns)
    if ($method === 'GET' && $action === 'get_cohorts') {
        $stmt = $db->query('SELECT cohort_id, name, start_date, end_date, max_students_per_supervisor, is_active FROM cohorts ORDER BY start_date DESC');
        jsonResponse(200, 'Cohorts fetched', $stmt->fetchAll());
    }

    // Get Parent Milestones (Utility for sub-milestones)
    if ($method === 'GET' && $action === 'get_parent_milestones') {
        $cohort_id = sanitize($_GET['cohort_id'] ?? '');
        if (!$cohort_id) jsonResponse(400, 'cohort_id required');
        $stmt = $db->prepare("SELECT milestone_id, name FROM milestones WHERE cohort_id = ? AND type != 'sub_milestone'");
        $stmt->execute([$cohort_id]);
        jsonResponse(200, 'Milestones fetched', $stmt->fetchAll());
    }

    if ($method === 'GET' && $action === 'get_milestones') {
        $cohortId = sanitize($_GET['cohort_id'] ?? '');
        $sql = "
            SELECT m.*, c.name AS cohort_name
            FROM milestones m
            JOIN cohorts c ON m.cohort_id = c.cohort_id
            WHERE m.supervisor_id IS NULL
        ";
        $params = [];
        if ($cohortId) {
            $sql .= ' AND m.cohort_id = ?';
            $params[] = $cohortId;
        }
        $sql .= ' ORDER BY c.start_date DESC, m.due_date ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse(200, 'Milestones fetched', $stmt->fetchAll());
    }

    // 1. Create User
    if ($method === 'POST' && $action === 'create_user') {
        $name     = sanitize($body['full_name'] ?? '');
        $email    = sanitize($body['email'] ?? '');
        $role     = sanitize($body['role'] ?? '');
        $uid      = sanitize($body['university_id_number'] ?? '');
        $dept     = sanitize($body['department'] ?? 'Computer Science');
        $password = $body['password'] ?? '';
        $cohortId = sanitize($body['cohort_id'] ?? '');

        if (!$name || !$email || !$role || !$password) {
            jsonResponse(400, 'Missing required fields.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $db->beginTransaction();
        
        $stmt = $db->prepare('INSERT INTO users (full_name, email, role, university_id_number, department, password) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, $role, $uid, $dept, $hash]);
        $newUserId = $db->lastInsertId();

        // If it's a student, automatically create a draft project linked to the chosen cohort
        if ($role === 'student' && $cohortId) {
            $projStmt = $db->prepare("INSERT INTO projects (student_id, cohort_id, title) VALUES (?, ?, ?)");
            $projTitle = "Project Proposal - " . $name;
            $projStmt->execute([$newUserId, $cohortId, $projTitle]);
        }
        
        $db->commit();
        auditLog((int)$_SESSION['user_id'], 'create_user', 'user', (int)$newUserId, $role);

        // ── Welcome email with first-time credentials ────────────────
        if (!empty($email)) {
            $roleLabel = ucfirst($role);
            $welcomeBody =
                "Dear {$name},\n\n" .
                "Welcome to the CUEA Final Year Project Management System (FYPM)!\n\n" .
                "Your account has been created successfully by the coordinator. " .
                "Below are your login credentials:\n\n" .
                "  Email:             {$email}\n" .
                "  Temporary Password: {$password}\n" .
                "  Role:              {$roleLabel}\n\n" .
                "Please log in and change your password as soon as possible.\n" .
                "If you have trouble accessing your account, contact your IT Support/the coordinator.\n\n" .
                "Best regards,\nCUEA FYPM Team";
            sendSystemEmail($email, $name, 'Welcome to CUEA FYPM – Your Account is Ready', $welcomeBody);
        }

        jsonResponse(201, 'User created successfully.');
    }

    // 2. Create Cohort
    if ($method === 'POST' && $action === 'create_cohort') {
        $name  = sanitize($body['name'] ?? '');
        $start = sanitize($body['start_date'] ?? '');
        $end   = sanitize($body['end_date'] ?? '');
        $max   = intval($body['max_students'] ?? 12);

        if (!$name || !$start || !$end) {
            jsonResponse(400, 'Missing fields.');
        }

        $stmt = $db->prepare('INSERT INTO cohorts (name, start_date, end_date, max_students_per_supervisor) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $start, $end, $max]);
        jsonResponse(201, 'Cohort created successfully.');
    }

    if ($method === 'POST' && $action === 'update_cohort') {
        $cohortId = sanitize($body['cohort_id'] ?? '');
        $name  = sanitize($body['name'] ?? '');
        $start = sanitize($body['start_date'] ?? '');
        $end   = sanitize($body['end_date'] ?? '');
        $max   = max(1, (int)($body['max_students'] ?? 12));
        $active = !empty($body['is_active']) ? 1 : 0;

        if (!$cohortId || !$name || !$start || !$end) {
            jsonResponse(400, 'Missing cohort fields.');
        }

        $stmt = $db->prepare('
            UPDATE cohorts
            SET name = ?, start_date = ?, end_date = ?, max_students_per_supervisor = ?, is_active = ?
            WHERE cohort_id = ?
        ');
        $stmt->execute([$name, $start, $end, $max, $active, $cohortId]);
        jsonResponse(200, 'Cohort updated successfully.');
    }

    if ($method === 'POST' && $action === 'delete_cohort') {
        $cohortId = sanitize($body['cohort_id'] ?? '');
        if (!$cohortId) jsonResponse(400, 'cohort_id required.');

        $stmtCheck = $db->prepare('SELECT COUNT(*) AS project_count FROM projects WHERE cohort_id = ?');
        $stmtCheck->execute([$cohortId]);
        if ((int)($stmtCheck->fetch()['project_count'] ?? 0) > 0) {
            jsonResponse(409, 'Cannot delete a cohort that already has projects. Mark it inactive instead.');
        }

        $stmt = $db->prepare('DELETE FROM cohorts WHERE cohort_id = ?');
        $stmt->execute([$cohortId]);
        jsonResponse(200, 'Cohort deleted successfully.');
    }

    // 3. Create Milestone
    if ($method === 'POST' && $action === 'create_milestone') {
        $cohortId = sanitize($body['cohort_id'] ?? '');
        $name     = sanitize($body['name'] ?? '');
        $type     = normalizeMilestoneType(sanitize($body['type'] ?? 'standard_milestone'));
        $due      = sanitize($body['due_date'] ?? '');
        $desc     = sanitize($body['description'] ?? '');
        $parent   = !empty($body['parent_id']) ? sanitize($body['parent_id']) : null;
        $creator  = $_SESSION['user_id'];

        if (!$cohortId || !$name || !$due) {
            jsonResponse(400, 'Missing required fields.');
        }

        $stmt = $db->prepare('INSERT INTO milestones (cohort_id, name, type, due_date, description, parent_milestone_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$cohortId, $name, $type, $due, $desc, $parent, $creator]);
        resequenceMilestones($db, (int)$cohortId);
        notifyCohortMilestone($db, (int)$cohortId, 'New CUEA FYPM Milestone', "A new milestone '{$name}' is due on {$due}.");
        auditLog((int)$_SESSION['user_id'], 'create_milestone', 'milestone', (int)$db->lastInsertId(), $name);
        jsonResponse(201, 'Milestone created successfully.');
    }

    if ($method === 'POST' && $action === 'update_milestone') {
        $milestoneId = sanitize($body['milestone_id'] ?? '');
        $cohortId = sanitize($body['cohort_id'] ?? '');
        $name = sanitize($body['name'] ?? '');
        $type = normalizeMilestoneType(sanitize($body['type'] ?? 'standard_milestone'));
        $due = sanitize($body['due_date'] ?? '');
        $desc = sanitize($body['description'] ?? '');
        $parent = !empty($body['parent_id']) ? sanitize($body['parent_id']) : null;

        if (!$milestoneId || !$cohortId || !$name || !$due) {
            jsonResponse(400, 'Missing milestone fields.');
        }

        $stmt = $db->prepare('
            UPDATE milestones
            SET cohort_id = ?, name = ?, type = ?, due_date = ?, description = ?, parent_milestone_id = ?
            WHERE milestone_id = ? AND supervisor_id IS NULL
        ');
        $stmt->execute([$cohortId, $name, $type, $due, $desc, $parent, $milestoneId]);
        resequenceMilestones($db, (int)$cohortId);
        jsonResponse(200, 'Milestone updated successfully.');
    }

    if ($method === 'POST' && $action === 'delete_milestone') {
        $milestoneId = sanitize($body['milestone_id'] ?? '');
        if (!$milestoneId) jsonResponse(400, 'milestone_id required.');

        $stmtCheck = $db->prepare('SELECT COUNT(*) AS submission_count FROM milestone_submissions WHERE milestone_id = ?');
        $stmtCheck->execute([$milestoneId]);
        if ((int)($stmtCheck->fetch()['submission_count'] ?? 0) > 0) {
            jsonResponse(409, 'Cannot delete a milestone that already has submissions.');
        }

        $stmtMeta = $db->prepare('SELECT cohort_id FROM milestones WHERE milestone_id = ? AND supervisor_id IS NULL LIMIT 1');
        $stmtMeta->execute([$milestoneId]);
        $meta = $stmtMeta->fetch();
        if (!$meta) jsonResponse(404, 'Milestone not found.');

        $stmt = $db->prepare('DELETE FROM milestones WHERE milestone_id = ? AND supervisor_id IS NULL');
        $stmt->execute([$milestoneId]);
        resequenceMilestones($db, (int)$meta['cohort_id']);
        jsonResponse(200, 'Milestone deleted successfully.');
    }

    // 4. Get Allocations Overview
    if ($method === 'GET' && $action === 'get_allocations') {
        // Fetch all supervisors and their load
        $stmt = $db->query("
            SELECT u.user_id, u.full_name, 
                   COUNT(DISTINCT p.project_id) as assigned_count,
                   GROUP_CONCAT(DISTINCT c.name ORDER BY c.start_date DESC SEPARATOR ', ') AS cohorts
            FROM users u
            LEFT JOIN projects p ON u.user_id = p.supervisor_id
            LEFT JOIN supervisor_cohorts sc ON u.user_id = sc.supervisor_id
            LEFT JOIN cohorts c ON sc.cohort_id = c.cohort_id
            WHERE u.role = 'supervisor' AND u.is_active = 1
            GROUP BY u.user_id
        ");
        $supervisors = $stmt->fetchAll();
        $maxTotal = (int)getSetting($db, 'max_total_students_per_supervisor', '12');
        foreach ($supervisors as &$supervisor) {
            $supervisor['max_total_students'] = $maxTotal;
            $supervisor['is_full'] = ((int)$supervisor['assigned_count'] >= $maxTotal);
        }
        jsonResponse(200, 'Allocations fetched', $supervisors);
    }

    if ($method === 'POST' && $action === 'assign_supervisor_cohort') {
        $supId = sanitize($body['supervisor_id'] ?? '');
        $cohortId = sanitize($body['cohort_id'] ?? '');
        if (!$supId || !$cohortId) jsonResponse(400, 'Supervisor and cohort are required.');

        $stmt = $db->prepare('INSERT IGNORE INTO supervisor_cohorts (supervisor_id, cohort_id) VALUES (?, ?)');
        $stmt->execute([$supId, $cohortId]);
        jsonResponse(200, 'Supervisor cohort access saved.');
    }

    if ($method === 'GET' && $action === 'get_supervisor_unassigned_cohorts') {
        $supId = sanitize($_GET['supervisor_id'] ?? '');
        if (!$supId) jsonResponse(400, 'Supervisor is required.');
        $stmt = $db->prepare('
            SELECT c.cohort_id, c.name
            FROM cohorts c
            WHERE c.is_active = 1
              AND NOT EXISTS (
                  SELECT 1
                  FROM supervisor_cohorts sc
                  WHERE sc.cohort_id = c.cohort_id
                    AND sc.supervisor_id = ?
              )
            ORDER BY c.start_date DESC, c.name ASC
        ');
        $stmt->execute([$supId]);
        jsonResponse(200, 'Unassigned cohorts fetched.', $stmt->fetchAll());
    }

    if ($method === 'POST' && $action === 'remove_supervisor_cohort') {
        $supId = sanitize($body['supervisor_id'] ?? '');
        $cohortId = sanitize($body['cohort_id'] ?? '');
        if (!$supId || !$cohortId) jsonResponse(400, 'Supervisor and cohort are required.');

        $stmt = $db->prepare('DELETE FROM supervisor_cohorts WHERE supervisor_id = ? AND cohort_id = ?');
        $stmt->execute([$supId, $cohortId]);
        jsonResponse(200, 'Supervisor cohort access removed.');
    }

    // 5. Get Pending Allocations (Unassigned Projects)
    if ($method === 'GET' && $action === 'get_unassigned_projects') {
        $stmt = $db->query("
            SELECT p.project_id, p.title, p.cohort_id, u.full_name as student_name, c.name as cohort_name
            FROM projects p
            JOIN users u ON p.student_id = u.user_id
            JOIN cohorts c ON p.cohort_id = c.cohort_id
            WHERE p.supervisor_id IS NULL
        ");
        $unassigned = $stmt->fetchAll();

        // Fetch preferences for these projects
        foreach ($unassigned as &$proj) {
            $prefStmt = $db->prepare("
                SELECT sp.priority_rank, u.user_id as supervisor_id, u.full_name
                FROM supervisor_preferences sp
                JOIN users u ON sp.supervisor_id = u.user_id
                WHERE sp.project_id = ?
                ORDER BY sp.priority_rank ASC
            ");
            $prefStmt->execute([$proj['project_id']]);
            $proj['preferences'] = $prefStmt->fetchAll();
        }

        jsonResponse(200, 'Unassigned fetched', $unassigned);
    }

    // 6. Assign Supervisor
    if ($method === 'POST' && $action === 'assign_supervisor') {
        $projectId = sanitize($body['project_id'] ?? '');
        $supId     = sanitize($body['supervisor_id'] ?? '');

        if (!$projectId || !$supId) {
            jsonResponse(400, 'Project ID and Supervisor ID required.');
        }

        ensureSupervisorCanTakeProject($db, (int)$supId);

        $stmtProject = $db->prepare('SELECT cohort_id FROM projects WHERE project_id = ? LIMIT 1');
        $stmtProject->execute([$projectId]);
        $projectRow = $stmtProject->fetch();
        if (!$projectRow) jsonResponse(404, 'Project not found.');

        $stmtAllowed = $db->prepare('SELECT 1 FROM supervisor_cohorts WHERE supervisor_id = ? AND cohort_id = ? LIMIT 1');
        $stmtAllowed->execute([$supId, $projectRow['cohort_id']]);
        if (!$stmtAllowed->fetch()) {
            jsonResponse(409, 'This supervisor is not associated with the student cohort.');
        }

        $stmt = $db->prepare('UPDATE projects SET supervisor_id = ? WHERE project_id = ?');
        $stmt->execute([$supId, $projectId]);

        // Auto-complete new supervisor's sub-milestones where parent is already done
        autoCompleteTransferredSubMilestones($db, (int)$projectId, (int)$supId);

        //  Notifications & emails 
        $stmtProj = $db->prepare('SELECT p.student_id, p.title, u.full_name AS student_name, u.email AS student_email FROM projects p JOIN users u ON p.student_id = u.user_id WHERE p.project_id = ? LIMIT 1');
        $stmtProj->execute([$projectId]);
        $projInfo = $stmtProj->fetch();

        $stmtSup = $db->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
        $stmtSup->execute([$supId]);
        $newSup = $stmtSup->fetch();

        if ($projInfo && $newSup) {
            // In-app notification for student
            $studentMsg = "Your project supervisor has been assigned: {$newSup['full_name']} ({$newSup['email']}).";
            $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'assignment', ?)");
            $notifStmt->execute([$projInfo['student_id'], $studentMsg]);

            // Email – student
            if (!empty($projInfo['student_email'])) {
                $studentBody =
                    "Dear {$projInfo['student_name']},\n\n" .
                    "A supervisor has been allocated to your Final Year Project.\n\n" .
                    "  Project : {$projInfo['title']}\n" .
                    "  Supervisor: {$newSup['full_name']}\n" .
                    "  Email   : {$newSup['email']}\n\n" .
                    "Please log in to CUEA FYPM to view your project dashboard and connect with your supervisor.\n\n" .
                    "Best regards,\nCUEA FYPM Team";
                sendSystemEmail($projInfo['student_email'], $projInfo['student_name'], 'CUEA FYPM – Supervisor Allocated to Your Project', $studentBody);
            }

            // Email – new supervisor
            if (!empty($newSup['email'])) {
                $supBody =
                    "Dear {$newSup['full_name']},\n\n" .
                    "A student project has been assigned to your supervision.\n\n" .
                    "  Student : {$projInfo['student_name']}\n" .
                    "  Project : {$projInfo['title']}\n\n" .
                    "Please log in to CUEA FYPM to review your supervisee list.\n\n" .
                    "Best regards,\nCUEA FYPM Team";
                sendSystemEmail($newSup['email'], $newSup['full_name'], 'CUEA FYPM – New Supervisee Assigned to You', $supBody);
            }
        }
        auditLog((int)$_SESSION['user_id'], 'assign_supervisor', 'project', (int)$projectId, 'supervisor_id=' . $supId);

        jsonResponse(200, 'Supervisor assigned successfully.');
    }

    if ($method === 'POST' && $action === 'transfer_student') {
        $projectId = sanitize($body['project_id'] ?? '');
        $supId = sanitize($body['supervisor_id'] ?? '');
        if (!$projectId || !$supId) jsonResponse(400, 'Project and new supervisor are required.');

        ensureSupervisorCanTakeProject($db, (int)$supId);
        $stmtProject = $db->prepare('
            SELECT p.student_id, p.cohort_id, p.supervisor_id, p.title,
                   u.full_name AS student_name, u.email AS student_email
            FROM projects p
            JOIN users u ON p.student_id = u.user_id
            WHERE p.project_id = ? LIMIT 1
        ');
        $stmtProject->execute([$projectId]);
        $project = $stmtProject->fetch();
        if (!$project) jsonResponse(404, 'Project not found.');

        $stmtAllowed = $db->prepare('SELECT 1 FROM supervisor_cohorts WHERE supervisor_id = ? AND cohort_id = ? LIMIT 1');
        $stmtAllowed->execute([$supId, $project['cohort_id']]);
        if (!$stmtAllowed->fetch()) {
            jsonResponse(409, 'New supervisor is not associated with this cohort.');
        }

        // Fetch old & new supervisor info before transfer
        $oldSupId = $project['supervisor_id'];
        $stmtOldSup = $db->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
        $stmtOldSup->execute([$oldSupId]);
        $oldSup = $stmtOldSup->fetch();

        $stmtNewSup = $db->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
        $stmtNewSup->execute([$supId]);
        $newSup = $stmtNewSup->fetch();

        // Perform transfer
        $stmt = $db->prepare('UPDATE projects SET supervisor_id = ? WHERE project_id = ?');
        $stmt->execute([$supId, $projectId]);

        // Auto-complete new supervisor's sub-milestones where parent is already done
        autoCompleteTransferredSubMilestones($db, (int)$projectId, (int)$supId);

        //  In-app notification + emails 
        $studentMsg = 'Your project has been transferred to a new supervisor by the Coordinator.';
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'assignment', ?)");
        $notifStmt->execute([$project['student_id'], $studentMsg]);

        // Email – student
        if (!empty($project['student_email']) && $newSup) {
            $body_student =
                "Dear {$project['student_name']},\n\n" .
                "The Coordinator has transferred your project to a new supervisor.\n\n" .
                "  Project     : {$project['title']}\n" .
                "  New Supervisor: {$newSup['full_name']}\n" .
                "  Email        : {$newSup['email']}\n\n" .
                "Please log in to CUEA FYPM to view your updated dashboard.\n\n" .
                "Best regards,\nCUEA FYPM Team";
            sendSystemEmail($project['student_email'], $project['student_name'], 'CUEA FYPM – Your Supervisor Has Changed', $body_student);
        }

        // Email – old supervisor
        if ($oldSup && !empty($oldSup['email'])) {
            $body_old =
                "Dear {$oldSup['full_name']},\n\n" .
                "This is to inform you that a student previously under your supervision has been transferred by the Coordinator.\n\n" .
                "  Student : {$project['student_name']}\n" .
                "  Project : {$project['title']}\n\n" .
                "No further action is required from you regarding this student.\n\n" .
                "Best regards,\nCUEA FYPM Team";
            sendSystemEmail($oldSup['email'], $oldSup['full_name'], 'CUEA FYPM – Supervisee Transferred Away', $body_old);
        }

        // Email – new supervisor
        if ($newSup && !empty($newSup['email'])) {
            $body_new =
                "Dear {$newSup['full_name']},\n\n" .
                "A student project has been transferred to your supervision by the Coordinator.\n\n" .
                "  Student : {$project['student_name']}\n" .
                "  Project : {$project['title']}\n\n" .
                "Please log in to CUEA FYPM to review your updated supervisee list.\n\n" .
                "Best regards,\nCUEA FYPM Team";
            sendSystemEmail($newSup['email'], $newSup['full_name'], 'CUEA FYPM – New Supervisee Transferred to You', $body_new);
        }

        auditLog((int)$_SESSION['user_id'], 'manual_transfer_student', 'project', (int)$projectId, 'new_supervisor_id=' . $supId);
        jsonResponse(200, 'Student transferred successfully.');
    }

    if ($method === 'GET' && $action === 'search_transfer_students') {
        $query = sanitize($_GET['admission_number'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 7;
        $offset = ($page - 1) * $limit;

        if ($query === '') {
            jsonResponse(400, 'Admission number search term is required.');
        }

        $like = '%' . $query . '%';
        $countStmt = $db->prepare('
            SELECT COUNT(*) AS total
            FROM projects p
            JOIN users u ON p.student_id = u.user_id
            WHERE u.role = "student"
              AND u.university_id_number LIKE ?
        ');
        $countStmt->execute([$like]);
        $total = (int)($countStmt->fetch()['total'] ?? 0);

        $stmt = $db->prepare('
            SELECT p.project_id, p.title, p.status, p.supervisor_id, p.cohort_id,
                   u.full_name AS student_name, u.university_id_number,
                   s.full_name AS supervisor_name, c.name AS cohort_name
            FROM projects p
            JOIN users u ON p.student_id = u.user_id
            JOIN cohorts c ON p.cohort_id = c.cohort_id
            LEFT JOIN users s ON p.supervisor_id = s.user_id
            WHERE u.role = "student"
              AND u.university_id_number LIKE ?
            ORDER BY u.university_id_number ASC
            LIMIT 7 OFFSET ?
        ');
        $stmt->bindValue(1, $like, PDO::PARAM_STR);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        jsonResponse(200, 'Transfer search results fetched.', [
            'results' => $stmt->fetchAll(),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int)ceil($total / $limit)
        ]);
    }

    if ($method === 'GET' && $action === 'get_transfer_requests') {
        $stmt = $db->query("
            SELECT scr.*, p.title AS project_title, stu.full_name AS student_name,
                   cur.full_name AS current_supervisor_name,
                   req.full_name AS requested_supervisor_name
            FROM supervisor_change_requests scr
            JOIN projects p ON scr.project_id = p.project_id
            JOIN users stu ON scr.student_id = stu.user_id
            LEFT JOIN users cur ON scr.current_supervisor_id = cur.user_id
            LEFT JOIN users req ON scr.requested_supervisor_id = req.user_id
            ORDER BY scr.created_at DESC
        ");
        jsonResponse(200, 'Transfer requests fetched', $stmt->fetchAll());
    }

    if ($method === 'POST' && $action === 'process_transfer_request') {
        $requestId = sanitize($body['request_id'] ?? '');
        $decision = sanitize($body['decision'] ?? '');
        $newSupervisorId = sanitize($body['supervisor_id'] ?? '');
        $comment = sanitize($body['coordinator_comment'] ?? '');

        if (!$requestId || !in_array($decision, ['approved', 'rejected'], true)) {
            jsonResponse(400, 'Valid request and decision are required.');
        }

        $stmtReq = $db->prepare('
            SELECT scr.*, p.cohort_id
            FROM supervisor_change_requests scr
            JOIN projects p ON scr.project_id = p.project_id
            WHERE scr.request_id = ? AND scr.status = "pending"
            LIMIT 1
        ');
        $stmtReq->execute([$requestId]);
        $request = $stmtReq->fetch();
        if (!$request) jsonResponse(404, 'Pending transfer request not found.');

        $db->beginTransaction();
        if ($decision === 'approved') {
            $targetSupervisor = $newSupervisorId ?: $request['requested_supervisor_id'];
            if (!$targetSupervisor) {
                $db->rollBack();
                jsonResponse(400, 'Choose a target supervisor before approving.');
            }
            ensureSupervisorCanTakeProject($db, (int)$targetSupervisor);
            $stmtAllowed = $db->prepare('SELECT 1 FROM supervisor_cohorts WHERE supervisor_id = ? AND cohort_id = ? LIMIT 1');
            $stmtAllowed->execute([$targetSupervisor, $request['cohort_id']]);
            if (!$stmtAllowed->fetch()) {
                $db->rollBack();
                jsonResponse(409, 'Target supervisor is not associated with this cohort.');
            }

            // Fetch old & new supervisor info before transfer
            $stmtOldSup2 = $db->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
            $stmtOldSup2->execute([$request['current_supervisor_id']]);
            $oldSup2 = $stmtOldSup2->fetch();

            $stmtNewSup2 = $db->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
            $stmtNewSup2->execute([$targetSupervisor]);
            $newSup2 = $stmtNewSup2->fetch();

            $stmtStudentInfo = $db->prepare('
                SELECT p.title, u.full_name AS student_name, u.email AS student_email
                FROM projects p JOIN users u ON p.student_id = u.user_id
                WHERE p.project_id = ? LIMIT 1
            ');
            $stmtStudentInfo->execute([$request['project_id']]);
            $studentInfo = $stmtStudentInfo->fetch();

            $stmtTransfer = $db->prepare('UPDATE projects SET supervisor_id = ? WHERE project_id = ?');
            $stmtTransfer->execute([$targetSupervisor, $request['project_id']]);

            // Auto-complete new supervisor's sub-milestones where parent is already done
            autoCompleteTransferredSubMilestones($db, (int)$request['project_id'], (int)$targetSupervisor);

            //  Emails for approved transfer request 
            // Email – student
            if ($studentInfo && !empty($studentInfo['student_email']) && $newSup2) {
                $sb =
                    "Dear {$studentInfo['student_name']},\n\n" .
                    "Your supervisor change request has been approved by the Coordinator.\n\n" .
                    "  Project     : {$studentInfo['title']}\n" .
                    "  New Supervisor: {$newSup2['full_name']}\n" .
                    "  Email        : {$newSup2['email']}\n\n" .
                    "Please log in to CUEA FYPM to view your updated project dashboard.\n\n" .
                    "Best regards,\nCUEA FYPM Team";
                sendSystemEmail($studentInfo['student_email'], $studentInfo['student_name'], 'CUEA FYPM – Supervisor Change Approved', $sb);
            }
            // Email – old supervisor
            if ($oldSup2 && !empty($oldSup2['email']) && $studentInfo) {
                $ob =
                    "Dear {$oldSup2['full_name']},\n\n" .
                    "A student who was under your supervision has been transferred following an approved change request.\n\n" .
                    "  Student : {$studentInfo['student_name']}\n" .
                    "  Project : {$studentInfo['title']}\n\n" .
                    "No further action is required from you regarding this student.\n\n" .
                    "Best regards,\nCUEA FYPM Team";
                sendSystemEmail($oldSup2['email'], $oldSup2['full_name'], 'CUEA FYPM – Supervisee Transferred Away', $ob);
            }
            // Email – new supervisor
            if ($newSup2 && !empty($newSup2['email']) && $studentInfo) {
                $nb =
                    "Dear {$newSup2['full_name']},\n\n" .
                    "A student project has been transferred to your supervision following an approved change request.\n\n" .
                    "  Student : {$studentInfo['student_name']}\n" .
                    "  Project : {$studentInfo['title']}\n\n" .
                    "Please log in to CUEA FYPM to review your supervisee list.\n\n" .
                    "Best regards,\nCUEA FYPM Team";
                sendSystemEmail($newSup2['email'], $newSup2['full_name'], 'CUEA FYPM – New Supervisee Transferred to You', $nb);
            }
        }

        $stmtUpdate = $db->prepare('
            UPDATE supervisor_change_requests
            SET status = ?, requested_supervisor_id = COALESCE(NULLIF(?, ""), requested_supervisor_id),
                coordinator_comment = ?, resolved_at = NOW()
            WHERE request_id = ?
        ');
        $stmtUpdate->execute([$decision, $newSupervisorId, $comment, $requestId]);

        $message = $decision === 'approved'
            ? 'Your supervisor change request has been approved.'
            : 'Your supervisor change request has been rejected.';
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'status_change', ?)");
        $notifStmt->execute([$request['student_id'], $message]);

        $db->commit();
        jsonResponse(200, 'Transfer request processed successfully.');
    }

    // 7. Get System Overview Data
    if ($method === 'GET' && $action === 'get_overview_data') {
        $cohortId       = sanitize($_GET['cohort_id'] ?? '');
        $supId          = sanitize($_GET['supervisor_id'] ?? '');
        $milestoneStatus = sanitize($_GET['milestone_status'] ?? '');

        // Valid status values
        $validStatuses = ['submitted','supervisor_approved','coordinator_approved',
                          'revision_required','rejected','cleared_for_defense',
                          'satisfactory','satisfactory_with_corrections','fail_redo'];

        $sql = "
            SELECT p.project_id, p.title, p.status, u.full_name as student_name,
                   s.full_name as supervisor_name, c.name as cohort_name
            FROM projects p
            JOIN users u ON p.student_id = u.user_id
            JOIN cohorts c ON p.cohort_id = c.cohort_id
            LEFT JOIN users s ON p.supervisor_id = s.user_id
            WHERE 1=1
        ";
        $params = [];
        if ($cohortId) {
            $sql .= ' AND p.cohort_id = ?';
            $params[] = $cohortId;
        }
        if ($supId) {
            $sql .= ' AND p.supervisor_id = ?';
            $params[] = $supId;
        }
        if ($milestoneStatus && in_array($milestoneStatus, $validStatuses, true)) {
            // Filter: most-recent submission for the project must match this status
            $sql .= '
                AND (
                    SELECT ms2.status
                    FROM milestone_submissions ms2
                    WHERE ms2.project_id = p.project_id
                    ORDER BY ms2.submitted_at DESC
                    LIMIT 1
                ) = ?';
            $params[] = $milestoneStatus;
        } elseif ($milestoneStatus === 'no_submission') {
            $sql .= '
                AND NOT EXISTS (
                    SELECT 1 FROM milestone_submissions ms3
                    WHERE ms3.project_id = p.project_id
                )';
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $projects = $stmt->fetchAll();

        // Attach overall milestone progress for each project
        foreach ($projects as &$proj) {
            $mStmt = $db->prepare('SELECT COUNT(*) as total FROM milestones WHERE cohort_id = ?');
            $mStmt->execute([$proj['cohort_id'] ?? 0]);
            $total = $mStmt->fetch()['total'] ?? 0;

            $sStmt = $db->prepare("SELECT COUNT(DISTINCT milestone_id) as completed FROM milestone_submissions WHERE project_id = ? AND status IN ('supervisor_approved','coordinator_approved','satisfactory','satisfactory_with_corrections')");
            $sStmt->execute([$proj['project_id']]);
            $completed = $sStmt->fetch()['completed'] ?? 0;

            $proj['progress'] = $total > 0 ? round(($completed / $total) * 100) : 0;
            $proj['milestones_total'] = $total;
            $proj['milestones_completed'] = $completed;

            $statusStmt = $db->prepare("
                SELECT m.name, s.status
                FROM milestone_submissions s
                JOIN milestones m ON s.milestone_id = m.milestone_id
                WHERE s.project_id = ?
                ORDER BY s.submitted_at DESC
                LIMIT 1
            ");
            $statusStmt->execute([$proj['project_id']]);
            $latest = $statusStmt->fetch();
            $proj['current_milestone_status'] = $latest
                ? ($latest['name'] . ': ' . str_replace('_', ' ', $latest['status']))
                : 'No submissions yet';
        }

        jsonResponse(200, 'Overview data fetched', $projects);
    }

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    // Catch duplicate entry
    if ($e->getCode() == 23000) {
        jsonResponse(409, 'A record with that email or identifier already exists.');
    }
    jsonResponse(500, 'Database error: ' . $e->getMessage());
}

jsonResponse(400, 'Invalid action.');
