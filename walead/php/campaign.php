<?php
/**
 * WaLead CRM - Campaign Runner
 * Handles campaign execution with Groq AI integration
 * Can be run via cron or triggered from dashboard
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create':
        createCampaign();
        break;
    case 'status':
        getCampaignStatus();
        break;
    case 'history':
        getCampaignHistory();
        break;
    case 'stop':
        stopCampaign();
        break;
    case 'generate_preview':
        generatePreview();
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}

function createCampaign() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($data['name'] ?? 'Campaign ' . date('Y-m-d H:i'));
    $template = trim($data['template'] ?? '');
    $useAI = (bool)($data['use_ai'] ?? false);
    $filter = $data['filter'] ?? 'pending';
    $limit = min(intval($data['limit'] ?? 10), MAX_MESSAGES_PER_DAY);

    if (!$template && !$useAI) {
        echo json_encode(['error' => 'Template or AI mode required']);
        return;
    }

    // Count available leads
    $where = "WHERE status = 'pending'";
    if ($filter === 'has_website') {
        $where .= " AND website IS NOT NULL AND website != ''";
    } elseif ($filter === 'no_website') {
        $where .= " AND (website IS NULL OR website = '')";
    }

    $availableCount = db()->fetchOne("SELECT COUNT(*) as cnt FROM leads {$where}")['cnt'];
    $actualLimit = min($limit, $availableCount);

    if ($actualLimit === 0) {
        echo json_encode(['error' => 'No matching leads available']);
        return;
    }

    // Create campaign record
    $campaignId = db()->insert(
        "INSERT INTO campaigns (name, message_template, use_ai, filter_type, total_leads, sent_count, status, created_at) 
         VALUES (?, ?, ?, ?, ?, 0, 'pending', NOW())",
        [$name, $template, $useAI ? 1 : 0, $filter, $actualLimit]
    );

    // Get leads
    $leads = db()->fetchAll(
        "SELECT * FROM leads {$where} ORDER BY id ASC LIMIT ?",
        [$actualLimit]
    );

    // Generate messages
    $messages = [];
    foreach ($leads as $lead) {
        $msg = $useAI ? generateAIMsg($lead, $template) : personalizeMsg($template, $lead);
        $messages[] = ['phone' => $lead['phone'], 'message' => $msg];
        
        // Store in messages table
        db()->insert(
            "INSERT INTO messages (lead_id, phone, body, direction, status, created_at) 
             VALUES (?, ?, ?, 'outbound', 'queued', NOW())",
            [$lead['id'], $lead['phone'], $msg]
        );

        // Update lead status
        db()->update(
            "UPDATE leads SET status = 'sent', last_contacted = NOW(), updated_at = NOW() WHERE id = ?",
            [$lead['id']]
        );
    }

    // Send to Node.js bulk endpoint
    $response = callNode('/send-bulk', ['messages' => $messages]);

    if ($response && isset($response['success']) && $response['success']) {
        db()->update(
            "UPDATE campaigns SET status = 'running', started_at = NOW() WHERE id = ?",
            [$campaignId]
        );

        echo json_encode([
            'success' => true,
            'campaign_id' => $campaignId,
            'queued' => count($messages),
            'message' => "Campaign started: {$actualLimit} messages queued"
        ]);
    } else {
        db()->update("UPDATE campaigns SET status = 'failed' WHERE id = ?", [$campaignId]);
        echo json_encode(['error' => 'Failed to queue messages on Node server']);
    }
}

function getCampaignStatus() {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode(['error' => 'Campaign ID required']);
        return;
    }

    $campaign = db()->fetchOne("SELECT * FROM campaigns WHERE id = ?", [$id]);
    if (!$campaign) {
        echo json_encode(['error' => 'Campaign not found']);
        return;
    }

    // Get sent count
    $sentCount = db()->fetchOne(
        "SELECT COUNT(*) as cnt FROM messages WHERE direction = 'outbound' AND status = 'sent' AND created_at >= ?",
        [$campaign['created_at']]
    )['cnt'];

    echo json_encode(['success' => true, 'campaign' => $campaign, 'sent_count' => $sentCount]);
}

function getCampaignHistory() {
    $campaigns = db()->fetchAll(
        "SELECT c.*, 
         (SELECT COUNT(*) FROM messages WHERE direction = 'outbound' AND created_at >= c.created_at AND created_at <= IFNULL(c.completed_at, NOW())) as actual_sent
         FROM campaigns c ORDER BY c.created_at DESC LIMIT 20"
    );
    echo json_encode(['success' => true, 'campaigns' => $campaigns]);
}

function stopCampaign() {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = intval($data['id'] ?? 0);
    if (!$id) {
        echo json_encode(['error' => 'Campaign ID required']);
        return;
    }
    db()->update("UPDATE campaigns SET status = 'stopped', completed_at = NOW() WHERE id = ?", [$id]);
    echo json_encode(['success' => true]);
}

function generatePreview() {
    $data = json_decode(file_get_contents('php://input'), true);
    $template = $data['template'] ?? '';
    $useAI = (bool)($data['use_ai'] ?? false);

    // Get a sample lead
    $lead = db()->fetchOne("SELECT * FROM leads WHERE status = 'pending' LIMIT 1");
    if (!$lead) {
        echo json_encode(['error' => 'No leads available for preview']);
        return;
    }

    $preview = $useAI ? generateAIMsg($lead, $template) : personalizeMsg($template, $lead);
    echo json_encode(['success' => true, 'preview' => $preview, 'lead' => $lead['business_name']]);
}

// Helper functions
function personalizeMsg($template, $lead) {
    $replacements = [
        '{business_name}' => $lead['business_name'] ?? '',
        '{name}' => $lead['business_name'] ?? '',
        '{phone}' => $lead['phone'] ?? '',
        '{address}' => $lead['address'] ?? '',
        '{website}' => $lead['website'] ?? '',
        '{rating}' => $lead['rating'] ?? '',
        '{reviews}' => $lead['reviews'] ?? ''
    ];
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

function generateAIMsg($lead, $basePrompt) {
    $prompt = "Generate a personalized WhatsApp cold outreach message for:
Business: {$lead['business_name']}
Location: {$lead['address']}
Website: " . ($lead['website'] ?: 'None') . "
Rating: {$lead['rating']}/5 ({$lead['reviews']} reviews)
Context: {$basePrompt}
Rules: Keep it under 3 sentences. Be friendly and professional. No emojis overuse. Write ONLY the message.";

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . GROQ_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => GROQ_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => 'You write short, friendly WhatsApp business outreach messages.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 120,
        'temperature' => 0.7
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return trim($result['choices'][0]['message']['content']);
    }

    return personalizeMsg($basePrompt ?: "Hi {business_name}! I found your business and wanted to connect.", $lead);
}

function callNode($endpoint, $data = null) {
    $url = NODE_SERVER_URL . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if ($data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
