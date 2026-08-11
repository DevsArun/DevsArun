-- ============================================================
-- WhatsApp CRM - Database Schema
-- Production Ready | MySQL 5.7+
-- ============================================================

CREATE DATABASE IF NOT EXISTS `whatsapp_crm` 
  DEFAULT CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `whatsapp_crm`;

-- ============================================================
-- LEADS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `leads` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_name` VARCHAR(255) NOT NULL,
  `address` TEXT DEFAULT NULL,
  `locality` VARCHAR(150) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT 'Patna',
  `state` VARCHAR(100) DEFAULT 'Bihar',
  `phone_raw` VARCHAR(50) DEFAULT NULL,
  `phone_clean` VARCHAR(20) DEFAULT NULL,
  `country_code` VARCHAR(5) DEFAULT '91',
  `website_url` VARCHAR(500) DEFAULT NULL,
  `website_status` ENUM('has_website', 'no_website', 'unknown') DEFAULT 'unknown',
  `rating` DECIMAL(2,1) DEFAULT NULL,
  `review_count` INT UNSIGNED DEFAULT 0,
  `business_status` VARCHAR(50) DEFAULT 'Open',
  `pitch_type` ENUM('A', 'B') DEFAULT NULL COMMENT 'A=has website, B=no website',
  `language_preference` VARCHAR(50) DEFAULT 'hinglish',
  `whatsapp_status` ENUM('pending', 'valid', 'invalid', 'unknown') DEFAULT 'pending',
  `outreach_status` ENUM('pending', 'queued', 'sent', 'replied', 'failed', 'skipped') DEFAULT 'pending',
  `outreach_message` TEXT DEFAULT NULL,
  `last_contacted_at` DATETIME DEFAULT NULL,
  `reply_received_at` DATETIME DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone_clean` (`phone_clean`),
  INDEX `idx_outreach_status` (`outreach_status`),
  INDEX `idx_whatsapp_status` (`whatsapp_status`),
  INDEX `idx_pitch_type` (`pitch_type`),
  INDEX `idx_website_status` (`website_status`),
  INDEX `idx_is_active` (`is_active`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MESSAGES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` INT UNSIGNED NOT NULL,
  `sender` ENUM('system', 'user', 'lead') NOT NULL DEFAULT 'system',
  `direction` ENUM('outbound', 'inbound') NOT NULL,
  `message_text` TEXT NOT NULL,
  `wa_message_id` VARCHAR(100) DEFAULT NULL,
  `message_type` VARCHAR(30) DEFAULT 'text',
  `is_read` TINYINT(1) DEFAULT 0,
  `is_first_outreach` TINYINT(1) DEFAULT 0,
  `delivered_at` DATETIME DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_lead_id` (`lead_id`),
  INDEX `idx_direction` (`direction`),
  INDEX `idx_is_read` (`is_read`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_wa_message_id` (`wa_message_id`),
  CONSTRAINT `fk_messages_lead` FOREIGN KEY (`lead_id`) 
    REFERENCES `leads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CAMPAIGNS TABLE (Track campaign runs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_name` VARCHAR(200) DEFAULT NULL,
  `total_leads` INT UNSIGNED DEFAULT 0,
  `sent_count` INT UNSIGNED DEFAULT 0,
  `failed_count` INT UNSIGNED DEFAULT 0,
  `skipped_count` INT UNSIGNED DEFAULT 0,
  `status` ENUM('running', 'paused', 'completed', 'stopped') DEFAULT 'running',
  `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ACTIVITY LOG TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_lead_id` (`lead_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
