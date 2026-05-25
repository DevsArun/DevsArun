<?php
/**
 * WaLead CRM - Webhook Receiver
 * Receives inbound messages from Node.js server
 * NO signature verification (disabled for HF Spaces reliability)
 * Full debug logging enabled
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Set response headers
header('Content-Type: application/json');

// Log all incoming webhooks
$rawBody = file_get_contents('php://input');
$timestamp = date('Y-m-d H:i:s');

debugWebhook("=== WEBHOOK RECEIVED at {$timestamp} ===");
debugWebhook("Method: " . $_SERVER['REQUEST_METHOD']);
debugWebhook("Raw body: " . $rawBody);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    debugWebhook("Rejected: Not POST");
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse payload
$payload = json_decode($rawBody, true);
if (!$payload) {
    debugWebhook("Rejected: Invalid JSON");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

debugWebhook("Parsed payload: " . print_r($payload, true));

// NO SIGNATURE VERIFICATION - disabled for reliability

// Process based on event type
$event = $payload['event'] ?? 'unknown';

switch ($event) {
    case 'message_received':
        handleInboundMessage($payload);
        break;
    default:
        debugWebhook("Unknown event: {$event}");
        echo json_encode(['status' => 'ignored', 'event' => $event]);
}

exit;


/**
 * Handle inbound message from WhatsApp
 */
function handleInboundMessage($payload) {
    $from = $payload['from'] ?? '';
    $resolvedPhone = $payload['resolved_phone'] ?? '';
    $body = $payload['body'] ?? '';
    $messageId = $payload['message_id'] ?? '';
    $incomingTimestamp = $payload['timestamp'] ?? date('Y-m-d H:i:s');

    debugWebhook("Processing inbound message:");
    debugWebhook("  From: {$from}");
    debugWebhook("  Resolved Phone: {$resolvedPhone}");
    debugWebhook("  Body: {$body}");
    debugWebhook("  Message ID: {$messageId}");

    // Determine phone number to use
    $phone = $resolvedPhone;
    if (!$phone) {
        // Try to extract from @c.us format
        if (strpos($from, '@c.us') !== false) {
            $phone = str_replace('@c.us', '', $from);
        } else {
            debugWebhook("WARNING: Could not resolve phone number for {$from}");
            $phone = $from; // Store LID as fallback
        }
    }

    debugWebhook("  Final phone used: {$phone}");

    // Find matching lead
    $lead = findLeadByPhone($phone);

    if ($lead) {
        debugWebhook("  Matched lead ID: {$lead['id']} ({$lead['business_name']})");

        // Store message
        db()->insert(
            "INSERT INTO messages (lead_id, phone, body, direction, status, message_id, created_at) 
             VALUES (?, ?, ?, 'inbound', 'received', ?, NOW())",
            [$lead['id'], $phone, $body, $messageId]
        );

        // Update lead status to replied
        db()->update(
            "UPDATE leads SET status = 'replied', last_reply = NOW(), updated_at = NOW() WHERE id = ?",
            [$lead['id']]
        );

        debugWebhook("  Message stored and lead updated to 'replied'");

        echo json_encode([
            'status' => 'processed',
            'lead_id' => $lead['id'],
            'phone' => $phone
        ]);
    } else {
        debugWebhook("  No matching lead found for phone: {$phone}");

        // Store as unmatched message
        db()->insert(
            "INSERT INTO messages (lead_id, phone, body, direction, status, message_id, created_at) 
             VALUES (0, ?, ?, 'inbound', 'unmatched', ?, NOW())",
            [$phone, $body, $messageId]
        );

        echo json_encode([
            'status' => 'unmatched',
            'phone' => $phone
        ]);
    }
}

/**
 * Find lead by phone number (flexible matching)
 */
function findLeadByPhone($phone) {
    // Clean phone number
    $cleaned = preg_replace('/[^0-9]/', '', $phone);

    // Try exact match first
    $lead = db()->fetchOne("SELECT * FROM leads WHERE REPLACE(REPLACE(phone, ' ', ''), '-', '') = ?", [$cleaned]);
    if ($lead) return $lead;

    // Try without country code (last 10 digits)
    if (strlen($cleaned) > 10) {
        $last10 = substr($cleaned, -10);
        $lead = db()->fetchOne(
            "SELECT * FROM leads WHERE RIGHT(REPLACE(REPLACE(phone, ' ', ''), '-', ''), 10) = ?",
            [$last10]
        );
        if ($lead) return $lead;
    }

    // Try with country code 91 prefix
    $with91 = '91' . substr($cleaned, -10);
    $lead = db()->fetchOne(
        "SELECT * FROM leads WHERE REPLACE(REPLACE(phone, ' ', ''), '-', '') = ?",
        [$with91]
    );
    if ($lead) return $lead;

    // LIKE match as last resort
    if (strlen($cleaned) >= 10) {
        $last10 = substr($cleaned, -10);
        $lead = db()->fetchOne(
            "SELECT * FROM leads WHERE phone LIKE ?",
            ['%' . $last10 . '%']
        );
        if ($lead) return $lead;
    }

    return null;
}

/**
 * Debug logging
 */
function debugWebhook($msg) {
    if (!DEBUG_MODE) return;
    $logFile = __DIR__ . '/webhook_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] {$msg}\n", FILE_APPEND | LOCK_EX);
}
