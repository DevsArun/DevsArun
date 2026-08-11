<?php
/**
 * ============================================================
 * WhatsApp CRM - Authentication & Security
 * ============================================================
 * Webhook HMAC verification, API auth helpers
 */

/**
 * Verify webhook signature from Node.js
 * Node sends: X-Webhook-Signature: HMAC-SHA256(payload, secret)
 */
function verifyWebhookSignature(string $payload): bool {
    $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
    $source = $_SERVER['HTTP_X_WEBHOOK_SOURCE'] ?? '';

    if (empty($signature) || $source !== 'wa-engine') {
        return false;
    }

    $expectedSignature = hash_hmac('sha256', $payload, WEBHOOK_SECRET);

    return hash_equals($expectedSignature, $signature);
}

/**
 * Verify API key for dashboard API endpoints
 * Checks X-API-Key header or api_key parameter
 */
function verifyAPIKey(): bool {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';

    if (empty($apiKey)) {
        return false;
    }

    return hash_equals(NODE_API_KEY, $apiKey);
}

/**
 * Simple session-based auth check for dashboard
 * For production, implement proper login system
 */
function requireDashboardAuth(): void {
    // For now, using simple session check
    // In production, implement proper login
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Simple auth bypass for development
    // REMOVE THIS IN PRODUCTION and implement proper login
    if (APP_ENV === 'development') {
        return;
    }

    if (!isset($_SESSION['crm_authenticated']) || $_SESSION['crm_authenticated'] !== true) {
        if (isAjax()) {
            jsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
        } else {
            header('Location: /login.php');
            exit;
        }
    }
}

/**
 * Rate limiting for API endpoints (simple file-based)
 */
function checkRateLimit(string $identifier, int $maxRequests = 60, int $windowSeconds = 60): bool {
    $rateLimitDir = defined('LOG_PATH') ? LOG_PATH . 'ratelimit/' : '/tmp/crm_ratelimit/';

    if (!is_dir($rateLimitDir)) {
        mkdir($rateLimitDir, 0755, true);
    }

    $file = $rateLimitDir . md5($identifier) . '.json';

    $data = ['requests' => [], 'blocked_until' => 0];

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
    }

    // Check if currently blocked
    if ($data['blocked_until'] > time()) {
        return false;
    }

    $now = time();
    $windowStart = $now - $windowSeconds;

    // Remove old entries
    $data['requests'] = array_filter($data['requests'], fn($ts) => $ts > $windowStart);

    // Check count
    if (count($data['requests']) >= $maxRequests) {
        $data['blocked_until'] = $now + $windowSeconds;
        file_put_contents($file, json_encode($data));
        return false;
    }

    // Add current request
    $data['requests'][] = $now;
    file_put_contents($file, json_encode($data));

    return true;
}

/**
 * Get client IP address
 */
function getClientIP(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // Handle comma-separated IPs (X-Forwarded-For)
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

/**
 * Generate CSRF token
 */
function generateCSRFToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}
