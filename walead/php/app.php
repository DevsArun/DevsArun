<?php
/**
 * WaLead CRM - PHP Backend API
 * Handles all dashboard operations, campaign management, CSV import, settings
 * Hosted on Hostinger shared hosting
 */

require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_leads': getLeads(); break;
    case 'get_lead': getLead(); break;
    case 'update_lead': updateLead(); break;
    case 'delete_lead': deleteLead(); break;
    case 'import_csv': importCSV(); break;
    case 'get_messages': getMessages(); break;
    case 'send_message': sendMessage(); break;
    case 'start_campaign': startCampaign(); break;
    case 'get_campaign_status': getCampaignStatus(); break;
    case 'stop_campaign': stopCampaign(); break;
    case 'process_campaign': processCampaignQueue(); break;
    case 'generate_message': generateMessage(); break;
    case 'get_stats': getStats(); break;
    case 'get_dashboard_stats': getDashboardStats(); break;
    case 'get_settings': getSettings(); break;
    case 'save_settings': saveSettings(); break;
    case 'webhook': handleWebhook(); break;
    case 'node_status': getNodeStatus(); break;
    case 'set_webhook_url': setWebhookUrl(); break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action: ' . $action]);
}

// ============ LEAD MANAGEMENT ============
function getLeads() {
    $db = getDB();
    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;
    $where = "1=1";
    $params = [];

    switch ($filter) {
        case 'replied': $where .= " AND status = 'replied'"; break;
        case 'sent': $where .= " AND status = 'sent'"; break;
        case 'pending': $where .= " AND status = 'pending'"; break;
        case 'has_website': $where .= " AND website IS NOT NULL AND website != ''"; break;
        case 'no_website': $where .= " AND (website IS NULL OR website = '')"; break;
    }

    if ($search) {
        $where .= " AND (business_name LIKE :search OR phone LIKE :search2 OR address LIKE :search3)";
        $params[':search'] = "%$search%";
        $params[':search2'] = "%$search%";
        $params[':search3'] = "%$search%";
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT * FROM leads WHERE $where ORDER BY updated_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $leads = $stmt->fetchAll();

    echo json_encode(['success' => true, 'leads' => $leads, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)]);
}

function getLead() {
    $db = getDB();
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['error' => 'Lead ID required']); return; }
    $stmt = $db->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([$id]);
    $lead = $stmt->fetch();
    if (!$lead) { echo json_encode(['error' => 'Lead not found']); return; }
    $msgStmt = $db->prepare("SELECT * FROM messages WHERE lead_id = ? ORDER BY created_at DESC LIMIT 50");
    $msgStmt->execute([$id]);
    echo json_encode(['success' => true, 'lead' => $lead, 'messages' => $msgStmt->fetchAll()]);
}

function updateLead() {
    $db = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $id = intval($data['id'] ?? 0);
    if (!$id) { echo json_encode(['error' => 'Lead ID required']); return; }
    $fields = []; $params = [];
    $allowed = ['business_name', 'phone', 'address', 'website', 'rating', 'reviews', 'status', 'notes'];
    foreach ($allowed as $field) {
        if (isset($data[$field])) { $fields[] = "$field = ?"; $params[] = $data[$field]; }
    }
    if (empty($fields)) { echo json_encode(['error' => 'No fields to update']); return; }
    $fields[] = "updated_at = NOW()";
    $params[] = $id;
    $stmt = $db->prepare("UPDATE leads SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
}

function deleteLead() {
    $db = getDB();
    $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['error' => 'Lead ID required']); return; }
    $stmt = $db->prepare("DELETE FROM leads WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
}


