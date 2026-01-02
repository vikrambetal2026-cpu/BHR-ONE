-- Fresh Setup Script (Overwrite / Recreate DB Objects)
-- MySQL 5.7+ / 8.0+
-- NOTE: Replace `job_portal` if you use a different database name.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Optional: create & use database
CREATE DATABASE IF NOT EXISTS `job_portal` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `job_portal`;

DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `search_keywords`;
DROP TABLE IF EXISTS `unmasked_contacts`;
DROP TABLE IF EXISTS `token_transactions`;
DROP TABLE IF EXISTS `employer_tokens`;
DROP TABLE IF EXISTS `job_applications`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `candidate_freetext`;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================
-- Core tables
-- =========================

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `role` ENUM('employer', 'jobseeker') NOT NULL,
  `company_name` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `jobs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employer_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `location` VARCHAR(255) NOT NULL,
  `job_type` ENUM('full-time', 'part-time', 'contract', 'internship') NOT NULL,
  `experience_min` INT DEFAULT 0,
  `experience_max` INT DEFAULT 50,
  `salary_min` INT DEFAULT NULL,
  `salary_max` INT DEFAULT NULL,
  `skills` TEXT DEFAULT NULL,
  `status` ENUM('active', 'inactive', 'closed') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_employer (employer_id),
  INDEX idx_status (status),
  INDEX idx_location (location),
  INDEX idx_job_type (job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `job_applications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `job_id` INT NOT NULL,
  `jobseeker_id` INT NOT NULL,
  `resume_text` TEXT DEFAULT NULL,
  `cover_letter` TEXT DEFAULT NULL,
  `status` ENUM('applied', 'shortlisted', 'rejected', 'hired') DEFAULT 'applied',
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  FOREIGN KEY (jobseeker_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_application (job_id, jobseeker_id),
  INDEX idx_job (job_id),
  INDEX idx_jobseeker (jobseeker_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================
-- Employer tokens & actions
-- =========================

CREATE TABLE IF NOT EXISTS `employer_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token_balance` INT NOT NULL DEFAULT 0,
  `free_unmasks_today` INT NOT NULL DEFAULT 0,
  `free_downloads_today` INT NOT NULL DEFAULT 0,
  `last_reset_date` DATE DEFAULT NULL,
  `total_spent` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `token_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `transaction_type` ENUM('search', 'unmask_email', 'unmask_mobile', 'download_profile', 'topup', 'initial') NOT NULL,
  `tokens` INT NOT NULL,
  `candidate_id` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user (user_id),
  INDEX idx_type (transaction_type),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `unmasked_contacts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `candidate_id` VARCHAR(100) NOT NULL,
  `email_unmasked` TINYINT(1) DEFAULT 0,
  `mobile_unmasked` TINYINT(1) DEFAULT 0,
  `profile_downloaded` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_candidate (user_id, candidate_id),
  INDEX idx_candidate (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `search_keywords` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `keyword` VARCHAR(100) NOT NULL,
  `search_mode` ENUM('ai', 'normal') NOT NULL,
  `frequency` INT DEFAULT 1,
  `last_used` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_keyword_mode (keyword, search_mode),
  INDEX idx_mode (search_mode),
  INDEX idx_frequency (frequency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `razorpay_payment_id` VARCHAR(255) DEFAULT NULL,
  `razorpay_order_id` VARCHAR(255) DEFAULT NULL,
  `amount` INT NOT NULL,
  `tokens_added` INT NOT NULL,
  `status` ENUM('created', 'paid', 'failed') DEFAULT 'created',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `metadata` JSON NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user (user_id),
  INDEX idx_status (status),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================
-- Candidate dataset (external / legacy)
-- =========================
-- This table powers the AI + normal candidate search.
-- Populate it with your candidate profiles (one row per candidate).
CREATE TABLE IF NOT EXISTS `candidate_freetext` (
  `candidate_id` VARCHAR(100) NOT NULL,
  `free_text` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`candidate_id`),
  FULLTEXT KEY `ft_free_text` (`free_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reset checks
SET FOREIGN_KEY_CHECKS = 1;
