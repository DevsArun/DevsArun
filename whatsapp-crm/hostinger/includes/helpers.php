<?php
/**
 * ============================================================
 * WhatsApp CRM - Helper Functions
 * ============================================================
 * Utility functions: sanitize, phone cleaning, logging,
 * address parsing, JSON response, etc.
 */

/**
 * Send JSON response and exit
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Clean and normalize phone number
 * Input: "+91 70046 67347" or "91 70046 67347" or "7004667347"
 * Output: "917004667347"
 */
function cleanPhone(string $phone): ?string {
    // Remove all non-numeric characters
    $clean = preg_replace('/[^0-9]/', '', $phone);

    if (empty($clean) || $clean === '0') {
        return null;
    }

    // If starts with 0, remove it
    if (str_starts_with($clean, '0')) {
        $clean = substr($clean, 1);
    }

    // If 10 digits (Indian local), prepend 91
    if (strlen($clean) === 10) {
        $clean = '91' . $clean;
    }

    // If 11 digits starting with 91, it's likely missing a digit - skip
    // If 12 digits starting with 91, perfect
    if (strlen($clean) === 12 && str_starts_with($clean, '91')) {
        return $clean;
    }

    // If longer (like +91...), trim to 12
    if (strlen($clean) > 12 && str_starts_with($clean, '91')) {
        return substr($clean, 0, 12);
    }

    // Return as-is if valid length
    if (strlen($clean) >= 10 && strlen($clean) <= 15) {
        return $clean;
    }

    return null;
}

/**
 * Check if phone number is valid (not N/A, empty, etc.)
 */
function isValidPhone(string $phone): bool {
    $phone = trim($phone);
    $invalid = ['', 'N/A', 'n/a', 'NA', 'na', '-', 'null', 'none', '0'];
    return !in_array($phone, $invalid, true);
}

/**
 * Sanitize string input
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize for database (no HTML encoding, just trim and clean)
 */
function sanitizeDB(string $input): string {
    return trim(strip_tags($input));
}

/**
 * Parse address to extract locality, city, state
 * Input: "UNIVERSAL TOWER, B-7, More, near DOMINO'S, Kurji, Patna, Bihar 800010, India"
 * Output: ['locality' => 'Kurji', 'city' => 'Patna', 'state' => 'Bihar']
 */
function parseAddress(string $address): array {
    $result = [
        'locality' => null,
        'city' => 'Patna', // Default for this dataset
        'state' => 'Bihar'  // Default for this dataset
    ];

    if (empty($address)) {
        return $result;
    }

    // Known Indian states for detection
    $states = [
        'Bihar', 'Jharkhand', 'Uttar Pradesh', 'Madhya Pradesh', 'Rajasthan',
        'Gujarat', 'Maharashtra', 'Tamil Nadu', 'Karnataka', 'Kerala',
        'Andhra Pradesh', 'Telangana', 'West Bengal', 'Punjab', 'Haryana',
        'Odisha', 'Delhi', 'Uttarakhand', 'Himachal Pradesh', 'Goa',
        'Assam', 'Chhattisgarh'
    ];

    // Extract state
    foreach ($states as $state) {
        if (stripos($address, $state) !== false) {
            $result['state'] = $state;
            break;
        }
    }

    // Split address by comma
    $parts = array_map('trim', explode(',', $address));

    // Try to find city (usually second-to-last or third-to-last part)
    $knownCities = [
        'Patna', 'Mumbai', 'Delhi', 'Bangalore', 'Hyderabad', 'Chennai',
        'Kolkata', 'Pune', 'Ahmedabad', 'Jaipur', 'Lucknow', 'Bhopal',
        'Ranchi', 'Danapur', 'Gaya', 'Bhagalpur', 'Muzaffarpur'
    ];

    foreach ($parts as $part) {
        foreach ($knownCities as $city) {
            if (stripos($part, $city) !== false) {
                $result['city'] = $city;
                break 2;
            }
        }
    }

    // Extract locality (area name)
    // Common Patna localities
    $localities = [
        'Kurji', 'Anandpuri', 'Boring Road', 'Kankarbagh', 'Patliputra',
        'Kadamkuan', 'Rajendra Nagar', 'Bailey Road', 'Ashok Rajpath',
        'Danapur', 'Saguna', 'Khajpura', 'Anisabad', 'Sri Krishna Puri',
        'Rukanpura', 'Punaichak', 'Jakkanpur', 'Rajeev Nagar',
        'New Patliputra Colony', 'Ramkrishan Nagar', 'Hanuman Nagar',
        'Jagdeo Path', 'Raja Bazar', 'Salimpur Ahra', 'Bander Bagicha',
        'Ali Nagar', 'Fraser Road', 'RPS More', 'Sipara', 'Rupaspur',
        'Kaliket Nagar', 'AG Colony', 'Boring Canal', 'Machuatoli'
    ];

    foreach ($localities as $locality) {
        if (stripos($address, $locality) !== false) {
            $result['locality'] = $locality;
            break;
        }
    }

    // If no known locality found, try to extract from parts
    if (empty($result['locality']) && count($parts) >= 3) {
        // Usually locality is 2-3 parts before the city
        for ($i = count($parts) - 3; $i >= 0; $i--) {
            $candidate = trim($parts[$i]);
            // Skip if it's a building/shop reference
            if (strlen($candidate) > 3 && strlen($candidate) < 40 &&
                !preg_match('/^(shop|floor|building|complex|tower|plot|no|near)/i', $candidate)) {
                $result['locality'] = $candidate;
                break;
            }
        }
    }

    return $result;
}

