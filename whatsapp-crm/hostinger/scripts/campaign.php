<?php
/**
 * ============================================================
 * WhatsApp CRM - Campaign Runner
 * ============================================================
 * Main outreach automation script
 * 
 * Flow:
 * 1. Fetch pending leads (not yet contacted)
 * 2. For each lead:
 *    a. Check WhatsApp registration via Node.js
 *    b. Skip invalid numbers
 *    c. Generate personalized message via Groq AI
 *    d. Send message via Node.js WhatsApp engine
 *    e. Store message in DB
 *    f. Update lead status
 *    g. Sleep random 120-300 seconds (anti-ban)
 * 
 * Usage (CLI): php campaign.php [--limit=20] [--dry-run]
 * Usage (Web): Called via AJAX for single batch trigger
 * 
 * IMPORTANT: This sends ONLY the first outreach message.
 * After lead replies, automation stops completely.
 */

// Load dependencies
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/groq.php';
require_once __DIR__ . '/../includes/node_client.php';

// ============================================================
// CONFIGURATION
// ============================================================

$isWeb = (php_sapi_name() !== 'cli');
$isDryRun = false;
$batchLimit = CAMPAIGN_BATCH_SIZE;

// CLI argument parsing
if (!$isWeb) {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $batchLimit = intval(str_replace('--limit=', '', $arg));
        }
        if ($arg === '--dry-run') {
            $isDryRun = true;
        }
    }
}

// Web mode - get params
if ($isWeb) {
    $batchLimit = intval($_POST['limit'] ?? $_GET['limit'] ?? CAMPAIGN_BATCH_SIZE);
    $isDryRun = isset($_POST['dry_run']) || isset($_GET['dry_run']);
}

// Safety cap
$batchLimit = min($batchLimit, CAMPAIGN_DAILY_LIMIT);

logCampaign("=== Campaign started | Limit: {$batchLimit} | DryRun: " . ($isDryRun ? 'YES' : 'NO') . " ===");

// ============================================================
// PRE-FLIGHT CHECKS
// ============================================================

// Check Node.js engine status
$nodeStatus = isNodeReady();
if (!$nodeStatus['online']) {
    $msg = "Node.js engine is OFFLINE. Cannot run campaign.";
    logCampaign($msg, 'ERROR');
    if ($isWeb) jsonResponse(['success' => false, 'error' => $msg], 503);
    echo "[ERROR] {$msg}\n";
    exit(1);
}

if (!$nodeStatus['wa_ready']) {
    $msg = "WhatsApp not ready. Status: {$nodeStatus['status']}. Scan QR first.";
    logCampaign($msg, 'ERROR');
    if ($isWeb) jsonResponse(['success' => false, 'error' => $msg], 503);
    echo "[ERROR] {$msg}\n";
    exit(1);
}

logCampaign("Pre-flight OK: Node online, WhatsApp ready");

// ============================================================
// CHECK DAILY LIMIT
// ============================================================

$today = date('Y-m-d');
$sentToday = dbQueryOne(
    "SELECT COUNT(*) as cnt FROM messages 
     WHERE direction = 'outbound' AND is_first_outreach = 1 
     AND DATE(created_at) = :today",
    [':today' => $today]
)['cnt'] ?? 0;

$remaining = CAMPAIGN_DAILY_LIMIT - $sentToday;

if ($remaining <= 0) {
    $msg = "Daily limit reached ({$sentToday}/{CAMPAIGN_DAILY_LIMIT}). Try again tomorrow.";
    logCampaign($msg, 'WARN');
    if ($isWeb) jsonResponse(['success' => false, 'error' => $msg], 429);
    echo "[WARN] {$msg}\n";
    exit(0);
}

$batchLimit = min($batchLimit, $remaining);
logCampaign("Daily usage: {$sentToday}/{CAMPAIGN_DAILY_LIMIT} | This batch max: {$batchLimit}");

// ============================================================
// FETCH PENDING LEADS
// ============================================================

$leads = dbQuery(
    "SELECT * FROM leads 
     WHERE outreach_status = 'pending' 
     AND is_active = 1 
     AND phone_clean IS NOT NULL 
     AND phone_clean != ''
     ORDER BY review_count DESC, rating DESC
     LIMIT :limit",
    [':limit' => $batchLimit]
);

if (empty($leads)) {
    $msg = "No pending leads to process.";
    logCampaign($msg);
    if ($isWeb) jsonResponse(['success' => true, 'message' => $msg, 'processed' => 0]);
    echo "[INFO] {$msg}\n";
    exit(0);
}

$totalLeads = count($leads);
logCampaign("Fetched {$totalLeads} leads to process");

// ============================================================
// PROCESS EACH LEAD
// ============================================================

$stats = [
    'processed'  => 0,
    'sent'       => 0,
    'invalid'    => 0,
    'failed'     => 0,
    'skipped'    => 0
];

