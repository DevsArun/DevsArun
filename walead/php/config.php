<?php
/**
 * WaLead CRM - Configuration File
 * Update these values for your Hostinger setup
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'walead_crm');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Node.js Server (Hugging Face Space)
define('NODE_SERVER_URL', 'https://itschol0408-whatsapp-crm-engine.hf.space');

// Groq AI Configuration
define('GROQ_API_KEY', '');
define('GROQ_MODEL', 'llama-3.1-70b-versatile');

// Campaign Settings
define('MIN_DELAY', 120);
define('MAX_DELAY', 300);
define('DAILY_LIMIT', 50);

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Database Connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}
?>
