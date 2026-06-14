<?php
require_once __DIR__ . '/db.php';

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare('
        SELECT COUNT(*) AS found
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);
    return (int)($stmt->fetch()['found'] ?? 0) > 0;
}

function enumContains(PDO $db, string $table, string $column, string $value): bool {
    $stmt = $db->prepare('
        SELECT COLUMN_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ');
    $stmt->execute([$table, $column]);
    $type = $stmt->fetch()['COLUMN_TYPE'] ?? '';
    return str_contains($type, "'" . $value . "'");
}

function ensureSystemUpgradeSchema(PDO $db): void {
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
        CREATE TABLE IF NOT EXISTS audit_logs (
          audit_id int PRIMARY KEY AUTO_INCREMENT,
          user_id int DEFAULT NULL,
          action varchar(120) NOT NULL,
          entity_type varchar(80) DEFAULT NULL,
          entity_id int DEFAULT NULL,
          details text,
          ip_address varchar(64) DEFAULT NULL,
          created_at timestamp DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
          log_id int PRIMARY KEY AUTO_INCREMENT,
          user_id int DEFAULT NULL,
          action varchar(120) NOT NULL,
          timestamp timestamp DEFAULT CURRENT_TIMESTAMP,
          ip_address varchar(64) DEFAULT NULL,
          FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS milestone_templates (
          template_id int PRIMARY KEY AUTO_INCREMENT,
          name varchar(150) NOT NULL,
          description text,
          created_by int NOT NULL,
          created_at timestamp DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS milestone_template_items (
          item_id int PRIMARY KEY AUTO_INCREMENT,
          template_id int NOT NULL,
          name varchar(150) NOT NULL,
          description text,
          type ENUM ('proposal', 'standard_milestone', 'sub_milestone', 'defense') DEFAULT 'standard_milestone',
          display_order int DEFAULT 1,
          instruction_file_path varchar(500) DEFAULT NULL,
          FOREIGN KEY (template_id) REFERENCES milestone_templates(template_id) ON DELETE CASCADE
        )
    ");

    if (!columnExists($db, 'milestones', 'instruction_file_path')) {
        $db->exec('ALTER TABLE milestones ADD COLUMN instruction_file_path varchar(500) DEFAULT NULL AFTER description');
    }
    if (!columnExists($db, 'milestones', 'sequence_order')) {
        $db->exec('ALTER TABLE milestones ADD COLUMN sequence_order int DEFAULT NULL AFTER type');
    }
    if (!enumContains($db, 'milestones', 'type', 'defense')) {
        $db->exec("ALTER TABLE milestones MODIFY type ENUM ('proposal', 'standard_milestone', 'sub_milestone', 'defense') DEFAULT 'standard_milestone'");
    }
    if (!enumContains($db, 'milestone_submissions', 'status', 'cleared_for_defense')) {
        $db->exec("ALTER TABLE milestone_submissions MODIFY status ENUM ('submitted', 'supervisor_approved', 'coordinator_approved', 'revision_required', 'rejected', 'cleared_for_defense', 'satisfactory', 'satisfactory_with_corrections', 'fail_redo') DEFAULT 'submitted'");
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS cohort_defense_settings (
          defense_setting_id int PRIMARY KEY AUTO_INCREMENT,
          cohort_id int NOT NULL,
          defense_deadline date NOT NULL,
          defense_milestone_id int DEFAULT NULL,
          created_by int NOT NULL,
          created_at timestamp DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_cohort_defense (cohort_id),
          FOREIGN KEY (cohort_id) REFERENCES cohorts(cohort_id) ON DELETE CASCADE,
          FOREIGN KEY (defense_milestone_id) REFERENCES milestones(milestone_id) ON DELETE SET NULL,
          FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS project_defense_assignments (
          assignment_id int PRIMARY KEY AUTO_INCREMENT,
          project_id int NOT NULL,
          original_cohort_id int NOT NULL,
          assigned_cohort_id int NOT NULL,
          defense_milestone_id int NOT NULL,
          reason varchar(150) NOT NULL DEFAULT 'missed_deadline',
          created_at timestamp DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_project_defense_assignment (project_id, defense_milestone_id),
          FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
          FOREIGN KEY (original_cohort_id) REFERENCES cohorts(cohort_id) ON DELETE CASCADE,
          FOREIGN KEY (assigned_cohort_id) REFERENCES cohorts(cohort_id) ON DELETE CASCADE,
          FOREIGN KEY (defense_milestone_id) REFERENCES milestones(milestone_id) ON DELETE CASCADE
        )
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
}
