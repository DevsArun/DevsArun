-- ============================================================
-- WaLead CRM - Database Schema
-- MySQL Database for Hostinger Shared Hosting
-- Run this SQL to set up the database
-- ============================================================

CREATE DATABASE IF NOT EXISTS walead_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE walead_crm;

-- ============ LEADS TABLE ============
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(255) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    phone VARCHAR(20) NOT NULL,
    website VARCHAR(500) DEFAULT NULL,
    rating DECIMAL(2,1) DEFAULT NULL,
    reviews INT DEFAULT 0,
    status ENUM('pending', 'sent', 'replied', 'failed', 'blocked') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    last_contacted DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_phone (phone),
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ MESSAGES TABLE ============
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    body TEXT NOT NULL,
    message_id VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'sent', 'delivered', 'read', 'received', 'failed') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lead_id (lead_id),
    INDEX idx_phone (phone),
    INDEX idx_direction (direction),
    INDEX idx_created (created_at),
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ CAMPAIGNS TABLE ============
CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template TEXT DEFAULT NULL,
    use_ai TINYINT(1) DEFAULT 0,
    filter_type VARCHAR(50) DEFAULT 'pending',
    total_leads INT DEFAULT 0,
    sent_count INT DEFAULT 0,
    status ENUM('running', 'paused', 'completed', 'stopped', 'failed') DEFAULT 'running',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ CAMPAIGN QUEUE TABLE ============
CREATE TABLE IF NOT EXISTS campaign_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    lead_id INT NOT NULL,
    status ENUM('pending', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_campaign (campaign_id),
    INDEX idx_status (status),
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ SETTINGS TABLE ============
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ WEBHOOK LOGS TABLE ============
CREATE TABLE IF NOT EXISTS webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    payload TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event),
    INDEX idx_phone (phone),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ DEFAULT SETTINGS ============
INSERT INTO settings (setting_key, setting_value) VALUES
('node_server_url', 'https://itschol0408-whatsapp-crm-engine.hf.space'),
('webhook_url', ''),
('groq_api_key', ''),
('groq_model', 'llama-3.1-70b-versatile'),
('min_delay', '120'),
('max_delay', '300'),
('daily_limit', '50'),
('company_name', 'WaLead CRM'),
('sender_name', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============ SAMPLE DATA (Optional - Patna Toy Shops format) ============
-- INSERT INTO leads (business_name, address, phone, website, rating, reviews, status) VALUES
-- ('Sample Toy Shop', 'Boring Road, Patna', '919876543210', 'https://example.com', 4.2, 45, 'pending');
