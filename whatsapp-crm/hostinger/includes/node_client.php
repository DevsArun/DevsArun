<?php
/**
 * ============================================================
 * WhatsApp CRM - Node.js API Client
 * ============================================================
 * cURL wrapper for communicating with Node.js WhatsApp Engine
 */

/**
 * Send message via Node.js WhatsApp Engine
 * 
 * @param string $phone Clean phone number (e.g., "917004667347")
 * @param string $message Message text to send
 * @param int|null $leadId Lead ID for tracking
 * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
 */
function nodeSendMessage(string $phone, string $message, ?int $leadId = null): array {
    $payload = [
        'phone' => $phone,
        'message' => $message,
        'lead_id' => $leadId
    ];

    return nodeAPIRequest('POST', '/send-message', $payload);
}

/**
 * Check if a phone number is registered on WhatsApp
 * 
 * @param string $phone Clean phone number
 * @return array ['success' => bool, 'is_registered' => bool, 'error' => string|null]
 */
function nodeCheckNumber(string $phone): array {
    $payload = ['phone' => $phone];
    return nodeAPIRequest('POST', '/check-number', $payload);
}

/**
 * Batch check multiple phone numbers
 * 
 * @param array $phones Array of clean phone numbers (max 10)
 * @return array ['success' => bool, 'results' => array]
 */
function nodeCheckNumbersBatch(array $phones): array {
    $payload = ['phones' => array_slice($phones, 0, 10)];
    return nodeAPIRequest('POST', '/check-numbers-batch', $payload);
}

/**
 * Get WhatsApp engine health status
 * 
 * @return array ['success' => bool, 'status' => string, 'whatsapp' => string]
 */
function nodeGetHealth(): array {
    return nodeAPIRequest('GET', '/health');
}

/**
 * Get WhatsApp connection status
 * 
 * @return array ['success' => bool, 'status' => string, 'qr' => string|null]
 */
function nodeGetWAStatus(): array {
    return nodeAPIRequest('GET', '/wa-status');
}

/**
 * Core API request to Node.js engine
 * 
 * @param string $method HTTP method (GET, POST)
 * @param string $endpoint API endpoint path
 * @param array|null $payload Request body for POST
 * @param int $timeout Request timeout in seconds
 * @return array Response array with success, data, error
 */
function nodeAPIRequest(string $method, string $endpoint, ?array $payload = null, int $timeout = 30): array {
    $url = rtrim(NODE_API_URL, '/') . $endpoint;

    $ch = curl_init();

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-Key: ' . NODE_API_KEY
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false, // Set true in production with proper cert
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // cURL error
    if ($response === false) {
        logCampaign("Node API error [{$method} {$endpoint}]: {$curlError}", 'ERROR');
        return [
            'success' => false,
            'data' => null,
            'error' => "Connection failed: {$curlError}",
            'http_code' => 0
        ];
    }

    // Parse response
    $data = json_decode($response, true);

    // HTTP error
    if ($httpCode >= 400) {
        $errorMsg = $data['error'] ?? "HTTP {$httpCode}";
        logCampaign("Node API HTTP {$httpCode} [{$method} {$endpoint}]: {$errorMsg}", 'ERROR');
        return [
            'success' => false,
            'data' => $data,
            'error' => $errorMsg,
            'http_code' => $httpCode
        ];
    }

    return [
        'success' => true,
        'data' => $data,
        'error' => null,
        'http_code' => $httpCode
    ];
}

/**
 * Check if Node.js engine is online and WhatsApp is ready
 * 
 * @return array ['online' => bool, 'wa_ready' => bool, 'status' => string]
 */
function isNodeReady(): array {
    $health = nodeGetHealth();

    if (!$health['success']) {
        return ['online' => false, 'wa_ready' => false, 'status' => 'offline'];
    }

    $waStatus = $health['data']['whatsapp'] ?? 'unknown';

    return [
        'online' => true,
        'wa_ready' => ($waStatus === 'ready'),
        'status' => $waStatus
    ];
}
