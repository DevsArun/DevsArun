/**
 * ============================================================
 * WhatsApp CRM - Dashboard Frontend Logic
 * ============================================================
 * Vanilla JS | Socket.io | Fetch API
 * Premium Silicon Valley Agency Quality
 */

// ============================================================
// STATE
// ============================================================
const state = {
    leads: [],
    currentLeadId: null,
    currentFilter: 'all',
    searchQuery: '',
    messages: [],
    stats: {},
    socketConnected: false,
    engineOnline: false,
    waReady: false
};

// ============================================================
// INITIALIZATION
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    initSocket();
    loadStats();
    loadLeads();
    initSearch();
    initFilters();
    initUploadDrag();
    initTextareaAutosize();

    // Periodic refresh every 30 seconds
    setInterval(refreshSync, 30000);
});

// ============================================================
// SOCKET.IO CONNECTION
// ============================================================
let socket = null;

function initSocket() {
    try {
        socket = io(CONFIG.socketUrl, {
            transports: ['websocket', 'polling'],
            reconnection: true,
            reconnectionDelay: 3000,
            timeout: 10000
        });

        socket.on('connect', () => {
            state.socketConnected = true;
            updateEngineStatus();
            console.log('[Socket] Connected');
        });

        socket.on('disconnect', () => {
            state.socketConnected = false;
            updateEngineStatus();
            console.log('[Socket] Disconnected');
        });

        // WhatsApp status updates
        socket.on('wa:status', (data) => {
            state.waReady = (data.status === 'ready');
            state.engineOnline = true;
            updateEngineStatus();
        });

        // Real-time inbound message
        socket.on('message:inbound', (data) => {
            handleRealtimeInbound(data);
        });

        // Real-time outbound sent
        socket.on('message:sent', (data) => {
            handleRealtimeOutbound(data);
        });

        socket.on('connect_error', () => {
            state.socketConnected = false;
            state.engineOnline = false;
            updateEngineStatus();
        });

    } catch (e) {
        console.error('[Socket] Init failed:', e);
        state.socketConnected = false;
        updateEngineStatus();
    }
}

// ============================================================
// ENGINE STATUS UI
// ============================================================
function updateEngineStatus() {
    const engineEl = document.getElementById('engineStatusText');
    const waEl = document.getElementById('waStatusText');

    if (state.engineOnline || state.socketConnected) {
        engineEl.innerHTML = '<span class="status-dot online"></span>Online';
    } else {
        engineEl.innerHTML = '<span class="status-dot offline"></span>Offline';
    }

    if (state.waReady) {
        waEl.innerHTML = '<span class="status-dot online"></span>Connected';
    } else if (state.engineOnline) {
        waEl.innerHTML = '<span class="status-dot pending"></span>Not Ready';
    } else {
        waEl.innerHTML = '<span class="status-dot offline"></span>Disconnected';
    }
}

// ============================================================
// API HELPERS
// ============================================================
async function apiGet(endpoint, params = {}) {
    const url = new URL(CONFIG.apiBase + '/' + endpoint, window.location.href);
    Object.entries(params).forEach(([k, v]) => {
        if (v !== '' && v !== null && v !== undefined) url.searchParams.set(k, v);
    });

    try {
        const res = await fetch(url.toString());
        return await res.json();
    } catch (e) {
        console.error(`[API] GET ${endpoint} failed:`, e);
        return { success: false, error: e.message };
    }
}

