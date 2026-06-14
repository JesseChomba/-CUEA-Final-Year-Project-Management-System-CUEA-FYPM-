-- ============================================================
--  CUEA Final Year Project Management System – Database Schema
--  Database: cuea_fypm
--  Charset:  utf8mb4 / utf8mb4_unicode_ci
-- ============================================================

CREATE DATABASE IF NOT EXISTS cuea_fypm
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE cuea_fypm;

-- Drop existing tables to ensure clean slate if re-importing
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `supervisor_change_requests`;
DROP TABLE IF EXISTS `submission_comments`;
DROP TABLE IF EXISTS `milestone_submissions`;
DROP TABLE IF EXISTS `milestones`;
DROP TABLE IF EXISTS `supervisor_cohorts`;
DROP TABLE IF EXISTS `supervisor_preferences`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `cohorts`;
DROP TABLE IF EXISTS `system_settings`;

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) PRIMARY KEY,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE `cohorts` (
  `cohort_id` int PRIMARY KEY AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `max_students_per_supervisor` int DEFAULT 12,
  `is_active` tinyint(1) DEFAULT 1
);

CREATE TABLE `users` (
  `user_id` int PRIMARY KEY AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) UNIQUE NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` ENUM ('student', 'supervisor', 'coordinator') NOT NULL,
  `university_id_number` varchar(20),
  `department` varchar(100) DEFAULT 'Computer Science',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `projects` (
  `project_id` int PRIMARY KEY AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `supervisor_id` int,
  `cohort_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `abstract` text,
  `status` ENUM ('draft', 'supervisor_review', 'coordinator_review', 'revision_required', 'approved', 'completed') DEFAULT 'draft',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE `audit_logs` (
  `audit_id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(120) NOT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `details` text,
  `ip_address` varchar(64) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `activity_logs` (
  `log_id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(120) NOT NULL,
  `timestamp` timestamp DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(64) DEFAULT NULL
);

CREATE TABLE `supervisor_preferences` (
  `pref_id` int PRIMARY KEY AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `supervisor_id` int NOT NULL,
  `priority_rank` tinyint NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `supervisor_cohorts` (
  `supervisor_cohort_id` int PRIMARY KEY AUTO_INCREMENT,
  `supervisor_id` int NOT NULL,
  `cohort_id` int NOT NULL,
  `assigned_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `milestones` (
  `milestone_id` int PRIMARY KEY AUTO_INCREMENT,
  `cohort_id` int NOT NULL,
  `parent_milestone_id` int,
  `supervisor_id` int DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `description` text,
  `instruction_file_path` varchar(500) DEFAULT NULL,
  `type` ENUM ('proposal', 'standard_milestone', 'sub_milestone', 'defense') DEFAULT 'standard_milestone',
  `sequence_order` int DEFAULT NULL,
  `due_date` date NOT NULL,
  `created_by` int NOT NULL
);

CREATE TABLE `milestone_submissions` (
  `sub_id` int PRIMARY KEY AUTO_INCREMENT,
  `milestone_id` int NOT NULL,
  `project_id` int NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `student_text` text,
  `version` int DEFAULT 1,
  `submitted_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM ('submitted', 'supervisor_approved', 'coordinator_approved', 'revision_required', 'rejected', 'cleared_for_defense', 'satisfactory', 'satisfactory_with_corrections', 'fail_redo') DEFAULT 'submitted',
  `supervisor_feedback` text,
  `coordinator_feedback` text
);

CREATE TABLE `notifications` (
  `notif_id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` ENUM ('reminder', 'comment', 'status_change', 'assignment', 'general') NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `supervisor_change_requests` (
  `request_id` int PRIMARY KEY AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `student_id` int NOT NULL,
  `current_supervisor_id` int,
  `requested_supervisor_id` int,
  `reason` text NOT NULL,
  `status` ENUM ('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
  `coordinator_comment` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` timestamp NULL DEFAULT NULL
);

CREATE TABLE `submission_comments` (
  `comment_id` int PRIMARY KEY AUTO_INCREMENT,
  `sub_id` int NOT NULL,
  `author_id` int NOT NULL,
  `body` text NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL
);

CREATE UNIQUE INDEX `supervisor_preferences_index_0` ON `supervisor_preferences` (`project_id`, `priority_rank`);
CREATE UNIQUE INDEX `supervisor_cohorts_index_0` ON `supervisor_cohorts` (`supervisor_id`, `cohort_id`);

ALTER TABLE `projects` ADD FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
ALTER TABLE `projects` ADD FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
ALTER TABLE `projects` ADD FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`cohort_id`) ON DELETE CASCADE;

ALTER TABLE `audit_logs` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
ALTER TABLE `activity_logs` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `milestones` ADD FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `supervisor_preferences` ADD FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE;
ALTER TABLE `supervisor_preferences` ADD FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `supervisor_cohorts` ADD FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
ALTER TABLE `supervisor_cohorts` ADD FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`cohort_id`) ON DELETE CASCADE;

ALTER TABLE `milestones` ADD FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`cohort_id`) ON DELETE CASCADE;
ALTER TABLE `milestones` ADD FOREIGN KEY (`parent_milestone_id`) REFERENCES `milestones` (`milestone_id`) ON DELETE CASCADE;
ALTER TABLE `milestones` ADD FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `milestone_submissions` ADD FOREIGN KEY (`milestone_id`) REFERENCES `milestones` (`milestone_id`) ON DELETE CASCADE;
ALTER TABLE `milestone_submissions` ADD FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE;

ALTER TABLE `notifications` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `supervisor_change_requests` ADD FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE;
ALTER TABLE `supervisor_change_requests` ADD FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
ALTER TABLE `supervisor_change_requests` ADD FOREIGN KEY (`current_supervisor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
ALTER TABLE `supervisor_change_requests` ADD FOREIGN KEY (`requested_supervisor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `submission_comments` ADD FOREIGN KEY (`sub_id`) REFERENCES `milestone_submissions` (`sub_id`) ON DELETE CASCADE;
ALTER TABLE `submission_comments` ADD FOREIGN KEY (`author_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

-- ============================================================
--  SEED DATA
-- ============================================================

-- Default Cohort
INSERT INTO `cohorts` (`name`, `start_date`, `end_date`, `max_students_per_supervisor`, `is_active`)
VALUES ('Class of 2024', '2024-01-01', '2024-12-31', 12, 1);

INSERT INTO `system_settings` (`setting_key`, `setting_value`)
VALUES ('max_total_students_per_supervisor', '12');

-- Default Coordinator Account
-- Email: admin@cuea.edu, Password: Admin@CUEA2024
INSERT INTO `users` (`full_name`, `email`, `password`, `role`, `university_id_number`, `department`, `is_active`)
VALUES (
  'System Administrator',
  'admin@cuea.edu',
  '$2y$12$2Y2FkEjlFxNjGb7vQzGiuuoqZl4tOpRHhGY8VPfh5i3UZMkL/X7tq',
  'coordinator',
  NULL,
  'Administration',
  1
);
