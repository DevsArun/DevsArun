<?php
/**
 * ============================================================
 * WhatsApp CRM - Webhook Receiver
 * ============================================================
 * Receives events from Node.js WhatsApp Engine
 * 
 * Events handled:
 *  - message_received: Inbound message from lead
 *  - message_sent: Confirmation of outbound message
 *  - message_sent_ack: Delivery acknowledgment
 * 
 * Security: HMAC-SHA256 signature verification
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

// ============================================================
// EVENT HANDLERS
// ============================================================

try {
    switch ($event) {

        // ── INBOUND MESSAGE (Lead replied) ──
        case 'message_received':
            handleInboundMessage($phone, $message, $waMessageId, $timestamp, $data);
            break;

        // ── OUTBOUND MESSAGE CONFIRMATION ──
        case 'message_sent':
            handleOutboundConfirmation($phone, $message, $waMessageId, $leadIdFromPayload, $timestamp);
            break;

        // ── DELIVERY ACK ──
        case 'message_sent_ack':
            // Just log, no action needed
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

/**
 * Handle inbound message from a lead
 */
function handleInboundMessage(string $phone, string $message, string $waMessageId, $timestamp, array $data): void {
    if (empty($phone) || empty($message)) {
        logWebhook("Inbound missing phone or message", 'WARN');
        return;
    }

    // Find lead by phone
    $lead = dbQueryOne(
        "SELECT id, business_name, outreach_status FROM leads WHERE phone_clean = :phone",
        [':phone' => $phone]
    );

    if (!$lead) {
        // Unknown number - could be new contact
        logWebhook("Inbound from unknown number: {$phone} - skipping");
        return;
    }

    $leadId = $lead['id'];

    // Check for duplicate message (by wa_message_id)
    if (!empty($waMessageId)) {
        $existing = dbQueryOne(
            "SELECT id FROM messages WHERE wa_message_id = :wa_id",
            [':wa_id' => $waMessageId]
        );

        if ($existing) {
            logWebhook("Duplicate message skipped: {$waMessageId}");
            return;
        }
    }

    // Store inbound message
    dbInsert(
        "INSERT INTO messages (lead_id, sender, direction, message_text, wa_message_id, message_type, is_read, created_at)
         VALUES (:lead_id, 'lead', 'inbound', :msg, :wa_id, 'text', 0, :created_at)",
        [
            ':lead_id'    => $leadId,
            ':msg'        => $message,
            ':wa_id'      => $waMessageId,
            ':created_at' => date('Y-m-d H:i:s', is_numeric($timestamp) ? $timestamp : time())
        ]
    );

    // CRITICAL: Mark lead as replied - STOP automation
    if ($lead['outreach_status'] !== 'replied') {
        dbExecute(
            "UPDATE leads SET 
                outreach_status = 'replied', 
                reply_received_at = NOW(), 
                updated_at = NOW() 
             WHERE id = :id",
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

        logWebhook("LEAD REPLIED: {$lead['business_name']} ({$phone}) - Automation STOPPED");
    } else {
        logWebhook("Follow-up message from {$lead['business_name']} ({$phone})");
    }
}

/**
 * Handle outbound message confirmation from Node
 * This is triggered when campaign.php sends via Node and Node confirms back
 */
function handleOutboundConfirmation(string $phone, string $message, string $waMessageId, ?int $leadId, $timestamp): void {
    if (empty($phone)) return;

    // If we already stored this message (campaign.php stores it), just update wa_message_id
    if (!empty($waMessageId) && $leadId) {
        // Check if message already exists without wa_id
        $existing = dbQueryOne(
            "SELECT id FROM messages WHERE lead_id = :lead_id AND direction = 'outbound' AND wa_message_id IS NULL ORDER BY created_at DESC LIMIT 1",
            [':lead_id' => $leadId]
        );

        if ($existing) {
            dbExecute(
                "UPDATE messages SET wa_message_id = :wa_id, delivered_at = NOW() WHERE id = :id",
                [':wa_id' => $waMessageId, ':id' => $existing['id']]
            );
            logWebhook("Updated wa_message_id for lead #{$leadId}");
            return;
        }
    }

    // If no existing record, check by wa_message_id to avoid duplicates
    if (!empty($waMessageId)) {
        $dup = dbQueryOne(
            "SELECT id FROM messages WHERE wa_message_id = :wa_id",
            [':wa_id' => $waMessageId]
        );
        if ($dup) {
            logWebhook("Outbound confirmation already recorded: {$waMessageId}");
            return;
        }
    }

    // Find lead if not provided
    if (!$leadId) {
        $lead = dbQueryOne(
            "SELECT id FROM leads WHERE phone_clean = :phone",
            [':phone' => $phone]
        );
        $leadId = $lead['id'] ?? null;
    }

    if (!$leadId) {
        logWebhook("Outbound confirmation for unknown lead: {$phone}");
        return;
    }

    // Store if not already stored by campaign.php
    dbInsert(
        "INSERT INTO messages (lead_id, sender, direction, message_text, wa_message_id, created_at)
         VALUES (:lead_id, 'system', 'outbound', :msg, :wa_id, :created_at)",
        [
            ':lead_id'    => $leadId,
            ':msg'        => $message,
            ':wa_id'      => $waMessageId,
            ':created_at' => date('Y-m-d H:i:s', is_numeric($timestamp) ? $timestamp : time())
        ]
    );

    logWebhook("Outbound stored for lead #{$leadId}");
}