/**
 * Determine website status from URL string
 */
function parseWebsiteStatus(string $website): array {
    $website = trim($website);
    $noWebsite = ['No Website Available', 'N/A', '', 'n/a', 'none', 'null', '-'];

    if (in_array($website, $noWebsite, true)) {
        return ['url' => null, 'status' => 'no_website'];
    }

    // Check if it's a valid URL-ish string
    if (filter_var($website, FILTER_VALIDATE_URL) || preg_match('/^https?:\/\//', $website)) {
        // Check if it's just a WhatsApp link (not a real website)
        if (stripos($website, 'wa.me') !== false || stripos($website, 'whatsapp.com') !== false) {
            return ['url' => $website, 'status' => 'no_website'];
        }
        // Google Sites count as basic presence
        if (stripos($website, 'sites.google.com') !== false) {
            return ['url' => $website, 'status' => 'has_website'];
        }
        return ['url' => $website, 'status' => 'has_website'];
    }

    return ['url' => null, 'status' => 'unknown'];
}

/**
 * Get language preference based on state/region
 */
function getLanguagePreference(string $state): string {
    $map = json_decode(LANGUAGE_MAP, true);
    return $map[$state] ?? 'english';
}

/**
 * Determine pitch type based on website status
 */
function getPitchType(string $websiteStatus): string {
    return ($websiteStatus === 'has_website') ? 'A' : 'B';
}

/**
 * Write to log file
 */
function writeLog(string $logFile, string $message, string $level = 'INFO'): void {
    $logPath = defined('LOG_PATH') ? LOG_PATH : __DIR__ . '/../logs/';

    if (!is_dir($logPath)) {
        mkdir($logPath, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($logPath . $logFile, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Log campaign activity
 */
function logCampaign(string $message, string $level = 'INFO'): void {
    writeLog('campaign.log', $message, $level);
}

/**
 * Log webhook activity
 */
function logWebhook(string $message, string $level = 'INFO'): void {
    writeLog('webhook.log', $message, $level);
}

/**
 * Log import activity
 */
function logImport(string $message, string $level = 'INFO'): void {
    writeLog('import.log', $message, $level);
}

/**
 * Get random delay between min and max (for anti-ban)
 */
function getRandomDelay(): int {
    $min = defined('CAMPAIGN_MIN_DELAY') ? CAMPAIGN_MIN_DELAY : 120;
    $max = defined('CAMPAIGN_MAX_DELAY') ? CAMPAIGN_MAX_DELAY : 300;
    return random_int($min, $max);
}

/**
 * Format timestamp for display
 */
function formatTime(?string $datetime): string {
    if (empty($datetime)) return 'Never';
    $ts = strtotime($datetime);
    $diff = time() - $ts;

    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';

    return date('M j, Y', $ts);
}

/**
 * Truncate text with ellipsis
 */
function truncateText(string $text, int $length = 60): string {
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . '...';
}

/**
 * Get request body as JSON
 */
function getRequestBody(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Validate required fields in request
 */
function validateRequired(array $data, array $fields): ?string {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            return "Missing required field: {$field}";
        }
    }
    return null;
}

/**
 * Generate simple unique ID
 */
function generateId(): string {
    return bin2hex(random_bytes(8));
}

/**
 * Check if request is AJAX
 */
function isAjax(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * CORS headers for API endpoints
 */
function setCorsHeaders(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-Key');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
