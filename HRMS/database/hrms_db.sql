-- ============================================================
-- HRMS database schema  (MySQL 5.7+ / MariaDB 10.3+)
-- Import with phpMyAdmin (Import tab) or:  mysql -u root -p < hrms_db.sql
-- No users are created here. Create the first Admin with setup.php (see README).
-- ============================================================

CREATE DATABASE IF NOT EXISTS `hrms_db`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `hrms_db`;

SET NAMES utf8mb4;

-- ---------- departments ----------
CREATE TABLE IF NOT EXISTS `departments` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_name` (`name`)
) ENGINE=InnoDB;

-- ---------- users (login accounts) ----------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(100) NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB;

-- ---------- staff (employment profile, 1:1 with users) ----------
CREATE TABLE IF NOT EXISTS `staff` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT UNSIGNED NOT NULL,
  `staff_code`        VARCHAR(20) NOT NULL,
  `phone`             VARCHAR(20) NULL,
  `department_id`     INT UNSIGNED NULL,
  `designation`       VARCHAR(100) NULL,
  `joining_date`      DATE NOT NULL,
  `employment_status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `salary`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `address`           VARCHAR(255) NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_user` (`user_id`),
  UNIQUE KEY `uq_staff_code` (`staff_code`),
  KEY `idx_staff_department` (`department_id`),
  KEY `idx_staff_status` (`employment_status`),
  CONSTRAINT `fk_staff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_staff_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------- attendance (one row per staff per day) ----------
CREATE TABLE IF NOT EXISTS `attendance` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id`     INT UNSIGNED NOT NULL,
  `att_date`     DATE NOT NULL,
  `check_in`     TIME NULL,
  `check_out`    TIME NULL,
  `status`       ENUM('present','late','half_day') NOT NULL DEFAULT 'present',
  `work_minutes` INT UNSIGNED NULL,
  `source`       ENUM('self','manual') NOT NULL DEFAULT 'self',
  `notes`        VARCHAR(255) NULL,
  `created_by`   INT UNSIGNED NULL,
  `updated_by`   INT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_staff_date` (`staff_id`,`att_date`),
  KEY `idx_attendance_date` (`att_date`),
  CONSTRAINT `fk_attendance_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_attendance_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendance_updated` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- leave_types ----------
CREATE TABLE IF NOT EXISTS `leave_types` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(60) NOT NULL,
  `days_per_year` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = no yearly limit',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_leave_types_name` (`name`)
) ENGINE=InnoDB;

-- ---------- leave_requests ----------
CREATE TABLE IF NOT EXISTS `leave_requests` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id`       INT UNSIGNED NOT NULL,
  `leave_type_id`  INT UNSIGNED NOT NULL,
  `from_date`      DATE NOT NULL,
  `to_date`        DATE NOT NULL,
  `days`           SMALLINT UNSIGNED NOT NULL,
  `reason`         VARCHAR(500) NOT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by`    INT UNSIGNED NULL,
  `reviewed_at`    DATETIME NULL,
  `admin_comments` VARCHAR(500) NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leave_staff` (`staff_id`),
  KEY `idx_leave_status` (`status`),
  KEY `idx_leave_dates` (`from_date`,`to_date`),
  CONSTRAINT `fk_leave_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_leave_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_leave_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- time_permissions ----------
CREATE TABLE IF NOT EXISTS `time_permissions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id`       INT UNSIGNED NOT NULL,
  `from_date`      DATE NOT NULL,
  `from_time`      TIME NOT NULL,
  `to_date`        DATE NOT NULL,
  `to_time`        TIME NOT NULL,
  `reason`         VARCHAR(500) NOT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by`    INT UNSIGNED NULL,
  `reviewed_at`    DATETIME NULL,
  `admin_comments` VARCHAR(500) NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_perm_staff` (`staff_id`),
  KEY `idx_perm_status` (`status`),
  KEY `idx_perm_from` (`from_date`),
  CONSTRAINT `fk_perm_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_perm_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- incentives ----------
CREATE TABLE IF NOT EXISTS `incentives` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id`       INT UNSIGNED NOT NULL,
  `amount`         DECIMAL(12,2) NOT NULL,
  `reason`         VARCHAR(255) NOT NULL,
  `incentive_date` DATE NOT NULL,
  `period_month`   TINYINT UNSIGNED NOT NULL,
  `period_year`    SMALLINT UNSIGNED NOT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `payment_status` ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `document_path`  VARCHAR(100) NULL,
  `document_name`  VARCHAR(150) NULL,
  `approved_by`    INT UNSIGNED NULL,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incentives_staff` (`staff_id`),
  KEY `idx_incentives_period` (`period_year`,`period_month`),
  KEY `idx_incentives_status` (`status`),
  CONSTRAINT `fk_incentives_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_incentives_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_incentives_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- deductions ----------
