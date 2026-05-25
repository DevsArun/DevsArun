<?php
/**
 * API: Get Messages for a Lead
 * Returns full conversation thread
 * 
 * Params:
 *  - lead_id: required
 *  - page: int (default 1)
 *  - limit: int (default 50)
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

    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    // Verify lead exists
    $lead = dbQueryOne("SELECT id, business_name FROM leads WHERE id = :id", [':id' => $leadId]);
    if (!$lead) {
        jsonResponse(['success' => false, 'error' => 'Lead not found'], 404);
    }

    // Get total messages count
    $total = dbQueryOne(
        "SELECT COUNT(*) as cnt FROM messages WHERE lead_id = :lead_id",
        [':lead_id' => $leadId]
    )['cnt'];

    // Get messages (oldest first for chat display)
    $messages = dbQuery(
        "SELECT id, lead_id, sender, direction, message_text, wa_message_id, 
                message_type, is_read, is_first_outreach, created_at
         FROM messages 
         WHERE lead_id = :lead_id
         ORDER BY created_at ASC
         LIMIT {$limit} OFFSET {$offset}",
        [':lead_id' => $leadId]
    );

    // Format messages
    $formatted = array_map(function($msg) {
        return [
            'id'               => (int)$msg['id'],
            'sender'           => $msg['sender'],
            'direction'        => $msg['direction'],
            'message'          => $msg['message_text'],
            'type'             => $msg['message_type'],
            'is_read'          => (bool)$msg['is_read'],
            'is_first_outreach'=> (bool)$msg['is_first_outreach'],
            'timestamp'        => $msg['created_at'],
            'time_display'     => date('h:i A', strtotime($msg['created_at']))
        ];
    }, $messages);

    jsonResponse([
        'success'  => true,
        'messages' => $formatted,
        'lead'     => $lead,
        'pagination' => [
            'page'  => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Failed to fetch messages'], 500);
}
