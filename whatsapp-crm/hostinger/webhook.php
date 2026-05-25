<?php
/**
 * ============================================================
 * WhatsApp CRM - Webhook Receiver (FIXED)
 * ============================================================
 * Receives events from Node.js WhatsApp Engine
 * 
 * FIXES:
 * - Duplicate message prevention (outbound stored by campaign, skip webhook dupe)
 * - Proper inbound reply storage
 * - Better phone matching
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Get raw payload
$rawPayload = file_get_contents('php://input');

if (empty($rawPayload)) {
    logWebhook("Empty payload received", 'WARN');
    http_response_code(400);
    exit('Empty payload');
}

// Verify signature
if (!verifyWebhookSignature($rawPayload)) {
    logWebhook("Invalid webhook signature from " . getClientIP(), 'ERROR');
    http_response_code(401);
    exit('Unauthorized');
}

// Parse payload
$data = json_decode($rawPayload, true);

if (!$data || !isset($data['event'])) {
    logWebhook("Invalid JSON payload", 'ERROR');
    http_response_code(400);
    exit('Invalid payload');
}

$event = $data['event'];
$phone = $data['phone'] ?? '';
$message = $data['message'] ?? '';
$waMessageId = $data['wa_message_id'] ?? '';
$timestamp = $data['timestamp'] ?? time();
$leadIdFromPayload = $data['lead_id'] ?? null;

logWebhook("Event: {$event} | Phone: {$phone} | WA_ID: {$waMessageId}");

try {
    switch ($event) {

        // ── INBOUND MESSAGE (Lead replied) ──
        case 'message_received':
            handleInboundMessage($phone, $message, $waMessageId, $timestamp, $data);
            break;

        // ── OUTBOUND MESSAGE CONFIRMATION ──
        // campaign.php ALREADY stores the message, so we only update wa_message_id
        case 'message_sent':
            handleOutboundConfirmation($phone, $message, $waMessageId, $leadIdFromPayload, $timestamp);
            break;

        // ── DELIVERY ACK ──
        case 'message_sent_ack':
            logWebhook("Delivery ACK for {$phone}");
            break;

        default:
            logWebhook("Unknown event: {$event}", 'WARN');
            break;
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok']);

} catch (Exception $e) {
    logWebhook("Webhook processing error: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

// ============================================================
// HANDLER FUNCTIONS
// ============================================================

function handleInboundMessage(string $phone, string $message, string $waMessageId, $timestamp, array $data): void {
    if (empty($phone) || empty($message)) {
        logWebhook("Inbound missing phone or message", 'WARN');
        return;
    }

    // Find lead by phone (try exact match and with/without 91 prefix)
    $lead = dbQueryOne(
        "SELECT id, business_name, outreach_status FROM leads WHERE phone_clean = :phone",
        [':phone' => $phone]
    );

    // Also try with 91 prefix if not found
    if (!$lead && !str_starts_with($phone, '91')) {
        $lead = dbQueryOne(
            "SELECT id, business_name, outreach_status FROM leads WHERE phone_clean = :phone",
            [':phone' => '91' . $phone]
        );
    }

    if (!$lead) {
        logWebhook("Inbound from unknown number: {$phone} - skipping");
        return;
    }

    $leadId = $lead['id'];

    // DUPLICATE CHECK - by wa_message_id
    if (!empty($waMessageId)) {
        $existing = dbQueryOne(
            "SELECT id FROM messages WHERE wa_message_id = :wa_id",
            [':wa_id' => $waMessageId]
        );
        if ($existing) {
            logWebhook("Duplicate inbound skipped: {$waMessageId}");
            return;
        }
    }

    // Store inbound message
    $createdAt = date('Y-m-d H:i:s', is_numeric($timestamp) ? (int)$timestamp : time());

    dbInsert(
        "INSERT INTO messages (lead_id, sender, direction, message_text, wa_message_id, message_type, is_read, is_first_outreach, created_at)
         VALUES (:lead_id, 'lead', 'inbound', :msg, :wa_id, 'text', 0, 0, :created_at)",
        [
            ':lead_id'    => $leadId,
            ':msg'        => $message,
            ':wa_id'      => $waMessageId,
            ':created_at' => $createdAt
        ]
    );

    // Mark lead as replied - STOP automation
    dbExecute(
        "UPDATE leads SET 
            outreach_status = 'replied', 
            reply_received_at = NOW(), 
            updated_at = NOW() 
         WHERE id = :id AND outreach_status != 'replied'",
        [':id' => $leadId]
    );

    // Log activity
    dbInsert(
        "INSERT INTO activity_log (lead_id, action, details, created_at)
         VALUES (:lead_id, 'lead_replied', :details, NOW())",
        [
            ':lead_id' => $leadId,
            ':details' => "Lead replied: " . substr($message, 0, 100)
        ]
    );

    logWebhook("✓ INBOUND stored for lead #{$leadId} ({$lead['business_name']})");
}

/**
 * Handle outbound confirmation - DO NOT create new record
 * campaign.php already stored the message. Only update wa_message_id if needed.
 */