CREATE TABLE IF NOT EXISTS `deductions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id`       INT UNSIGNED NOT NULL,
  `category`       VARCHAR(50) NOT NULL,
  `amount`         DECIMAL(12,2) NOT NULL,
  `reason`         VARCHAR(255) NOT NULL,
  `deduction_date` DATE NOT NULL,
  `period_month`   TINYINT UNSIGNED NOT NULL,
  `period_year`    SMALLINT UNSIGNED NOT NULL,
  `status`         ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deductions_staff` (`staff_id`),
  KEY `idx_deductions_period` (`period_year`,`period_month`),
  KEY `idx_deductions_category` (`category`),
  CONSTRAINT `fk_deductions_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_deductions_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- payroll (one saved record per staff per month) ----------
CREATE TABLE IF NOT EXISTS `payroll` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id`         INT UNSIGNED NOT NULL,
  `period_month`     TINYINT UNSIGNED NOT NULL,
  `period_year`      SMALLINT UNSIGNED NOT NULL,
  `base_salary`      DECIMAL(12,2) NOT NULL,
  `total_incentives` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `gross_earnings`   DECIMAL(12,2) NOT NULL,
  `total_deductions` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_salary`       DECIMAL(12,2) NOT NULL,
  `status`           ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
  `generated_by`     INT UNSIGNED NULL,
  `approved_by`      INT UNSIGNED NULL,
  `approved_at`      DATETIME NULL,
  `paid_at`          DATETIME NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_staff_period` (`staff_id`,`period_year`,`period_month`),
  KEY `idx_payroll_period` (`period_year`,`period_month`),
  KEY `idx_payroll_status` (`status`),
  CONSTRAINT `fk_payroll_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payroll_generated` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payroll_approved` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- payroll_items (frozen line items shown on the payslip) ----------
CREATE TABLE IF NOT EXISTS `payroll_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payroll_id`  INT UNSIGNED NOT NULL,
  `item_type`   ENUM('earning','deduction') NOT NULL,
  `label`       VARCHAR(255) NOT NULL,
  `amount`      DECIMAL(12,2) NOT NULL,
  `source_table` VARCHAR(30) NULL,
  `source_id`   INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_items_payroll` (`payroll_id`),
  CONSTRAINT `fk_items_payroll` FOREIGN KEY (`payroll_id`) REFERENCES `payroll` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- system_settings ----------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key`   VARCHAR(64) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB;

-- ---------- audit_logs ----------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NULL,
  `action`     VARCHAR(64) NOT NULL,
  `entity`     VARCHAR(64) NOT NULL,
  `entity_id`  INT UNSIGNED NULL,
  `details`    TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity`,`entity_id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- Starting configuration (not personal data)
-- ============================================================
INSERT IGNORE INTO `departments` (`name`) VALUES
  ('Human Resources'), ('Finance'), ('Operations'), ('IT');

INSERT IGNORE INTO `leave_types` (`name`, `days_per_year`) VALUES
  ('Casual Leave', 12), ('Sick Leave', 12), ('Earned Leave', 15), ('Unpaid Leave', 0);

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
  ('company_name', 'My Company'),
  ('company_address', ''),
  ('company_email', ''),
  ('company_phone', ''),
  ('currency_symbol', 'Rs.'),
  ('work_start', '09:30'),
  ('work_end', '18:00'),
  ('late_grace_minutes', '10'),
  ('half_day_hours', '4'),
  ('weekly_off_days', '7'),
  ('permission_max_hours', '4'),
  ('payroll_pay_day', '5'),
  ('session_timeout_minutes', '30'),
  ('max_login_attempts', '5');
