<?php
/**
 * WaLead CRM - Configuration File
 * All settings controllable from here
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'walead_crm');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Node.js Server (Hugging Face Space)
define('NODE_SERVER_URL', 'https://itschol0408-whatsapp-crm-engine.hf.space');

// Groq AI API Key (for personalized messages)
define('GROQ_API_KEY', 'your_groq_api_key_here');
define('GROQ_MODEL', 'llama-3.1-70b-versatile');

// Webhook Configuration
define('WEBHOOK_SECRET', ''); // Disabled for HF Spaces reliability
define('VERIFY_SIGNATURE', false); // DISABLED - no signature verification

// Campaign Settings
define('MIN_DELAY_SECONDS', 120); // Anti-ban: minimum delay between sends
define('MAX_DELAY_SECONDS', 300); // Anti-ban: maximum delay between sends
define('MAX_MESSAGES_PER_DAY', 50); // Safety limit

// Timezone
date_default_timezone_set('Asia/Kolkata');

// App Settings
define('APP_NAME', 'WaLead CRM');
define('APP_VERSION', '2.0');
define('DEBUG_MODE', true);

// CSV Format columns (0-indexed)
define('CSV_COL_BUSINESS_NAME', 0);
define('CSV_COL_ADDRESS', 1);
define('CSV_COL_PHONE', 2);
define('CSV_COL_WEBSITE', 3);
define('CSV_COL_RATING', 4);
define('CSV_COL_REVIEWS', 5);
define('CSV_COL_STATUS', 6);