async function apiPost(endpoint, body = {}) {
    try {
        const res = await fetch(CONFIG.apiBase + '/' + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        return await res.json();
    } catch (e) {
        console.error(`[API] POST ${endpoint} failed:`, e);
        return { success: false, error: e.message };
    }
}

// ============================================================
// LOAD STATS
// ============================================================
async function loadStats() {
    const res = await apiGet('get_stats.php');
    if (!res.success) return;

    state.stats = res.stats;
    document.getElementById('kpiSentToday').textContent = res.stats.sent_today;
    document.getElementById('kpiReplies').textContent = res.stats.replied;
    document.getElementById('kpiReplyRate').textContent = res.stats.reply_rate + '%';
    document.getElementById('kpiRemaining').textContent = res.stats.daily_remaining;
    document.getElementById('navTotalBadge').textContent = res.stats.total_leads;

    if (res.stats.unread > 0) {
        const badge = document.getElementById('navRepliedBadge');
        badge.textContent = res.stats.unread;
        badge.style.display = '';
    }
}

// ============================================================
// LOAD LEADS
// ============================================================
async function loadLeads() {
    const params = { limit: 50 };

    if (state.currentFilter && state.currentFilter !== 'all') {
        if (['has_website', 'no_website'].includes(state.currentFilter)) {
            params.website = state.currentFilter;
        } else {
            params.status = state.currentFilter;
        }
    }

    if (state.searchQuery) {
        params.search = state.searchQuery;
    }

    const res = await apiGet('get_leads.php', params);
    if (!res.success) return;

    state.leads = res.leads;
    renderLeadList();
}

// ============================================================
// RENDER LEAD LIST
// ============================================================
function renderLeadList() {
    const container = document.getElementById('leadListScroll');
    const emptyEl = document.getElementById('leadListEmpty');

    if (state.leads.length === 0) {
        emptyEl.style.display = 'flex';
        container.innerHTML = '';
        container.appendChild(emptyEl);
        return;
    }

    emptyEl.style.display = 'none';

    const html = state.leads.map(lead => {
        const initials = getInitials(lead.business_name);
        const isActive = lead.id === state.currentLeadId;
        const hasUnread = lead.unread_count > 0;
        const statusTag = getStatusTag(lead.outreach_status);
        const websiteTag = lead.website_status === 'has_website'
            ? '<span class="lead-tag website">Web</span>'
            : '<span class="lead-tag no-website">No Web</span>';

        return `
            <div class="lead-item ${isActive ? 'active' : ''} ${hasUnread ? 'has-unread' : ''}" 
                 onclick="selectLead(${lead.id})" data-lead-id="${lead.id}">
                <div class="lead-avatar">${initials}</div>
                <div class="lead-item-content">
                    <div class="lead-item-top">
                        <span class="lead-item-name">${escHtml(lead.business_name)}</span>
                        <span class="lead-item-time">${lead.last_activity}</span>
                    </div>
                    <div class="lead-item-preview">${lead.last_message ? escHtml(lead.last_message) : (lead.locality || 'No messages yet')}</div>
                    <div class="lead-item-meta">
                        ${statusTag}
                        ${websiteTag}
                        ${hasUnread ? `<span class="unread-badge">${lead.unread_count}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');

    container.innerHTML = html;
}

function getStatusTag(status) {
    const map = {
        'sent': '<span class="lead-tag sent">Sent</span>',
        'replied': '<span class="lead-tag replied">Replied</span>',
        'pending': '<span class="lead-tag pending">Pending</span>',
        'failed': '<span class="lead-tag failed">Failed</span>',
        'skipped': '<span class="lead-tag skipped">Skipped</span>',
        'queued': '<span class="lead-tag pending">Queued</span>'
    };
    return map[status] || '';
}

function getInitials(name) {
    return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase().substring(0, 2);
}

// ============================================================
// SELECT LEAD - Load Conversation
// ============================================================
async function selectLead(leadId) {
    state.currentLeadId = leadId;

    // Update UI active state
    document.querySelectorAll('.lead-item').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.leadId) === leadId);
    });

    // Show conversation area
    document.getElementById('convoEmpty').style.display = 'none';
    const convoActive = document.getElementById('convoActive');
    convoActive.style.display = 'flex';

    // Load lead details for header
    const lead = state.leads.find(l => l.id === leadId);
    if (lead) {
        document.getElementById('convoAvatar').textContent = getInitials(lead.business_name);
        document.getElementById('convoName').textContent = lead.business_name;
        document.getElementById('convoSubtitle').textContent =
            `${lead.locality || lead.city || ''} • ${lead.outreach_status} • ${lead.rating ? '★' + lead.rating : ''}`;
    }

    // Load messages
    await loadMessages(leadId);

    // Mark as read
    await apiPost('mark_read.php', { lead_id: leadId });

    // Update unread badge
    const leadItem = state.leads.find(l => l.id === leadId);
    if (leadItem) {
        leadItem.unread_count = 0;
        renderLeadList();
    }
}