// ============ CSV IMPORT ============
function importCSV() {
    $db = getDB();
    if (!isset($_FILES['csv_file'])) { echo json_encode(['error' => 'No CSV file uploaded']); return; }
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle) { echo json_encode(['error' => 'Cannot read file']); return; }

    $header = fgetcsv($handle);
    if (!$header) { echo json_encode(['error' => 'Empty CSV']); return; }
    $header = array_map(function($h) { return strtolower(trim(str_replace([' ', '-'], '_', $h))); }, $header);

    $colMap = [
        'business_name' => array_search('business_name', $header) !== false ? array_search('business_name', $header) : array_search('name', $header),
        'address' => array_search('address', $header),
        'phone' => array_search('phone', $header),
        'website' => array_search('website', $header),
        'rating' => array_search('rating', $header),
        'reviews' => array_search('reviews', $header)
    ];

    $imported = 0; $skipped = 0;
    $stmt = $db->prepare("INSERT INTO leads (business_name, address, phone, website, rating, reviews, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW()) ON DUPLICATE KEY UPDATE business_name=VALUES(business_name), address=VALUES(address), website=VALUES(website), rating=VALUES(rating), reviews=VALUES(reviews), updated_at=NOW()");

    while (($row = fgetcsv($handle)) !== false) {
        $bName = $colMap['business_name'] !== false ? ($row[$colMap['business_name']] ?? '') : '';
        $addr = $colMap['address'] !== false ? ($row[$colMap['address']] ?? '') : '';
        $phone = $colMap['phone'] !== false ? ($row[$colMap['phone']] ?? '') : '';
        $web = $colMap['website'] !== false ? ($row[$colMap['website']] ?? '') : '';
        $rating = $colMap['rating'] !== false ? ($row[$colMap['rating']] ?? 0) : 0;
        $reviews = $colMap['reviews'] !== false ? ($row[$colMap['reviews']] ?? 0) : 0;

        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 10) $phone = '91' . $phone;
        if (substr($phone, 0, 1) === '0') $phone = '91' . substr($phone, 1);
        if (empty($phone) || strlen($phone) < 10) { $skipped++; continue; }

        try { $stmt->execute([$bName, $addr, $phone, $web, $rating, $reviews]); $imported++; }
        catch (Exception $e) { $skipped++; }
    }
    fclose($handle);
    echo json_encode(['success' => true, 'imported' => $imported, 'skipped' => $skipped]);
}

// ============ MESSAGES ============
function getMessages() {
    $db = getDB();
    $leadId = intval($_GET['lead_id'] ?? 0);
    $phone = $_GET['phone'] ?? '';

    if ($leadId) {
        $stmt = $db->prepare("SELECT * FROM messages WHERE lead_id = ? ORDER BY created_at ASC");
        $stmt->execute([$leadId]);
    } elseif ($phone) {
        $leadStmt = $db->prepare("SELECT id FROM leads WHERE phone = ? LIMIT 1");
        $leadStmt->execute([$phone]);
        $lead = $leadStmt->fetch();
        if ($lead) {
            $stmt = $db->prepare("SELECT * FROM messages WHERE lead_id = ? ORDER BY created_at ASC");
            $stmt->execute([$lead['id']]);
        } else { echo json_encode(['success' => true, 'messages' => []]); return; }
    } else { echo json_encode(['error' => 'lead_id or phone required']); return; }
    echo json_encode(['success' => true, 'messages' => $stmt->fetchAll()]);
}

function sendMessage() {
    $db = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $leadId = intval($data['lead_id'] ?? 0);
    $phone = $data['phone'] ?? '';
    $message = $data['message'] ?? '';
    if (empty($message)) { echo json_encode(['error' => 'Message required']); return; }

    if (!$phone && $leadId) {
        $stmt = $db->prepare("SELECT phone FROM leads WHERE id = ?");
        $stmt->execute([$leadId]);
        $lead = $stmt->fetch();
        if ($lead) $phone = $lead['phone'];
    }
    if (empty($phone)) { echo json_encode(['error' => 'Phone required']); return; }

    $response = httpPost(NODE_SERVER_URL . '/send-message', ['phone' => $phone, 'message' => $message]);

    if ($response && isset($response['success']) && $response['success']) {
        if (!$leadId) {
            $stmt = $db->prepare("SELECT id FROM leads WHERE phone = ? LIMIT 1");
            $stmt->execute([$phone]);
            $lead = $stmt->fetch();
            $leadId = $lead ? $lead['id'] : 0;
        }
        if ($leadId) {
            $db->prepare("INSERT INTO messages (lead_id, phone, direction, body, message_id, status, created_at) VALUES (?, ?, 'outbound', ?, ?, 'sent', NOW())")->execute([$leadId, $phone, $message, $response['messageId'] ?? '']);
            $db->prepare("UPDATE leads SET status='sent', last_contacted=NOW(), updated_at=NOW() WHERE id=?")->execute([$leadId]);
        }
        echo json_encode(['success' => true, 'messageId' => $response['messageId'] ?? null]);
    } else {
        echo json_encode(['success' => false, 'error' => $response['error'] ?? 'Send failed']);
    }
}


