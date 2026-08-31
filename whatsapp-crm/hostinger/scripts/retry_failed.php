<?php
/**
 * ============================================================
 * WhatsApp CRM - Retry Failed Leads
 * ============================================================
 * Retries leads that previously failed during campaign
 * Only retries leads with outreach_status = 'failed'
 * 
 * Usage (CLI): php retry_failed.php [--limit=10]
 * Usage (Web): Called via AJAX
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
$batchLimit = 10;

if (!$isWeb && isset($argv[1])) {
    if (str_starts_with($argv[1], '--limit=')) {
        $batchLimit = intval(str_replace('--limit=', '', $argv[1]));
    }
}

if ($isWeb) {
    $batchLimit = intval($_POST['limit'] ?? $_GET['limit'] ?? 10);
}

$batchLimit = min($batchLimit, 20); // Safety cap for retries

logCampaign("=== Retry Failed started | Limit: {$batchLimit} ===");

// ============================================================
// PRE-FLIGHT
// ============================================================

$nodeStatus = isNodeReady();
if (!$nodeStatus['wa_ready']) {
    $msg = "WhatsApp not ready. Status: {$nodeStatus['status']}";
    logCampaign($msg, 'ERROR');
    if ($isWeb) jsonResponse(['success' => false, 'error' => $msg], 503);
    echo "[ERROR] {$msg}\n";
    exit(1);
}

// ============================================================
// FETCH FAILED LEADS
// ============================================================

$leads = dbQuery(
    "SELECT * FROM leads 
     WHERE outreach_status = 'failed' 
     AND is_active = 1 
     AND phone_clean IS NOT NULL 
     AND whatsapp_status != 'invalid'
     ORDER BY updated_at ASC
     LIMIT :limit",
    [':limit' => $batchLimit]
);

if (empty($leads)) {
    $msg = "No failed leads to retry.";
    logCampaign($msg);
    if ($isWeb) jsonResponse(['success' => true, 'message' => $msg, 'processed' => 0]);
    echo "[INFO] {$msg}\n";
    exit(0);
}

$totalLeads = count($leads);
logCampaign("Found {$totalLeads} failed leads to retry");

// ============================================================
// PROCESS
// ============================================================

$stats = ['processed' => 0, 'sent' => 0, 'invalid' => 0, 'failed' => 0];

foreach ($leads as $index => $lead) {
    $leadId = $lead['id'];
    $phone = $lead['phone_clean'];
    $businessName = $lead['business_name'];

    logCampaign("[Retry " . ($index + 1) . "/{$totalLeads}] {$businessName} ({$phone})");

    // Re-check WhatsApp registration
    if (FEATURE_NUMBER_VALIDATION) {
        $checkResult = nodeCheckNumber($phone);

        if ($checkResult['success'] && !($checkResult['data']['is_registered'] ?? false)) {
            logCampaign("  ✗ Still not on WhatsApp - marking invalid");
            dbExecute(
                "UPDATE leads SET whatsapp_status = 'invalid', outreach_status = 'skipped', updated_at = NOW() WHERE id = :id",
                [':id' => $leadId]
            );
            $stats['invalid']++;
            $stats['processed']++;
            continue;
        }

        if (!$checkResult['success']) {
            logCampaign("  Check failed again: {$checkResult['error']}", 'WARN');
            $stats['failed']++;
            $stats['processed']++;
            continue;
        }

        dbExecute(
            "UPDATE leads SET whatsapp_status = 'valid', updated_at = NOW() WHERE id = :id",
            [':id' => $leadId]
        );
    }

    // Generate message (use existing if available)
    $outreachMessage = $lead['outreach_message'];
    if (empty($outreachMessage)) {
        $msgResult = generateOutreachMessage($lead);
        if (!$msgResult['success']) {
            logCampaign("  Message generation failed", 'ERROR');
            $stats['failed']++;
            $stats['processed']++;
            continue;
        }
        $outreachMessage = $msgResult['message'];
    }

    // Send
    $sendResult = nodeSendMessage($phone, $outreachMessage, $leadId);

    if (!$sendResult['success']) {
        logCampaign("  ✗ Send failed again: {$sendResult['error']}", 'ERROR');
        $stats['failed']++;
        $stats['processed']++;
        continue;
    }

    // Success
    $waMessageId = $sendResult['data']['wa_message_id'] ?? null;

    dbExecute(
        "UPDATE leads SET 
            outreach_status = 'sent', 
            outreach_message = :msg,
            last_contacted_at = NOW(),
            updated_at = NOW()
         WHERE id = :id",
        [':msg' => $outreachMessage, ':id' => $leadId]
    );

    dbInsert(
        "INSERT INTO messages (lead_id, sender, direction, message_text, wa_message_id, is_first_outreach, created_at)
         VALUES (:lead_id, 'system', 'outbound', :msg, :wa_id, 1, NOW())",
        [':lead_id' => $leadId, ':msg' => $outreachMessage, ':wa_id' => $waMessageId]
    );

    logCampaign("  ✓ Retry SENT successfully");
    $stats['sent']++;
    $stats['processed']++;

    // Anti-ban delay
    if ($index < $totalLeads - 1) {
        $delay = getRandomDelay();
        logCampaign("  Sleeping {$delay}s...");
        if (!$isWeb) {
            sleep($delay);
        } else {
            break; // Web mode: one at a time
        }
    }
}

// ============================================================
// REPORT
// ============================================================

$summary = "Retry complete | Processed: {$stats['processed']} | Sent: {$stats['sent']} | Invalid: {$stats['invalid']} | Still Failed: {$stats['failed']}";
logCampaign($summary);

if ($isWeb) {
    jsonResponse(['success' => true, 'message' => 'Retry batch processed', 'stats' => $stats]);
} else {
    echo "\n[RETRY COMPLETE] Sent: {$stats['sent']} | Invalid: {$stats['invalid']} | Failed: {$stats['failed']}\n";
}
