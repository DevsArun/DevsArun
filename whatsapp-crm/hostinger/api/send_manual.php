<?php
/**
 * API: Send Manual Message from Dashboard
 * Used when user manually replies to a lead from CRM chat
 * 
 * POST Params:
 *  - lead_id: required
 *  - message: required (text to send)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/node_client.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'POST method required'], 405);
}

try {
    $body = getRequestBody();
    $leadId = intval($body['lead_id'] ?? 0);
    $message = trim($body['message'] ?? '');

    // Validate
    if ($leadId <= 0) {
        jsonResponse(['success' => false, 'error' => 'lead_id is required'], 400);
    }

    if (empty($message)) {
        jsonResponse(['success' => false, 'error' => 'Message cannot be empty'], 400);
    }

    if (strlen($message) > 4096) {
        jsonResponse(['success' => false, 'error' => 'Message too long (max 4096 chars)'], 400);
    }

    // Get lead
    $lead = dbQueryOne(
        "SELECT id, phone_clean, business_name, whatsapp_status FROM leads WHERE id = :id AND is_active = 1",
        [':id' => $leadId]
    );

    if (!$lead) {
        jsonResponse(['success' => false, 'error' => 'Lead not found'], 404);
    }

    if (empty($lead['phone_clean'])) {
        jsonResponse(['success' => false, 'error' => 'Lead has no valid phone number'], 400);
    }

    // Send via Node.js
    $sendResult = nodeSendMessage($lead['phone_clean'], $message, $leadId);

    if (!$sendResult['success']) {
        jsonResponse([
            'success' => false, 
            'error' => 'Failed to send: ' . ($sendResult['error'] ?? 'Unknown error')
        ], 502);
    }

    $waMessageId = $sendResult['data']['wa_message_id'] ?? null;

    // Store message
    $msgId = dbInsert(
        "INSERT INTO messages (lead_id, sender, direction, message_text, wa_message_id, is_first_outreach, created_at)
         VALUES (:lead_id, 'user', 'outbound', :msg, :wa_id, 0, NOW())",
        [':lead_id' => $leadId, ':msg' => $message, ':wa_id' => $waMessageId]
    );

    // Update lead last contacted
    dbExecute(
        "UPDATE leads SET last_contacted_at = NOW(), updated_at = NOW() WHERE id = :id",
        [':id' => $leadId]
    );

    // Log activity
    dbInsert(
        "INSERT INTO activity_log (lead_id, action, details, created_at)
         VALUES (:lead_id, 'manual_message_sent', :details, NOW())",
        [':lead_id' => $leadId, ':details' => "Manual reply sent to {$lead['phone_clean']}"]
    );

    jsonResponse([
        'success'      => true,
        'message_id'   => $msgId,
        'wa_message_id'=> $waMessageId,
        'timestamp'    => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Server error'], 500);
}