// ============================================================
// LOAD MESSAGES
// ============================================================
async function loadMessages(leadId) {
    const res = await apiGet('get_messages.php', { lead_id: leadId });
    if (!res.success) return;

    state.messages = res.messages;
    renderMessages();
}

function renderMessages() {
    const container = document.getElementById('chatMessages');

    if (state.messages.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <p style="font-size:13px;">No messages yet</p>
            </div>
        `;
        return;
    }

    const html = state.messages.map(msg => {
        const isOutbound = msg.direction === 'outbound';
        const firstBadge = msg.is_first_outreach
            ? '<div class="msg-first-badge">First Outreach</div>'
            : '';

        return `
            <div class="msg-bubble ${isOutbound ? 'outbound' : 'inbound'}">
                ${firstBadge}
                ${escHtml(msg.message)}
                <div class="msg-time">${msg.time_display}</div>
            </div>
        `;
    }).join('');

    container.innerHTML = html;

    // Scroll to bottom
    container.scrollTop = container.scrollHeight;
}

// ============================================================
// SEND MANUAL MESSAGE
// ============================================================
async function sendManualMessage() {
    const input = document.getElementById('msgInput');
    const message = input.value.trim();

    if (!message || !state.currentLeadId) return;

    const btn = document.getElementById('btnSend');
    btn.disabled = true;
    input.value = '';
    input.style.height = '42px';

    // Optimistic UI - show message immediately
    const tempMsg = {
        id: Date.now(),
        direction: 'outbound',
        sender: 'user',
        message: message,
        is_first_outreach: false,
        time_display: new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
    };
    state.messages.push(tempMsg);
    renderMessages();

    // Send to API
    const res = await apiPost('send_manual.php', {
        lead_id: state.currentLeadId,
        message: message
    });

    btn.disabled = false;

    if (res.success) {
        showToast('Message sent', 'success');
    } else {
        showToast('Failed to send: ' + (res.error || 'Unknown error'), 'error');
        // Remove optimistic message
        state.messages.pop();
        renderMessages();
    }
}

function handleMsgKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendManualMessage();
    }
}

// ============================================================
// SEARCH
// ============================================================
let searchTimeout = null;

function initSearch() {
    const input = document.getElementById('searchInput');
    input.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            state.searchQuery = input.value.trim();
            loadLeads();
        }, 300);
    });
}

// ============================================================
// FILTERS
// ============================================================
function initFilters() {
    // Filter pills
    document.querySelectorAll('.filter-pill').forEach(pill => {
        pill.addEventListener('click', () => {
            document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            state.currentFilter = pill.dataset.filter;
            loadLeads();
        });
    });

    // Nav items
    document.querySelectorAll('.nav-item[data-filter]').forEach(item => {
        item.addEventListener('click', () => {
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            item.classList.add('active');
            state.currentFilter = item.dataset.filter;

            // Sync filter pills
            document.querySelectorAll('.filter-pill').forEach(p => {
                p.classList.toggle('active', p.dataset.filter === state.currentFilter);
            });

            loadLeads();
        });
    });
}

// ============================================================
// CAMPAIGN
// ============================================================
async function startCampaign() {
    const btn = document.getElementById('btnStartCampaign');

    if (!state.waReady && !state.socketConnected) {
        showToast('WhatsApp engine is not ready. Connect first.', 'error');
        return;
    }

    if (!confirm('Start campaign? This will send outreach messages to pending leads.')) return;

    btn.disabled = true;
    btn.innerHTML = '<svg class="animate-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" opacity="0.3"/><path d="M12 2a10 10 0 0 1 10 10"/></svg> Running...';

    try {
        const res = await fetch('./scripts/campaign.php?limit=5', { method: 'GET' });
        const data = await res.json();

        if (data.success) {
            showToast(`Campaign: ${data.stats.sent} sent, ${data.stats.invalid} invalid`, 'success');
            loadStats();
            loadLeads();
        } else {
            showToast('Campaign error: ' + (data.error || 'Unknown'), 'error');
        }
    } catch (e) {
        showToast('Campaign request failed', 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg> Start Campaign';
}

// ============================================================
// CSV UPLOAD
// ============================================================
function openUploadModal() {
    document.getElementById('uploadModal').classList.add('active');
}

function closeUploadModal() {
    document.getElementById('uploadModal').classList.remove('active');
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('csvFileInput').value = '';
}

function initUploadDrag() {
    const zone = document.getElementById('uploadZone');

    zone.addEventListener('dragover', (e) => {
        e.preventDefault();
        zone.classList.add('dragover');
    });

    zone.addEventListener('dragleave', () => {
        zone.classList.remove('dragover');
    });

    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file) uploadCSV(file);
    });
}

function handleFileSelect(e) {
    const file = e.target.files[0];
    if (file) uploadCSV(file);
}

async function uploadCSV(file) {
    if (!file.name.endsWith('.csv')) {
        showToast('Only .csv files are accepted', 'error');
        return;
    }

    const progressEl = document.getElementById('uploadProgress');
    const barEl = document.getElementById('uploadBar');
    const statusEl = document.getElementById('uploadStatus');

    progressEl.style.display = 'block';
    barEl.style.width = '30%';
    statusEl.textContent = 'Uploading...';

    const formData = new FormData();
    formData.append('csv_file', file);

    try {
        barEl.style.width = '60%';
        statusEl.textContent = 'Processing...';

        const res = await fetch('./upload.php', {
            method: 'POST',
            body: formData
        });

        const data = await res.json();

        barEl.style.width = '100%';

        if (data.success) {
            const stats = data.stats || {};
            statusEl.textContent = `Done! Imported: ${stats.imported || 0} | Duplicates: ${stats.duplicate || 0}`;
            showToast(`CSV imported: ${stats.imported || 0} leads added`, 'success');
            setTimeout(() => {
                closeUploadModal();
                loadStats();
                loadLeads();
            }, 2000);
        } else {
            statusEl.textContent = 'Error: ' + (data.error || 'Import failed');
            showToast('Import failed: ' + (data.error || ''), 'error');
        }
    } catch (e) {
        statusEl.textContent = 'Upload failed';
        showToast('Upload failed: ' + e.message, 'error');
    }
}

// ============================================================
// DETAILS PANEL
// ============================================================
async function toggleDetails() {
    const panel = document.getElementById('detailsPanel');
    panel.classList.toggle('open');

    if (panel.classList.contains('open') && state.currentLeadId) {
        const res = await apiGet('get_lead_details.php', { id: state.currentLeadId });
        if (res.success) {
            renderDetails(res.lead);
        }
    }
}

function renderDetails(lead) {
    const container = document.getElementById('detailsContent');
    container.innerHTML = `
        <div style="margin-bottom:24px;">
            <div style="font-size:18px;font-weight:700;margin-bottom:4px;">${escHtml(lead.business_name)}</div>
            <div style="font-size:12px;color:var(--text-secondary);">${escHtml(lead.address || '')}</div>
        </div>

        <div class="details-section">
            <h4>Contact</h4>
            <div class="details-row"><span class="label">Phone</span><span class="value">${lead.phone || 'N/A'}</span></div>
            <div class="details-row"><span class="label">WhatsApp</span><span class="value">${lead.whatsapp_status}</span></div>
            <div class="details-row"><span class="label">Website</span><span class="value">${lead.website_url ? '<a href="'+lead.website_url+'" target="_blank" style="color:var(--brand-600)">Visit</a>' : 'None'}</span></div>
        </div>

        <div class="details-section">
            <h4>Business Info</h4>
            <div class="details-row"><span class="label">Rating</span><span class="value">${lead.rating ? '★ ' + lead.rating : 'N/A'}</span></div>
            <div class="details-row"><span class="label">Reviews</span><span class="value">${lead.review_count}</span></div>
            <div class="details-row"><span class="label">Locality</span><span class="value">${lead.locality || 'N/A'}</span></div>
            <div class="details-row"><span class="label">City</span><span class="value">${lead.city || 'N/A'}</span></div>
            <div class="details-row"><span class="label">Pitch Type</span><span class="value">${lead.pitch_type === 'A' ? 'Has Website' : 'No Website'}</span></div>
            <div class="details-row"><span class="label">Language</span><span class="value">${lead.language || 'hinglish'}</span></div>
        </div>

        <div class="details-section">
            <h4>Outreach</h4>
            <div class="details-row"><span class="label">Status</span><span class="value">${lead.outreach_status}</span></div>
            <div class="details-row"><span class="label">Last Contact</span><span class="value">${lead.last_contacted || 'Never'}</span></div>
            <div class="details-row"><span class="label">Reply At</span><span class="value">${lead.reply_received || 'No reply'}</span></div>
            <div class="details-row"><span class="label">Messages</span><span class="value">${lead.message_count}</span></div>
        </div>
    `;
}

// ============================================================
// REAL-TIME HANDLERS
// ============================================================
function handleRealtimeInbound(data) {
    showToast(`New reply from ${data.from_name || data.phone}`, 'info');

    // Refresh stats
    loadStats();

    // If this lead is currently open, add message
    const lead = state.leads.find(l => l.phone === data.phone);
    if (lead) {
        lead.unread_count = (lead.unread_count || 0) + 1;
        lead.outreach_status = 'replied';
        lead.last_message = data.message;

        if (state.currentLeadId === lead.id) {
            const newMsg = {
                id: Date.now(),
                direction: 'inbound',
                sender: 'lead',
                message: data.message,
                is_first_outreach: false,
                time_display: new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
            };
            state.messages.push(newMsg);
            renderMessages();

            // Mark read since we're viewing
            apiPost('mark_read.php', { lead_id: lead.id });
            lead.unread_count = 0;
        }

        renderLeadList();
    } else {
        // Reload leads to get the new one
        loadLeads();
    }
}

function handleRealtimeOutbound(data) {
    // If currently viewing this lead, refresh messages
    const lead = state.leads.find(l => l.phone === data.phone);
    if (lead && state.currentLeadId === lead.id) {
        loadMessages(lead.id);
    }
}

// ============================================================
// REFRESH SYNC
// ============================================================
async function refreshSync() {
    const res = await apiGet('refresh_sync.php');
    if (!res.success) return;

    state.engineOnline = res.engine.online;
    state.waReady = res.engine.wa_ready;
    updateEngineStatus();

    // Update unread badge
    if (res.unread_count > 0) {
        const badge = document.getElementById('navRepliedBadge');
        badge.textContent = res.unread_count;
        badge.style.display = '';
    }
}

// ============================================================
// TEXTAREA AUTOSIZE
// ============================================================
function initTextareaAutosize() {
    const textarea = document.getElementById('msgInput');
    textarea.addEventListener('input', () => {
        textarea.style.height = '42px';
        textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
    });
}

// ============================================================
// TOAST NOTIFICATIONS
// ============================================================
function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    const icon = type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ';
    toast.innerHTML = `<span>${icon}</span> ${escHtml(message)}`;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ============================================================
// UTILITIES
// ============================================================
function escHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
