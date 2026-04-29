-- DevBridge Database Schema
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- PHP 8.1+, MySQL/MariaDB

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET time_zone = '+00:00';

-- ============================================================
-- admins
-- ============================================================
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `email`         VARCHAR(255) DEFAULT NULL,
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- settings
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `key_name`   VARCHAR(100) NOT NULL,
  `value`      TEXT         NOT NULL DEFAULT '',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- projects
-- ============================================================
CREATE TABLE IF NOT EXISTS `projects` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`               VARCHAR(255) NOT NULL,
  `code`               VARCHAR(60)  NOT NULL,
  `description`        TEXT         DEFAULT NULL,
  `business_goal`      TEXT         DEFAULT NULL,
  `tech_stack`         TEXT         DEFAULT NULL,
  `global_rules`       TEXT         DEFAULT NULL,
  `default_ai_model`   VARCHAR(150) DEFAULT NULL,
  `max_parallel_tasks` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `status`             ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- repositories
-- ============================================================
CREATE TABLE IF NOT EXISTS `repositories` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`            INT UNSIGNED NOT NULL,
  `github_owner`          VARCHAR(100) NOT NULL,
  `github_repo`           VARCHAR(150) NOT NULL,
  `default_branch`        VARCHAR(100) NOT NULL DEFAULT 'main',
  `protected_branch`      VARCHAR(100) DEFAULT NULL,
  `repository_type`       VARCHAR(50)  NOT NULL DEFAULT 'existing',
  `status`                ENUM('active','pending_creation','archived','error') NOT NULL DEFAULT 'active',
  `max_parallel_tasks`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `github_webhook_secret` VARCHAR(255) DEFAULT NULL,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `fk_repos_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- project_rules  (additional per-repository rule overrides)
-- ============================================================
CREATE TABLE IF NOT EXISTS `project_rules` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`    INT UNSIGNED NOT NULL,
  `repository_id` INT UNSIGNED DEFAULT NULL,
  `rule_text`     TEXT NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_project` (`project_id`),
  CONSTRAINT `fk_rules_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- roadmap_branches
-- ============================================================
CREATE TABLE IF NOT EXISTS `roadmap_branches` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`  INT UNSIGNED NOT NULL,
  `code`        VARCHAR(60) NOT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch` (`project_id`, `code`),
  CONSTRAINT `fk_rbranch_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- roadmap_versions
-- ============================================================
CREATE TABLE IF NOT EXISTS `roadmap_versions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`    INT UNSIGNED NOT NULL,
  `repository_id` INT UNSIGNED DEFAULT NULL,
  `branch_code`   VARCHAR(60)  DEFAULT NULL,
  `version`       VARCHAR(20)  NOT NULL,
  `roadmap_md`    MEDIUMTEXT   DEFAULT NULL,
  `roadmap_json`  MEDIUMTEXT   DEFAULT NULL,
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rv_project` (`project_id`),
  CONSTRAINT `fk_rv_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- roadmap_change_proposals
-- ============================================================
CREATE TABLE IF NOT EXISTS `roadmap_change_proposals` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`       INT UNSIGNED NOT NULL,
  `repository_id`    INT UNSIGNED DEFAULT NULL,
  `branch_code`      VARCHAR(60)  DEFAULT NULL,
  `related_task_id`  INT UNSIGNED DEFAULT NULL,
  `related_pr_number` INT UNSIGNED DEFAULT NULL,
  `reason`           TEXT DEFAULT NULL,
  `proposed_md_diff` MEDIUMTEXT DEFAULT NULL,
  `proposed_json_diff` MEDIUMTEXT DEFAULT NULL,
  `status`           ENUM('pending','approved','rejected','applied') NOT NULL DEFAULT 'pending',
  `operator_decision` TEXT DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rcp_project` (`project_id`),
  CONSTRAINT `fk_rcp_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- dev_tasks
