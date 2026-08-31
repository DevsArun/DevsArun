<?php
/**
 * API: Get Messages for a Lead — FINAL FIXED
 * Returns conversation with IST timestamps
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

setCorsHeaders();

try {
    $leadId = intval($_GET['lead_id'] ?? 0);
    if ($leadId <= 0) {
        jsonResponse(['success' => false, 'error' => 'lead_id is required'], 400);
    }

    // Get messages (oldest first for chat)
    $messages = dbQuery(
        "SELECT id, lead_id, sender, direction, message_text, wa_message_id,
                message_type, is_read, is_first_outreach, created_at
         FROM messages
         WHERE lead_id = :lead_id
         ORDER BY created_at ASC
         LIMIT 200",
        [':lead_id' => $leadId]
    );

    // Format with IST time
    $formatted = array_map(function($msg) {
        $ts = strtotime($msg['created_at']);
        // Format in IST (server should already be IST from app.php timezone)
        $timeDisplay = date('h:i A', $ts);

        return [
            'id'               => (int)$msg['id'],
            'sender'           => $msg['sender'],
            'direction'        => $msg['direction'],
            'message'          => $msg['message_text'],
            'type'             => $msg['message_type'] ?? 'text',
            'is_read'          => (bool)$msg['is_read'],
            'is_first_outreach'=> (bool)$msg['is_first_outreach'],
            'timestamp'        => $msg['created_at'],
            'time_display'     => $timeDisplay
        ];
    }, $messages);

    jsonResponse([
        'success'  => true,
        'messages' => $formatted,
        'total'    => count($formatted)
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Failed to fetch messages'], 500);
}