function handleOutboundConfirmation(string $phone, string $message, string $waMessageId, ?int $leadId, $timestamp): void {
    if (empty($phone)) return;

    // FIRST: Check if this wa_message_id already exists (avoid any duplicate)
    if (!empty($waMessageId)) {
        $dup = dbQueryOne(
            "SELECT id FROM messages WHERE wa_message_id = :wa_id",
            [':wa_id' => $waMessageId]
        );
        if ($dup) {
            logWebhook("Outbound already recorded (wa_id exists): {$waMessageId}");
            return;
        }
    }

    // Find the message that campaign.php stored (has NULL wa_message_id)
    if ($leadId && !empty($waMessageId)) {
        $existing = dbQueryOne(
            "SELECT id FROM messages WHERE lead_id = :lead_id AND direction = 'outbound' AND (wa_message_id IS NULL OR wa_message_id = '') ORDER BY created_at DESC LIMIT 1",
            [':lead_id' => $leadId]
        );

        if ($existing) {
            // Just update the wa_message_id — DO NOT insert new record
            dbExecute(
                "UPDATE messages SET wa_message_id = :wa_id, delivered_at = NOW() WHERE id = :id",
                [':wa_id' => $waMessageId, ':id' => $existing['id']]
            );
            logWebhook("Updated wa_message_id for existing message #{$existing['id']}");
            return;
        }
    }

    // If no lead_id provided, find by phone
    if (!$leadId) {
        $lead = dbQueryOne(
            "SELECT id FROM leads WHERE phone_clean = :phone",
            [':phone' => $phone]
        );
        $leadId = $lead['id'] ?? null;
    }

    if (!$leadId) {
        logWebhook("Outbound confirmation for unknown lead: {$phone} - skipping");
        return;
    }

    // Check if campaign already stored a message for this lead recently (within 60 sec)
    $recentMsg = dbQueryOne(
        "SELECT id FROM messages WHERE lead_id = :lead_id AND direction = 'outbound' AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) ORDER BY created_at DESC LIMIT 1",
        [':lead_id' => $leadId]
    );

    if ($recentMsg) {
        // Campaign already stored it, just update wa_id
        dbExecute(
            "UPDATE messages SET wa_message_id = :wa_id, delivered_at = NOW() WHERE id = :id AND (wa_message_id IS NULL OR wa_message_id = '')",
            [':wa_id' => $waMessageId, ':id' => $recentMsg['id']]
        );
        logWebhook("Updated recent message with wa_id for lead #{$leadId}");
        return;
    }

    // Only store if truly no record exists (manual send from phone app, not from CRM)
    logWebhook("No existing outbound found for lead #{$leadId} - storing as new (sent from phone app)");
    dbInsert(
        "INSERT INTO messages (lead_id, sender, direction, message_text, wa_message_id, is_first_outreach, created_at)
         VALUES (:lead_id, 'user', 'outbound', :msg, :wa_id, 0, NOW())",
        [
            ':lead_id' => $leadId,
            ':msg'     => $message,
            ':wa_id'   => $waMessageId
        ]
    );
}
