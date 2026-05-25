<?php
/**
 * API: Get Full Lead Details
 * Returns complete lead information for the right panel
 * 
 * Params:
 *  - id: lead ID (required)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

setCorsHeaders();

try {
    $leadId = intval($_GET['id'] ?? 0);

    if ($leadId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Lead ID is required'], 400);
    }

    $lead = dbQueryOne("SELECT * FROM leads WHERE id = :id", [':id' => $leadId]);

    if (!$lead) {
        jsonResponse(['success' => false, 'error' => 'Lead not found'], 404);
    }

    // Get message count
    $msgCount = dbQueryOne(
        "SELECT COUNT(*) as cnt FROM messages WHERE lead_id = :id",
        [':id' => $leadId]
    )['cnt'];

    // Get last activity
    $lastActivity = dbQueryOne(
        "SELECT action, details, created_at FROM activity_log WHERE lead_id = :id ORDER BY created_at DESC LIMIT 1",
        [':id' => $leadId]
    );

    jsonResponse([
        'success' => true,
        'lead' => [
            'id'              => (int)$lead['id'],
            'business_name'   => $lead['business_name'],
            'address'         => $lead['address'],
            'locality'        => $lead['locality'],
            'city'            => $lead['city'],
            'state'           => $lead['state'],
            'phone'           => $lead['phone_clean'],
            'phone_raw'       => $lead['phone_raw'],
            'website_url'     => $lead['website_url'],
            'website_status'  => $lead['website_status'],
            'rating'          => $lead['rating'] ? floatval($lead['rating']) : null,
            'review_count'    => (int)$lead['review_count'],
            'business_status' => $lead['business_status'],
            'pitch_type'      => $lead['pitch_type'],
            'language'        => $lead['language_preference'],
            'whatsapp_status' => $lead['whatsapp_status'],
            'outreach_status' => $lead['outreach_status'],
            'outreach_message'=> $lead['outreach_message'],
            'last_contacted'  => $lead['last_contacted_at'],
            'reply_received'  => $lead['reply_received_at'],
            'message_count'   => (int)$msgCount,
            'notes'           => $lead['notes'],
            'last_activity'   => $lastActivity,
            'created_at'      => $lead['created_at'],
            'updated_at'      => $lead['updated_at']
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Failed to fetch lead details'], 500);
}
