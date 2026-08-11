<?php
/**
 * API: Refresh/Sync State
 * Returns current engine status and unread count
 * Used for periodic dashboard refresh
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/node_client.php';

setCorsHeaders();

try {
    // Get Node.js engine status
    $nodeStatus = isNodeReady();

    // Get unread count
    $unread = dbQueryOne(
        "SELECT COUNT(*) as cnt FROM messages WHERE direction = 'inbound' AND is_read = 0"
    )['cnt'];

    // Get recent replies (last 5 minutes)
    $recentReplies = dbQuery(
        "SELECT l.id, l.business_name, m.message_text, m.created_at 
         FROM messages m 
         JOIN leads l ON l.id = m.lead_id 
         WHERE m.direction = 'inbound' AND m.created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         ORDER BY m.created_at DESC 
         LIMIT 5"
    );

    // Campaign status
    $activeCampaign = dbQueryOne(
        "SELECT * FROM campaigns WHERE status = 'running' ORDER BY started_at DESC LIMIT 1"
    );

    jsonResponse([
        'success' => true,
        'engine' => [
            'online'   => $nodeStatus['online'],
            'wa_ready' => $nodeStatus['wa_ready'],
            'status'   => $nodeStatus['status']
        ],
        'unread_count'   => (int)$unread,
        'recent_replies' => $recentReplies,
        'campaign'       => $activeCampaign,
        'timestamp'      => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Sync failed'], 500);
}