foreach ($leads as $index => $lead) {
    $leadNum = $index + 1;
    $leadId = $lead['id'];
    $phone = $lead['phone_clean'];
    $businessName = $lead['business_name'];

    logCampaign("[{$leadNum}/{$totalLeads}] Processing: {$businessName} ({$phone})");

    // ── Step 1: Validate WhatsApp Registration ──
    if (FEATURE_NUMBER_VALIDATION) {
        logCampaign("  Checking WhatsApp registration...");
        $checkResult = nodeCheckNumber($phone);

        if (!$checkResult['success']) {
            logCampaign("  Number check failed: {$checkResult['error']}", 'WARN');
            // Mark as failed, will retry later
            dbExecute(
                "UPDATE leads SET outreach_status = 'failed', updated_at = NOW() WHERE id = :id",
                [':id' => $leadId]
            );
            $stats['failed']++;
            $stats['processed']++;
            continue;
        }

        $isRegistered = $checkResult['data']['is_registered'] ?? false;

        if (!$isRegistered) {
            logCampaign("  ✗ Number NOT on WhatsApp - skipping");
            dbExecute(
                "UPDATE leads SET whatsapp_status = 'invalid', outreach_status = 'skipped', updated_at = NOW() WHERE id = :id",
                [':id' => $leadId]
            );
            $stats['invalid']++;
            $stats['processed']++;
            continue;
        }

        // Mark as valid
        dbExecute(
            "UPDATE leads SET whatsapp_status = 'valid', updated_at = NOW() WHERE id = :id",
            [':id' => $leadId]
        );
        logCampaign("  ✓ Number valid on WhatsApp");
    }

    // ── Step 2: Generate Personalized Message ──
    logCampaign("  Generating AI message...");
    $msgResult = generateOutreachMessage($lead);

    if (!$msgResult['success']) {
        logCampaign("  Message generation failed: {$msgResult['error']}", 'ERROR');
        dbExecute(
            "UPDATE leads SET outreach_status = 'failed', updated_at = NOW() WHERE id = :id",
            [':id' => $leadId]
        );
        $stats['failed']++;
        $stats['processed']++;
        continue;
    }

    $outreachMessage = $msgResult['message'];
    logCampaign("  Message generated (" . strlen($outreachMessage) . " chars)");

    // ── Step 3: Send Message (or dry-run) ──
    if ($isDryRun) {
        logCampaign("  [DRY-RUN] Would send message to {$phone}");
        logCampaign("  [DRY-RUN] Message preview: " . substr($outreachMessage, 0, 100) . "...");
        $stats['sent']++;
        $stats['processed']++;

        // Store generated message for review
        dbExecute(
            "UPDATE leads SET outreach_message = :msg, outreach_status = 'queued', updated_at = NOW() WHERE id = :id",
            [':msg' => $outreachMessage, ':id' => $leadId]
        );
        continue;
    }

    // Actually send via Node.js
    logCampaign("  Sending message...");
    $sendResult = nodeSendMessage($phone, $outreachMessage, $leadId);

    if (!$sendResult['success']) {
        logCampaign("  ✗ Send failed: {$sendResult['error']}", 'ERROR');
        dbExecute(
            "UPDATE leads SET outreach_status = 'failed', outreach_message = :msg, updated_at = NOW() WHERE id = :id",
            [':msg' => $outreachMessage, ':id' => $leadId]
        );
        $stats['failed']++;
        $stats['processed']++;
        continue;
    }

    // ── Step 4: Record Success ──
    $waMessageId = $sendResult['data']['wa_message_id'] ?? null;

    // Update lead
    dbExecute(
        "UPDATE leads SET 
            outreach_status = 'sent', 
            outreach_message = :msg, 
            last_contacted_at = NOW(), 
            updated_at = NOW() 
         WHERE id = :id",
        [':msg' => $outreachMessage, ':id' => $leadId]
    );

    // Store message in messages table
    dbInsert(
        "INSERT INTO messages (lead_id, sender, direction, message_text, wa_message_id, is_first_outreach, created_at)
         VALUES (:lead_id, 'system', 'outbound', :msg, :wa_id, 1, NOW())",
        [
            ':lead_id' => $leadId,
            ':msg'     => $outreachMessage,
            ':wa_id'   => $waMessageId
        ]
    );

    // Log activity
    dbInsert(
        "INSERT INTO activity_log (lead_id, action, details, created_at)
         VALUES (:lead_id, 'outreach_sent', :details, NOW())",
        [
            ':lead_id' => $leadId,
            ':details' => "First outreach sent to {$phone}"
        ]
    );

    logCampaign("  ✓ Message SENT successfully | WA ID: {$waMessageId}");
    $stats['sent']++;
    $stats['processed']++;

    // ── Step 5: Anti-ban delay ──
    if ($leadNum < $totalLeads) {
        $delay = getRandomDelay();
        logCampaign("  Sleeping {$delay}s before next message...");

        if (!$isWeb) {
            // CLI: actually sleep
            sleep($delay);
        } else {
            // Web: break after first send (let frontend trigger next)
            logCampaign("  Web mode: stopping after first send. Frontend will trigger next.");
            break;
        }
    }
}

// ============================================================
// FINAL REPORT
// ============================================================

$summary = "Campaign batch complete | Processed: {$stats['processed']} | Sent: {$stats['sent']} | Invalid: {$stats['invalid']} | Failed: {$stats['failed']}";
logCampaign($summary);
logCampaign("=== Campaign batch ended ===\n");

if ($isWeb) {
    jsonResponse([
        'success' => true,
        'message' => 'Campaign batch processed',
        'stats'   => $stats
    ]);
} else {
    echo "\n╔══════════════════════════════════════════╗\n";
    echo "║       CAMPAIGN BATCH COMPLETE            ║\n";
    echo "╠══════════════════════════════════════════╣\n";
    echo "║  Processed:    {$stats['processed']}\n";
    echo "║  Sent:         {$stats['sent']}\n";
    echo "║  Invalid (no WA): {$stats['invalid']}\n";
    echo "║  Failed:       {$stats['failed']}\n";
    echo "║  Skipped:      {$stats['skipped']}\n";
    echo "╚══════════════════════════════════════════╝\n";
}