// ============ CAMPAIGN ============
function startCampaign() {
    $db = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $template = $data['template'] ?? '';
    $useAI = $data['use_ai'] ?? false;
    $filter = $data['filter'] ?? 'pending';
    $limit = min(intval($data['limit'] ?? DAILY_LIMIT), DAILY_LIMIT);
    if (empty($template) && !$useAI) { echo json_encode(['error' => 'Template or AI required']); return; }

    $where = "status = 'pending'";
    if ($filter === 'has_website') $where .= " AND website IS NOT NULL AND website != ''";
    if ($filter === 'no_website') $where .= " AND (website IS NULL OR website = '')";

    $stmt = $db->prepare("SELECT * FROM leads WHERE $where ORDER BY id ASC LIMIT ?");
    $stmt->execute([$limit]);
    $leads = $stmt->fetchAll();
    if (empty($leads)) { echo json_encode(['error' => 'No pending leads']); return; }

    $campStmt = $db->prepare("INSERT INTO campaigns (template, use_ai, filter_type, total_leads, status, created_at) VALUES (?, ?, ?, ?, 'running', NOW())");
    $campStmt->execute([$template, $useAI ? 1 : 0, $filter, count($leads)]);
    $campaignId = $db->lastInsertId();

    $queueStmt = $db->prepare("INSERT INTO campaign_queue (campaign_id, lead_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
    foreach ($leads as $lead) { $queueStmt->execute([$campaignId, $lead['id']]); }

    echo json_encode(['success' => true, 'campaign_id' => $campaignId, 'total_leads' => count($leads)]);
}

function getCampaignStatus() {
    $db = getDB();
    $campaignId = intval($_GET['campaign_id'] ?? 0);
    if (!$campaignId) {
        $stmt = $db->query("SELECT * FROM campaigns ORDER BY id DESC LIMIT 1");
        $campaign = $stmt->fetch();
    } else {
        $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = ?");
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch();
    }
    if ($campaign) {
        $qStmt = $db->prepare("SELECT status, COUNT(*) as count FROM campaign_queue WHERE campaign_id = ? GROUP BY status");
        $qStmt->execute([$campaign['id']]);
        echo json_encode(['success' => true, 'campaign' => $campaign, 'queue' => $qStmt->fetchAll()]);
    } else { echo json_encode(['success' => true, 'campaign' => null]); }
}

function stopCampaign() {
    $db = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $campaignId = intval($data['campaign_id'] ?? 0);
    if (!$campaignId) {
        $stmt = $db->query("SELECT id FROM campaigns WHERE status = 'running' ORDER BY id DESC LIMIT 1");
        $c = $stmt->fetch();
        $campaignId = $c ? $c['id'] : 0;
    }
    if ($campaignId) {
        $db->prepare("UPDATE campaigns SET status='stopped', updated_at=NOW() WHERE id=?")->execute([$campaignId]);
        $db->prepare("UPDATE campaign_queue SET status='cancelled' WHERE campaign_id=? AND status='pending'")->execute([$campaignId]);
        echo json_encode(['success' => true, 'campaign_id' => $campaignId]);
    } else { echo json_encode(['error' => 'No running campaign']); }
}

function processCampaignQueue() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM campaigns WHERE status = 'running' ORDER BY id DESC LIMIT 1");
    $campaign = $stmt->fetch();
    if (!$campaign) { echo json_encode(['success' => true, 'status' => 'no_campaign']); return; }

    $qStmt = $db->prepare("SELECT cq.*, l.* FROM campaign_queue cq JOIN leads l ON l.id = cq.lead_id WHERE cq.campaign_id = ? AND cq.status = 'pending' ORDER BY cq.id ASC LIMIT 1");
    $qStmt->execute([$campaign['id']]);
    $item = $qStmt->fetch();

    if (!$item) {
        $db->prepare("UPDATE campaigns SET status='completed', updated_at=NOW() WHERE id=?")->execute([$campaign['id']]);
        echo json_encode(['success' => true, 'status' => 'campaign_completed']);
        return;
    }

    $message = $campaign['template'];
    if ($campaign['use_ai'] && !empty(GROQ_API_KEY)) {
        $ai = generateAIMessage($item['business_name'], $item['website'] ?? '', $campaign['template'], $item['address'] ?? '');
        if ($ai) $message = $ai;
    } else {
        $message = str_replace(['{business_name}', '{name}', '{website}', '{address}'], [$item['business_name'], $item['business_name'], $item['website'] ?? '', $item['address'] ?? ''], $message);
    }

    $response = httpPost(NODE_SERVER_URL . '/send-message', ['phone' => $item['phone'], 'message' => $message]);

    if ($response && isset($response['success']) && $response['success']) {
        $db->prepare("UPDATE campaign_queue SET status='sent', sent_at=NOW() WHERE id=?")->execute([$item['id']]);
        $db->prepare("INSERT INTO messages (lead_id, phone, direction, body, message_id, status, created_at) VALUES (?, ?, 'outbound', ?, ?, 'sent', NOW())")->execute([$item['lead_id'], $item['phone'], $message, $response['messageId'] ?? '']);
        $db->prepare("UPDATE leads SET status='sent', last_contacted=NOW(), updated_at=NOW() WHERE id=?")->execute([$item['lead_id']]);
        $db->prepare("UPDATE campaigns SET sent_count=sent_count+1, updated_at=NOW() WHERE id=?")->execute([$campaign['id']]);
        echo json_encode(['success' => true, 'status' => 'message_sent', 'lead' => $item['business_name'], 'phone' => $item['phone'], 'next_delay' => rand(MIN_DELAY, MAX_DELAY)]);
    } else {
        $db->prepare("UPDATE campaign_queue SET status='failed', sent_at=NOW() WHERE id=?")->execute([$item['id']]);
        echo json_encode(['success' => false, 'error' => $response['error'] ?? 'Send failed']);
    }
}

