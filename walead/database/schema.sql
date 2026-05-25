-- ============================================================
-- WaLead CRM - Database Schema
-- MySQL on Hostinger shared hosting
-- Run this SQL to set up the database
-- ============================================================

CREATE DATABASE IF NOT EXISTS walead_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE walead_crm;

-- ============================================================
-- LEADS TABLE
-- Stores all business leads from CSV imports
-- ============================================================
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(255) NOT NULL,
    address TEXT DEFAULT NULL,
    phone VARCHAR(50) NOT NULL,
    website VARCHAR(500) DEFAULT NULL,
    rating DECIMAL(2,1) DEFAULT 0.0,
    reviews INT DEFAULT 0,
    status ENUM('pending', 'sent', 'replied', 'failed', 'opted_out') DEFAULT 'pending',
    last_contacted DATETIME DEFAULT NULL,
    last_reply DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_phone (phone),
    INDEX idx_website (website(191)),
    INDEX idx_created (created_at),
    INDEX idx_last_contacted (last_contacted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MESSAGES TABLE
-- Stores all inbound and outbound messages
-- ============================================================
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL DEFAULT 0,
    phone VARCHAR(50) NOT NULL,
    body TEXT NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    status ENUM('queued', 'sent', 'delivered', 'read', 'received', 'failed', 'unmatched') DEFAULT 'queued',
    message_id VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lead_id (lead_id),
    INDEX idx_phone (phone),
    INDEX idx_direction (direction),
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_lead_created (lead_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CAMPAIGNS TABLE
-- Tracks campaign runs
-- ============================================================
CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) DEFAULT NULL,
    message_template TEXT DEFAULT NULL,
    use_ai TINYINT(1) DEFAULT 0,
    filter_type VARCHAR(50) DEFAULT 'pending',
    total_leads INT DEFAULT 0,
    sent_count INT DEFAULT 0,
    reply_count INT DEFAULT 0,
    status ENUM('pending', 'running', 'completed', 'stopped', 'failed') DEFAULT 'pending',
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SETTINGS TABLE
-- Key-value store for app settings
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEFAULT SETTINGS
-- ============================================================
INSERT INTO settings (setting_key, setting_value) VALUES
('node_server_url', 'https://itschol0408-whatsapp-crm-engine.hf.space'),
('groq_api_key', ''),
('groq_model', 'llama-3.1-70b-versatile'),
('webhook_url', ''),
('min_delay_seconds', '120'),
('max_delay_seconds', '300'),
('max_messages_per_day', '50'),
('app_name', 'WaLead CRM'),
('timezone', 'Asia/Kolkata')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================
-- SAMPLE DATA (Patna Toy Shops format)
-- Uncomment to insert test data
-- ============================================================
-- INSERT INTO leads (business_name, address, phone, website, rating, reviews, status) VALUES
-- ('Toy World Patna', 'Fraser Road, Patna, Bihar 800001', '917004667347', 'https://toyworld.in', 4.2, 156, 'pending'),
-- ('Kids Paradise', 'Boring Road, Patna, Bihar 800001', '919876543210', '', 3.8, 89, 'pending'),
-- ('Fun Zone Toys', 'Exhibition Road, Patna, Bihar 800001', '918765432109', 'https://funzonetoys.com', 4.5, 234, 'pending');
