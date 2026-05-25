<?php
/**
 * ============================================================
 * WhatsApp CRM - Application Configuration
 * ============================================================
 * All constants, API URLs, keys, feature flags
 * Update these values for your environment
 */

// ============================================================
// APP SETTINGS
// ============================================================
define('APP_NAME', 'WhatsApp CRM');
define('APP_VERSION', '1.0.0');
define('APP_ENV', 'production'); // production | development
define('APP_DEBUG', false);
define('APP_TIMEZONE', 'Asia/Kolkata');

date_default_timezone_set(APP_TIMEZONE);

// ============================================================
// NODE.JS WHATSAPP ENGINE
// ============================================================
define('NODE_API_URL', 'http://YOUR_VPS_IP:3001'); // Your VPS IP:Port
define('NODE_API_KEY', 'your_strong_api_key_here_min_32_chars'); // Must match Node .env

// ============================================================
// GROQ AI API
// ============================================================
define('GROQ_API_KEY', 'gsk_your_groq_api_key_here');
define('GROQ_MODEL', 'llama-3.1-70b-versatile');
define('GROQ_MAX_TOKENS', 800);
define('GROQ_TEMPERATURE', 0.7);

// ============================================================
// HUGGING FACE TOKEN (Only needed if Space is PRIVATE)
// Get from: https://huggingface.co/settings/tokens
// Leave empty if Space is PUBLIC
// ============================================================
define('HF_TOKEN', ''); // e.g., 'hf_xxxxxxxxxxxxxxxxxxxxxxxx'

// ============================================================
// WEBHOOK SECURITY
// ============================================================
define('WEBHOOK_SECRET', 'your_webhook_hmac_secret_here'); // Must match Node .env
define('WEBHOOK_SOURCE_HEADER', 'X-Webhook-Source');
define('WEBHOOK_SIGNATURE_HEADER', 'X-Webhook-Signature');

// ============================================================
// SOCKET.IO (Frontend connects to this)
// ============================================================
define('SOCKET_URL', 'http://YOUR_VPS_IP:3001'); // Same as Node server

// ============================================================
// CAMPAIGN SETTINGS
// ============================================================
define('CAMPAIGN_MIN_DELAY', 120);  // Minimum seconds between sends
define('CAMPAIGN_MAX_DELAY', 300);  // Maximum seconds between sends
define('CAMPAIGN_BATCH_SIZE', 20);  // Max leads per campaign run
define('CAMPAIGN_DAILY_LIMIT', 40); // Max messages per day (safety)

// ============================================================
// OUTREACH RULES
// ============================================================
define('OUTREACH_ONLY_FIRST_MESSAGE', true);
define('STOP_ON_REPLY', true);
define('SKIP_INVALID_NUMBERS', true);

// ============================================================
// USER SERVICE OFFERINGS (for AI prompt)
// ============================================================
define('USER_SERVICES', json_encode([
    'Landing Pages',
    'Business Websites',
    'eCommerce Websites',
    'Custom Web Apps',
    'AI Agents',
    'Automation Systems',
    'Android Apps',
    'Chrome Extensions',
    'Digital Marketing'
]));

// ============================================================
// LANGUAGE MAPPING (State/Region -> Preferred Language)
// ============================================================
define('LANGUAGE_MAP', json_encode([
    'Bihar'         => 'hinglish',
    'Jharkhand'     => 'hinglish',
    'Uttar Pradesh' => 'hinglish',
    'Madhya Pradesh'=> 'hinglish',
    'Rajasthan'     => 'hinglish',
    'Delhi'         => 'hinglish',
    'Haryana'       => 'hinglish',
    'Gujarat'       => 'gujarati_english',
    'Maharashtra'   => 'marathi_english',
    'Tamil Nadu'    => 'english',
    'Karnataka'     => 'english',
    'Kerala'        => 'english',
    'Andhra Pradesh'=> 'english',
    'Telangana'     => 'english',
    'West Bengal'   => 'english',
    'Punjab'        => 'punjabi_english',
    'Odisha'        => 'english'
]));

// ============================================================
// FILE PATHS
// ============================================================
define('LOG_PATH', __DIR__ . '/../logs/');
define('CSV_UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_CSV_SIZE', 5 * 1024 * 1024); // 5MB

// ============================================================
// FEATURE FLAGS
// ============================================================
define('FEATURE_AI_MESSAGES', true);
define('FEATURE_NUMBER_VALIDATION', true);
define('FEATURE_REALTIME_SYNC', true);
define('FEATURE_CSV_UPLOAD', true);
define('FEATURE_MANUAL_REPLY', true);