function generateAIMessage($businessName, $website, $template, $address) {
    if (empty(GROQ_API_KEY)) return null;
    $prompt = "Generate a short WhatsApp outreach message (under 100 words). Business: $businessName.";
    if ($address) $prompt .= " Location: $address.";
    if ($website) $prompt .= " Website: $website.";
    if ($template) $prompt .= " Style: $template.";
    $prompt .= " Generate ONLY the message text.";
    $response = httpPost('https://api.groq.com/openai/v1/chat/completions', [
        'model' => GROQ_MODEL,
        'messages' => [['role' => 'system', 'content' => 'Write concise WhatsApp outreach messages.'], ['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.8, 'max_tokens' => 200
    ], ['Authorization: Bearer ' . GROQ_API_KEY]);
    if ($response && isset($response['choices'][0]['message']['content'])) return trim($response['choices'][0]['message']['content']);
    return null;
}

function generateMessage() {
    $data = json_decode(file_get_contents('php://input'), true);
    $msg = generateAIMessage($data['business_name'] ?? '', $data['website'] ?? '', $data['template'] ?? '', $data['address'] ?? '');
    if ($msg) echo json_encode(['success' => true, 'message' => $msg]);
    else echo json_encode(['error' => 'AI generation failed']);
}


// ============ STATS ============
function getStats() {
    $db = getDB();
    $today = date('Y-m-d');
    $stats = [];
    $stats['total_leads'] = $db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE direction='outbound' AND DATE(created_at)=?");
    $stmt->execute([$today]); $stats['sent_today'] = $stmt->fetchColumn();
    $stats['total_replies'] = $db->query("SELECT COUNT(*) FROM messages WHERE direction='inbound'")->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE direction='inbound' AND DATE(created_at)=?");
    $stmt->execute([$today]); $stats['replies_today'] = $stmt->fetchColumn();
    $stats['total_sent'] = $db->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn();
    $stats['reply_rate'] = $stats['total_sent'] > 0 ? round(($stats['total_replies'] / $stats['total_sent']) * 100, 1) : 0;
    $stats['remaining'] = $db->query("SELECT COUNT(*) FROM leads WHERE status='pending'")->fetchColumn();
    echo json_encode(['success' => true, 'stats' => $stats]);
}

function getDashboardStats() {
    $db = getDB();
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE direction='outbound' AND DATE(created_at)=?");
    $stmt->execute([$today]); $sentToday = $stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE direction='inbound' AND DATE(created_at)=?");
    $stmt->execute([$today]); $repliesToday = $stmt->fetchColumn();
    $totalSent = $db->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn();
    $totalReplies = $db->query("SELECT COUNT(*) FROM messages WHERE direction='inbound'")->fetchColumn();
    $remaining = $db->query("SELECT COUNT(*) FROM leads WHERE status='pending'")->fetchColumn();
    $replyRate = $totalSent > 0 ? round(($totalReplies / $totalSent) * 100, 1) : 0;
    echo json_encode(['success' => true, 'sent_today' => $sentToday, 'replies_today' => $repliesToday, 'reply_rate' => $replyRate, 'remaining' => $remaining, 'total_leads' => $db->query("SELECT COUNT(*) FROM leads")->fetchColumn()]);
}

// ============ SETTINGS ============
function getSettings() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM settings ORDER BY setting_key");
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];
    $defaults = ['node_server_url' => NODE_SERVER_URL, 'groq_api_key' => GROQ_API_KEY ? '***configured***' : '', 'groq_model' => GROQ_MODEL, 'min_delay' => MIN_DELAY, 'max_delay' => MAX_DELAY, 'daily_limit' => DAILY_LIMIT, 'webhook_url' => '', 'company_name' => 'WaLead CRM', 'sender_name' => ''];
    foreach ($defaults as $key => $val) { if (!isset($settings[$key])) $settings[$key] = $val; }
    echo json_encode(['success' => true, 'settings' => $settings]);
}

