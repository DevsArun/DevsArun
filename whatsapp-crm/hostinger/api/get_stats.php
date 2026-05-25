<?php
/**
 * API: Get Dashboard Stats/KPIs
 * Returns counts and metrics for the dashboard sidebar
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

setCorsHeaders();

try {
    // Total leads
    $total = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE is_active = 1")['cnt'];

    // By outreach status
    $pending = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE outreach_status = 'pending' AND is_active = 1")['cnt'];
    $sent = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE outreach_status = 'sent' AND is_active = 1")['cnt'];
    $replied = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE outreach_status = 'replied' AND is_active = 1")['cnt'];
    $failed = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE outreach_status = 'failed' AND is_active = 1")['cnt'];
    $skipped = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE outreach_status = 'skipped' AND is_active = 1")['cnt'];

    // WhatsApp status
    $waValid = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE whatsapp_status = 'valid'")['cnt'];
    $waInvalid = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE whatsapp_status = 'invalid'")['cnt'];

    // Website stats
    $hasWebsite = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE website_status = 'has_website' AND is_active = 1")['cnt'];
    $noWebsite = dbQueryOne("SELECT COUNT(*) as cnt FROM leads WHERE website_status = 'no_website' AND is_active = 1")['cnt'];

    // Today's activity
    $today = date('Y-m-d');
    $sentToday = dbQueryOne(
        "SELECT COUNT(*) as cnt FROM messages WHERE direction = 'outbound' AND is_first_outreach = 1 AND DATE(created_at) = :today",
        [':today' => $today]
    )['cnt'];

    $repliesToday = dbQueryOne(
        "SELECT COUNT(*) as cnt FROM messages WHERE direction = 'inbound' AND DATE(created_at) = :today",
        [':today' => $today]
    )['cnt'];

    // Unread messages
    $unread = dbQueryOne("SELECT COUNT(*) as cnt FROM messages WHERE direction = 'inbound' AND is_read = 0")['cnt'];

    // Reply rate
    $replyRate = ($sent > 0) ? round(($replied / $sent) * 100, 1) : 0;

    jsonResponse([
        'success' => true,
        'stats' => [
            'total_leads'    => (int)$total,
            'pending'        => (int)$pending,
            'sent'           => (int)$sent,
            'replied'        => (int)$replied,
            'failed'         => (int)$failed,
            'skipped'        => (int)$skipped,
            'wa_valid'       => (int)$waValid,
            'wa_invalid'     => (int)$waInvalid,
            'has_website'    => (int)$hasWebsite,
            'no_website'     => (int)$noWebsite,
            'sent_today'     => (int)$sentToday,
            'replies_today'  => (int)$repliesToday,
            'unread'         => (int)$unread,
            'reply_rate'     => $replyRate,
            'daily_limit'    => CAMPAIGN_DAILY_LIMIT,
            'daily_remaining'=> max(0, CAMPAIGN_DAILY_LIMIT - (int)$sentToday)
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Failed to fetch stats'], 500);
}