-- ============================================================
CREATE TABLE IF NOT EXISTS `dev_tasks` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`              INT UNSIGNED NOT NULL,
  `repository_id`           INT UNSIGNED NOT NULL,
  `parent_task_id`          INT UNSIGNED DEFAULT NULL,
  `title`                   VARCHAR(255) NOT NULL,
  `original_operator_request` TEXT DEFAULT NULL,
  `final_task_spec`         MEDIUMTEXT DEFAULT NULL,
  `roadmap_branch`          VARCHAR(60)  DEFAULT NULL,
  `roadmap_item_code`       VARCHAR(100) DEFAULT NULL,
  `acceptance_criteria_json` TEXT DEFAULT NULL,
  `allowed_files_json`      TEXT DEFAULT NULL,
  `forbidden_files_json`    TEXT DEFAULT NULL,
  `expected_files_json`     TEXT DEFAULT NULL,
  `depends_on_json`         TEXT DEFAULT NULL,
  `risk_level`              ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `run_mode`                ENUM('manual','run_now','queue','after_dependencies') NOT NULL DEFAULT 'manual',
  `priority`                ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `branch_name`             VARCHAR(255) DEFAULT NULL,
  `conflict_status`         ENUM('none','low','medium','high','blocking') NOT NULL DEFAULT 'none',
  `conflict_details_json`   TEXT DEFAULT NULL,
  `github_issue_number`     INT UNSIGNED DEFAULT NULL,
  `github_issue_url`        VARCHAR(500) DEFAULT NULL,
  `github_pr_number`        INT UNSIGNED DEFAULT NULL,
  `github_pr_url`           VARCHAR(500) DEFAULT NULL,
  `review_cycles`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status`                  ENUM(
    'draft','clarifying','ready_to_run','blocked_by_dependency',
    'issue_created','assigned_to_agent','pr_created','reviewing',
    'changes_requested','waiting_for_agent','waiting_for_operator',
    'approved_by_gpt','ready_for_manual_merge','merged','failed','cancelled'
  ) NOT NULL DEFAULT 'draft',
  `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dt_project` (`project_id`),
  KEY `idx_dt_repo` (`repository_id`),
  KEY `idx_dt_status` (`status`),
  CONSTRAINT `fk_dt_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `fk_dt_repo`    FOREIGN KEY (`repository_id`) REFERENCES `repositories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- dev_task_messages  (chat history with GPT)
-- ============================================================
CREATE TABLE IF NOT EXISTS `dev_task_messages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`    INT UNSIGNED NOT NULL,
  `role`       ENUM('operator','assistant','system') NOT NULL DEFAULT 'operator',
  `content`    MEDIUMTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dtm_task` (`task_id`),
  CONSTRAINT `fk_dtm_task` FOREIGN KEY (`task_id`) REFERENCES `dev_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- dev_task_reviews  (GPT code review snapshots + results)
-- ============================================================
CREATE TABLE IF NOT EXISTS `dev_task_reviews` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`           INT UNSIGNED NOT NULL,
  `pr_meta_json`      MEDIUMTEXT DEFAULT NULL,
  `pr_files_json`     MEDIUMTEXT DEFAULT NULL,
  `pr_diff`           LONGTEXT   DEFAULT NULL,
  `check_status_json` TEXT       DEFAULT NULL,
  `review_json`       MEDIUMTEXT DEFAULT NULL,
  `error`             TEXT       DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dtr_task` (`task_id`),
  CONSTRAINT `fk_dtr_task` FOREIGN KEY (`task_id`) REFERENCES `dev_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- operator_decisions
-- ============================================================
CREATE TABLE IF NOT EXISTS `operator_decisions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`     INT UNSIGNED NOT NULL,
  `admin_id`    INT UNSIGNED NOT NULL,
  `action`      VARCHAR(60) NOT NULL,
  `notes`       TEXT DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_od_task` (`task_id`),
  CONSTRAINT `fk_od_task`  FOREIGN KEY (`task_id`)  REFERENCES `dev_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_od_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- github_events  (raw webhook payloads)
-- ============================================================
CREATE TABLE IF NOT EXISTS `github_events` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type`   VARCHAR(60) NOT NULL,
  `action`       VARCHAR(60) DEFAULT NULL,
  `repository`   VARCHAR(255) DEFAULT NULL,
  `pr_number`    INT UNSIGNED DEFAULT NULL,
  `task_id`      INT UNSIGNED DEFAULT NULL,
  `payload_json` LONGTEXT DEFAULT NULL,
  `processed`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ge_event` (`event_type`),
  KEY `idx_ge_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- logs
-- ============================================================
CREATE TABLE IF NOT EXISTS `logs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`       VARCHAR(60) NOT NULL,
  `message`    TEXT NOT NULL,
  `task_id`    INT UNSIGNED DEFAULT NULL,
  `project_id` INT UNSIGNED DEFAULT NULL,
  `context`    TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_type` (`type`),
  KEY `idx_logs_task` (`task_id`),
  KEY `idx_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