function saveSettings() {
    $db = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data)) { echo json_encode(['error' => 'No data']); return; }
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()");
    $saved = 0;
    foreach ($data as $key => $value) { if ($key === 'action') continue; $stmt->execute([$key, $value]); $saved++; }
    if (isset($data['webhook_url']) && $data['webhook_url']) httpPost(NODE_SERVER_URL . '/set-webhook', ['url' => $data['webhook_url']]);
    echo json_encode(['success' => true, 'saved' => $saved]);
}

// ============ WEBHOOK ============
function handleWebhook() {
    $db = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { echo json_encode(['error' => 'Invalid data']); return; }
    $event = $data['event'] ?? '';
    $phone = $data['phone'] ?? '';
    $message = $data['message'] ?? '';

    $db->prepare("INSERT INTO webhook_logs (event, phone, payload, created_at) VALUES (?, ?, ?, NOW())")->execute([$event, $phone, json_encode($data)]);

    if ($event === 'message_received' && $phone && $message) {
        $phoneClean = preg_replace('/[^0-9]/', '', $phone);
        $leadStmt = $db->prepare("SELECT id FROM leads WHERE phone = ? OR phone = ? LIMIT 1");
        $leadStmt->execute([$phone, $phoneClean]);
        $lead = $leadStmt->fetch();

        if ($lead) {
            $db->prepare("INSERT INTO messages (lead_id, phone, direction, body, status, created_at) VALUES (?, ?, 'inbound', ?, 'received', NOW())")->execute([$lead['id'], $phoneClean, $message]);
            $db->prepare("UPDATE leads SET status='replied', updated_at=NOW() WHERE id=?")->execute([$lead['id']]);
            echo json_encode(['success' => true, 'action' => 'stored', 'lead_id' => $lead['id']]);
        } else {
            $db->prepare("INSERT INTO leads (business_name, phone, status, created_at, updated_at) VALUES (?, ?, 'replied', NOW(), NOW())")->execute(['Unknown - ' . $phoneClean, $phoneClean]);
            $newId = $db->lastInsertId();
            $db->prepare("INSERT INTO messages (lead_id, phone, direction, body, status, created_at) VALUES (?, ?, 'inbound', ?, 'received', NOW())")->execute([$newId, $phoneClean, $message]);
            echo json_encode(['success' => true, 'action' => 'new_lead', 'lead_id' => $newId]);
        }
    } else { echo json_encode(['success' => true, 'action' => 'logged']); }
}

function getNodeStatus() {
    $response = httpGet(NODE_SERVER_URL . '/status');
    echo $response ? json_encode(['success' => true, 'node' => $response]) : json_encode(['success' => false, 'error' => 'Cannot reach Node server']);
}

function setWebhookUrl() {
    $data = json_decode(file_get_contents('php://input'), true);
    $response = httpPost(NODE_SERVER_URL . '/set-webhook', ['url' => $data['url'] ?? '']);
    echo json_encode(['success' => true, 'response' => $response]);
}

// ============ HTTP HELPERS ============
function httpPost($url, $data, $extraHeaders = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $extraHeaders), CURLOPT_SSL_VERIFYPEER => false]);
    $response = curl_exec($ch); curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

function httpGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_SSL_VERIFYPEER => false]);
    $response = curl_exec($ch); curl_close($ch);
    return $response ? json_decode($response, true) : null;
}
?>
