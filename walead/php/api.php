<?php
/**
 * WaLead CRM - API Endpoints
 * Handles all AJAX requests from the dashboard
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_leads':
            getLeads();
            break;
        case 'get_lead':
            getLead();
            break;
        case 'get_messages':
            getMessages();
            break;
        case 'send_message':
            sendMessage();
            break;
        case 'get_stats':
            getStats();
            break;
        case 'update_lead_status':
            updateLeadStatus();
            break;
        case 'get_config':
            getConfig();
            break;
        case 'save_config':
            saveConfig();
            break;
        case 'start_campaign':
            startCampaign();
            break;
        case 'get_campaign_status':
            getCampaignStatus();
            break;
        case 'get_node_status':
            getNodeStatus();
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}


// ============ LEADS ============
function getLeads() {
    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;

    $where = "WHERE 1=1";
    $params = [];

    switch ($filter) {
        case 'replied':
            $where .= " AND l.status = 'replied'";
            break;
        case 'sent':
            $where .= " AND l.status = 'sent'";
            break;
        case 'pending':
            $where .= " AND l.status = 'pending'";
            break;
        case 'has_website':
            $where .= " AND l.website IS NOT NULL AND l.website != ''";
            break;
        case 'no_website':
            $where .= " AND (l.website IS NULL OR l.website = '')";
            break;
    }

    if ($search) {
        $where .= " AND (l.business_name LIKE ? OR l.phone LIKE ? OR l.address LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM leads l {$where}";
    $total = db()->fetchOne($countSql, $params)['total'];

    // Get leads with last message
    $sql = "SELECT l.*, 
            (SELECT body FROM messages WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM messages WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
            (SELECT COUNT(*) FROM messages WHERE lead_id = l.id AND direction = 'inbound') as reply_count
            FROM leads l {$where}
            ORDER BY l.updated_at DESC
            LIMIT {$limit} OFFSET {$offset}";

    $leads = db()->fetchAll($sql, $params);

    jsonResponse([
        'success' => true,
        'leads' => $leads,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

function getLead() {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(['error' => 'Lead ID required'], 400);
    }

    $lead = db()->fetchOne("SELECT * FROM leads WHERE id = ?", [$id]);
    if (!$lead) {
        jsonResponse(['error' => 'Lead not found'], 404);
    }

    $messages = db()->fetchAll(
        "SELECT * FROM messages WHERE lead_id = ? ORDER BY created_at ASC",
        [$id]
    );

    jsonResponse([
        'success' => true,
        'lead' => $lead,
        'messages' => $messages
    ]);
}


// ============ MESSAGES ============
function getMessages() {
    $lead_id = intval($_GET['lead_id'] ?? 0);
    $since = $_GET['since'] ?? null;

    if (!$lead_id) {
        jsonResponse(['error' => 'Lead ID required'], 400);
    }

    $params = [$lead_id];
    $where = "WHERE lead_id = ?";

    if ($since) {
        $where .= " AND created_at > ?";
        $params[] = $since;
    }

    $messages = db()->fetchAll(
        "SELECT * FROM messages {$where} ORDER BY created_at ASC",
        $params
    );

    jsonResponse(['success' => true, 'messages' => $messages]);
}

function sendMessage() {
    $data = json_decode(file_get_contents('php://input'), true);
    $lead_id = intval($data['lead_id'] ?? 0);
    $message = trim($data['message'] ?? '');

    if (!$lead_id || !$message) {
        jsonResponse(['error' => 'Lead ID and message required'], 400);
    }

    // Get lead phone
    $lead = db()->fetchOne("SELECT * FROM leads WHERE id = ?", [$lead_id]);
    if (!$lead) {
        jsonResponse(['error' => 'Lead not found'], 404);
    }

    // Send via Node.js server
    $response = callNodeServer('/send-message', [
        'phone' => $lead['phone'],
        'message' => $message
    ]);

    if ($response && isset($response['success']) && $response['success']) {
        // Store message in database
        db()->insert(
            "INSERT INTO messages (lead_id, phone, body, direction, status, message_id, created_at) 
             VALUES (?, ?, ?, 'outbound', 'sent', ?, NOW())",
            [$lead_id, $lead['phone'], $message, $response['message_id'] ?? '']
        );

        // Update lead status
        db()->update(
            "UPDATE leads SET status = 'sent', last_contacted = NOW(), updated_at = NOW() WHERE id = ? AND status = 'pending'",
            [$lead_id]
        );

        jsonResponse([
            'success' => true,
            'message_id' => $response['message_id'] ?? '',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        $error = $response['error'] ?? 'Failed to send message';
        jsonResponse(['error' => $error], 500);
    }
}

// ============ STATS ============
function getStats() {
    $today = date('Y-m-d');

    $sentToday = db()->fetchOne(
        "SELECT COUNT(*) as count FROM messages WHERE direction = 'outbound' AND DATE(created_at) = ?",
        [$today]
    )['count'];

    $totalReplies = db()->fetchOne(
        "SELECT COUNT(*) as count FROM messages WHERE direction = 'inbound'"
    )['count'];

    $totalSent = db()->fetchOne(
        "SELECT COUNT(*) as count FROM messages WHERE direction = 'outbound'"
    )['count'];

    $totalLeads = db()->fetchOne(
        "SELECT COUNT(*) as count FROM leads"
    )['count'];

    $pendingLeads = db()->fetchOne(
        "SELECT COUNT(*) as count FROM leads WHERE status = 'pending'"
    )['count'];

    $repliedLeads = db()->fetchOne(
        "SELECT COUNT(*) as count FROM leads WHERE status = 'replied'"
    )['count'];

    $replyRate = $totalSent > 0 ? round(($totalReplies / $totalSent) * 100, 1) : 0;

    jsonResponse([
        'success' => true,
        'stats' => [
            'sent_today' => intval($sentToday),
            'total_replies' => intval($totalReplies),
            'total_sent' => intval($totalSent),
            'total_leads' => intval($totalLeads),
            'pending' => intval($pendingLeads),
            'replied' => intval($repliedLeads),
            'reply_rate' => $replyRate,
            'remaining' => intval($pendingLeads)
        ]
    ]);
}


// ============ LEAD STATUS ============
function updateLeadStatus() {
    $data = json_decode(file_get_contents('php://input'), true);
    $lead_id = intval($data['lead_id'] ?? 0);
    $status = $data['status'] ?? '';

    $validStatuses = ['pending', 'sent', 'replied', 'failed', 'opted_out'];
    if (!$lead_id || !in_array($status, $validStatuses)) {
        jsonResponse(['error' => 'Valid lead ID and status required'], 400);
    }

    db()->update(
        "UPDATE leads SET status = ?, updated_at = NOW() WHERE id = ?",
        [$status, $lead_id]
    );

    jsonResponse(['success' => true]);
}

// ============ CONFIG ============
function getConfig() {
    jsonResponse([
        'success' => true,
        'config' => [
            'node_server_url' => NODE_SERVER_URL,
            'groq_model' => GROQ_MODEL,
            'min_delay' => MIN_DELAY_SECONDS,
            'max_delay' => MAX_DELAY_SECONDS,
            'max_messages_per_day' => MAX_MESSAGES_PER_DAY,
            'verify_signature' => VERIFY_SIGNATURE
        ]
    ]);
}

function saveConfig() {
    // In production, this would update a config table in DB
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['webhook_url'])) {
        // Update Node.js server webhook URL
        callNodeServer('/update-config', ['webhook_url' => $data['webhook_url']]);
    }

    jsonResponse(['success' => true, 'message' => 'Config updated']);
}

// ============ CAMPAIGN ============
function startCampaign() {
    $data = json_decode(file_get_contents('php://input'), true);
    $message_template = $data['message_template'] ?? '';
    $use_ai = $data['use_ai'] ?? false;
    $filter = $data['filter'] ?? 'pending';
    $limit = min(intval($data['limit'] ?? 10), MAX_MESSAGES_PER_DAY);

    if (!$message_template && !$use_ai) {
        jsonResponse(['error' => 'Message template or AI mode required'], 400);
    }

    // Get leads to contact
    $where = "WHERE status = 'pending'";
    if ($filter === 'has_website') {
        $where .= " AND website IS NOT NULL AND website != ''";
    } elseif ($filter === 'no_website') {
        $where .= " AND (website IS NULL OR website = '')";
    }

    $leads = db()->fetchAll(
        "SELECT * FROM leads {$where} ORDER BY id ASC LIMIT ?",
        [$limit]
    );

    if (empty($leads)) {
        jsonResponse(['error' => 'No pending leads found'], 404);
    }

    // Build messages array
    $messages = [];
    foreach ($leads as $lead) {
        if ($use_ai) {
            $personalizedMsg = generateAIMessage($lead, $message_template);
        } else {
            $personalizedMsg = personalizeTemplate($message_template, $lead);
        }
        $messages[] = [
            'phone' => $lead['phone'],
            'message' => $personalizedMsg,
            'lead_id' => $lead['id']
        ];
    }

    // Send to Node.js bulk endpoint
    $bulkPayload = array_map(function($m) {
        return ['phone' => $m['phone'], 'message' => $m['message']];
    }, $messages);

    $response = callNodeServer('/send-bulk', ['messages' => $bulkPayload]);

    if ($response && isset($response['success']) && $response['success']) {
        // Create campaign record
        $campaignId = db()->insert(
            "INSERT INTO campaigns (message_template, use_ai, filter_type, total_leads, status, created_at) 
             VALUES (?, ?, ?, ?, 'running', NOW())",
            [$message_template, $use_ai ? 1 : 0, $filter, count($leads)]
        );

        // Store messages in DB as queued
        foreach ($messages as $msg) {
            db()->insert(
                "INSERT INTO messages (lead_id, phone, body, direction, status, created_at) 
                 VALUES (?, ?, ?, 'outbound', 'queued', NOW())",
                [$msg['lead_id'], $msg['phone'], $msg['message']]
            );
            db()->update(
                "UPDATE leads SET status = 'sent', last_contacted = NOW(), updated_at = NOW() WHERE id = ?",
                [$msg['lead_id']]
            );
        }

        jsonResponse([
            'success' => true,
            'campaign_id' => $campaignId,
            'queued' => count($messages),
            'message' => "Campaign started with {$response['queued']} messages queued"
        ]);
    } else {
        jsonResponse(['error' => $response['error'] ?? 'Failed to start campaign'], 500);
    }
}

function getCampaignStatus() {
    $campaigns = db()->fetchAll(
        "SELECT * FROM campaigns ORDER BY created_at DESC LIMIT 10"
    );
    jsonResponse(['success' => true, 'campaigns' => $campaigns]);
}


// ============ NODE STATUS ============
function getNodeStatus() {
    $response = callNodeServer('/status', null, 'GET');
    if ($response) {
        jsonResponse(['success' => true, 'node_status' => $response]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Cannot reach Node server']);
    }
}

// ============ HELPER FUNCTIONS ============
function callNodeServer($endpoint, $data = null, $method = 'POST') {
    $url = NODE_SERVER_URL . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($method === 'POST' && $data !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        if (DEBUG_MODE) {
            error_log("WaLead Node call failed: {$error}");
        }
        return null;
    }

    return json_decode($response, true);
}

function personalizeTemplate($template, $lead) {
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

function generateAIMessage($lead, $basePrompt) {
    $prompt = "Generate a personalized WhatsApp cold outreach message for a business. 
Keep it short (2-3 sentences max), friendly, professional.
Business Name: {$lead['business_name']}
Address: {$lead['address']}
Website: " . ($lead['website'] ?: 'No website') . "
Rating: {$lead['rating']} ({$lead['reviews']} reviews)
Base prompt/context: {$basePrompt}
Write ONLY the message, no quotes, no explanation.";

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . GROQ_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => GROQ_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a friendly business outreach assistant. Write short WhatsApp messages.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 150,
        'temperature' => 0.7
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        return trim($result['choices'][0]['message']['content']);
    }

    // Fallback to template
    return personalizeTemplate($basePrompt ?: "Hi {business_name}! I came across your business and would love to connect.", $lead);
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
