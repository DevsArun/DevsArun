<?php
require_once __DIR__ . '/config/app.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp CRM — Command Center</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>

<div class="crm-layout">

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- LEFT SIDEBAR -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <aside class="sidebar">
        <!-- Brand -->
        <div class="sidebar-brand">
            <h1>
                <span class="brand-icon">
                    <svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.654-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>
                </span>
                WA CRM
            </h1>
        </div>

        <!-- Engine Status -->
        <div class="engine-status" id="engineStatus">
            <div class="status-row">
                <span class="label">Engine</span>
                <span id="engineStatusText"><span class="status-dot pending"></span>Checking...</span>
            </div>
            <div class="status-row">
                <span class="label">WhatsApp</span>
                <span id="waStatusText"><span class="status-dot pending"></span>Checking...</span>
            </div>
        </div>

        <!-- Navigation -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Navigation</div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-item active" data-filter="all">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                All Leads
                <span class="badge" id="navTotalBadge">0</span>
            </div>
            <div class="nav-item" data-filter="replied">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Replied
                <span class="badge" id="navRepliedBadge" style="display:none">0</span>
            </div>
            <div class="nav-item" data-filter="sent">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Sent
            </div>
            <div class="nav-item" data-filter="pending">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Pending
            </div>
            <div class="nav-item" data-filter="failed">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                Failed
            </div>
        </nav>

        <!-- KPIs -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Today's Metrics</div>
        </div>
        <div class="kpi-grid">
            <div class="kpi-card highlight">
                <div class="kpi-value" id="kpiSentToday">0</div>
                <div class="kpi-label">Sent Today</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value" id="kpiReplies">0</div>
                <div class="kpi-label">Replies</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value" id="kpiReplyRate">0%</div>
                <div class="kpi-label">Reply Rate</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value" id="kpiRemaining">0</div>
                <div class="kpi-label">Remaining</div>
            </div>
        </div>

        <!-- Actions -->
        <div class="sidebar-actions">
            <button class="btn-campaign" id="btnStartCampaign" onclick="startCampaign()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Start Campaign
            </button>
            <button class="btn-upload" id="btnUpload" onclick="openUploadModal()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload CSV
            </button>
        </div>
    </aside>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- MIDDLE - LEAD LIST -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <section class="lead-list-panel">
        <div class="lead-list-header">
            <h2>Conversations</h2>
            <div class="search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" id="searchInput" placeholder="Search leads..." autocomplete="off">
            </div>
        </div>

        <!-- Filter Pills -->
        <div class="filter-pills">
            <span class="filter-pill active" data-filter="all">All</span>
            <span class="filter-pill" data-filter="replied">Replied</span>
            <span class="filter-pill" data-filter="sent">Sent</span>
            <span class="filter-pill" data-filter="pending">Pending</span>
            <span class="filter-pill" data-filter="has_website">Has Website</span>
            <span class="filter-pill" data-filter="no_website">No Website</span>
        </div>

        <!-- Lead Items -->
        <div class="lead-list-scroll" id="leadListScroll">
            <!-- Populated by JS -->
            <div class="chat-empty" id="leadListEmpty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <p>Upload CSV to import leads</p>
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- RIGHT - CONVERSATION -->
    <!-- ═══════════════════════════════════════════════════════ -->
    <section class="conversation-panel" style="position:relative;">

        <!-- No conversation selected state -->
        <div class="chat-empty" id="convoEmpty" style="height:100%">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:56px;height:56px;opacity:0.25">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <p style="font-size:15px;font-weight:500;color:var(--text-secondary)">Select a lead to view conversation</p>
            <p style="font-size:12px;color:var(--text-muted)">Click on any lead from the list</p>
        </div>

        <!-- Active Conversation (hidden by default) -->
        <div id="convoActive" style="display:none;flex-direction:column;height:100%;">
            <!-- Header -->
            <div class="convo-header">
                <div class="lead-avatar" id="convoAvatar">MT</div>
                <div class="lead-info">
                    <h3 id="convoName">Business Name</h3>
                    <div class="lead-subtitle" id="convoSubtitle">Locality • Status</div>
                </div>
                <button class="lead-details-btn" onclick="toggleDetails()">Details</button>
            </div>

            <!-- Messages -->
            <div class="chat-messages" id="chatMessages">
                <!-- Populated by JS -->
            </div>

            <!-- Input -->
            <div class="chat-input-area">
                <textarea id="msgInput" placeholder="Type your message..." rows="1" onkeydown="handleMsgKeydown(event)"></textarea>
                <button class="btn-send" id="btnSend" onclick="sendManualMessage()">
                    <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </div>
        </div>

        <!-- Details Slide Panel -->
        <div class="details-panel" id="detailsPanel">
            <button class="close-btn" onclick="toggleDetails()">×</button>
            <div id="detailsContent">
                <!-- Populated by JS -->
            </div>
        </div>
    </section>

</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- UPLOAD MODAL -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="uploadModal">
    <div class="modal-box">
        <h3>Upload Leads CSV</h3>
        <p>Import your business leads. Required columns: Business Name, Phone</p>
        <div class="upload-zone" id="uploadZone" onclick="document.getElementById('csvFileInput').click()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:36px;height:36px;margin:0 auto 10px;display:block;color:#9ca3af"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <p>Click or drag CSV file here</p>
            <p class="file-types">Supports .csv files up to 5MB</p>
        </div>
        <input type="file" id="csvFileInput" accept=".csv" style="display:none" onchange="handleFileSelect(event)">
        <div id="uploadProgress" style="display:none;margin-top:16px;">
            <div style="height:4px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
                <div id="uploadBar" style="height:100%;background:var(--brand-500);width:0%;transition:width 0.3s;"></div>
            </div>
            <p id="uploadStatus" style="font-size:12px;color:var(--text-secondary);margin-top:8px;">Uploading...</p>
        </div>
        <div style="display:flex;gap:8px;margin-top:20px;justify-content:flex-end;">
            <button onclick="closeUploadModal()" style="padding:8px 16px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);font-size:13px;cursor:pointer;">Cancel</button>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Socket.io from VPS -->
<script src="<?= SOCKET_URL ?>/socket.io/socket.io.js"></script>
<script>
    // Configuration from PHP
    const CONFIG = {
        socketUrl: '<?= SOCKET_URL ?>',
        apiBase: './api'
    };
</script>
<script src="assets/js/dashboard.js"></script>

</body>
</html>
