<?php
/**
 * API: Get Leads List
 * Supports search, filter, pagination
 * 
 * Params:
 *  - search: string (business name, phone, locality)
 *  - status: outreach_status filter
 *  - website: has_website|no_website
 *  - wa_status: valid|invalid|pending
 *  - page: int (default 1)
 *  - limit: int (default 30)
 *  - sort: field name
 *  - order: asc|desc
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

setCorsHeaders();

try {
    $search   = trim($_GET['search'] ?? '');
    $status   = trim($_GET['status'] ?? '');
    $website  = trim($_GET['website'] ?? '');
    $waStatus = trim($_GET['wa_status'] ?? '');
    $page     = max(1, intval($_GET['page'] ?? 1));
    $limit    = min(100, max(1, intval($_GET['limit'] ?? 30)));
    $sort     = $_GET['sort'] ?? 'created_at';
    $order    = (strtolower($_GET['order'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
    $offset   = ($page - 1) * $limit;

    // Whitelist sortable columns
    $sortableColumns = ['business_name', 'rating', 'review_count', 'outreach_status', 'created_at', 'last_contacted_at'];
    if (!in_array($sort, $sortableColumns)) {
        $sort = 'created_at';
    }

    // Build query — only show leads with valid phone numbers
    $where = ["l.is_active = 1", "l.phone_clean IS NOT NULL", "l.phone_clean != ''"];
    $params = [];

    if (!empty($search)) {
        $where[] = "(l.business_name LIKE :search OR l.phone_clean LIKE :search2 OR l.locality LIKE :search3 OR l.address LIKE :search4)";
        $params[':search'] = "%{$search}%";
        $params[':search2'] = "%{$search}%";
        $params[':search3'] = "%{$search}%";
        $params[':search4'] = "%{$search}%";
    }

    if (!empty($status) && in_array($status, ['pending', 'queued', 'sent', 'replied', 'failed', 'skipped'])) {
        $where[] = "l.outreach_status = :status";
        $params[':status'] = $status;
    }

    if (!empty($website) && in_array($website, ['has_website', 'no_website'])) {
        $where[] = "l.website_status = :website";
        $params[':website'] = $website;
    }

    if (!empty($waStatus) && in_array($waStatus, ['valid', 'invalid', 'pending'])) {
        $where[] = "l.whatsapp_status = :wa_status";
        $params[':wa_status'] = $waStatus;
    }

    $whereClause = implode(' AND ', $where);

    // Get total count
    $countSQL = "SELECT COUNT(*) as total FROM leads l WHERE {$whereClause}";
    $total = dbQueryOne($countSQL, $params)['total'];

    // Get leads — sort by most recent activity first
    $sql = "SELECT l.*, 
            (SELECT message_text FROM messages WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM messages WHERE lead_id = l.id ORDER BY created_at DESC LIMIT 1) as last_message_at,
            (SELECT COUNT(*) FROM messages WHERE lead_id = l.id AND direction = 'inbound' AND is_read = 0) as unread_count
            FROM leads l 
            WHERE {$whereClause}
            ORDER BY 
                COALESCE((SELECT MAX(created_at) FROM messages WHERE lead_id = l.id), l.updated_at, l.created_at) DESC
            LIMIT {$limit} OFFSET {$offset}";

    $leads = dbQuery($sql, $params);

    // Format leads
    $formatted = array_map(function($lead) {
        return [
            'id'              => (int)$lead['id'],
            'business_name'   => $lead['business_name'],
            'locality'        => $lead['locality'],
            'city'            => $lead['city'],
            'phone'           => $lead['phone_clean'],
            'website_status'  => $lead['website_status'],
            'website_url'     => $lead['website_url'],
            'rating'          => $lead['rating'] ? floatval($lead['rating']) : null,
            'review_count'    => (int)$lead['review_count'],
            'outreach_status' => $lead['outreach_status'],
            'whatsapp_status' => $lead['whatsapp_status'],
            'pitch_type'      => $lead['pitch_type'],
            'last_message'    => $lead['last_message'] ? truncateText($lead['last_message'], 80) : null,
            'last_message_at' => $lead['last_message_at'],
            'last_activity'   => formatTime($lead['last_message_at'] ?? $lead['last_contacted_at'] ?? $lead['created_at']),
            'unread_count'    => (int)$lead['unread_count'],
            'created_at'      => $lead['created_at']
        ];
    }, $leads);

    jsonResponse([
        'success' => true,
        'leads'   => $formatted,
        'pagination' => [
            'page'       => $page,
            'limit'      => $limit,
            'total'      => (int)$total,
            'pages'      => ceil($total / $limit),
            'has_more'   => ($page * $limit) < $total
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Failed to fetch leads: ' . $e->getMessage()], 500);
}
