<?php
/**
 * API: Mark Messages as Read
 * Marks all unread inbound messages for a lead as read
 * 
 * POST Params:
 *  - lead_id: required
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'POST method required'], 405);
}

try {
    $body = getRequestBody();
    $leadId = intval($body['lead_id'] ?? 0);

    if ($leadId <= 0) {
        jsonResponse(['success' => false, 'error' => 'lead_id is required'], 400);
    }

    // Mark all unread inbound messages as read
    $affected = dbExecute(
        "UPDATE messages SET is_read = 1, read_at = NOW() 
         WHERE lead_id = :lead_id AND direction = 'inbound' AND is_read = 0",
        [':lead_id' => $leadId]
    );

    jsonResponse([
        'success' => true,
        'marked_read' => $affected
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Failed to mark as read'], 500);
}
