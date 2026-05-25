<?php
/**
 * WaLead CRM - Premium Dashboard
 * Silicon Valley White + Green Theme
 * 3-Column Layout: Sidebar + Lead List + Chat
 */
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WaLead CRM - WhatsApp Cold Outreach Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { 50: '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0', 300: '#86efac', 400: '#4ade80', 500: '#22c55e', 600: '#16a34a', 700: '#15803d', 800: '#166534', 900: '#14532d' }
                    }
                }
            }
        }
    </script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .scrollbar-thin::-webkit-scrollbar { width: 6px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: #f1f5f9; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .lead-item { transition: all 0.15s ease; }
        .lead-item:hover { background: #f0fdf4; }
        .lead-item.active { background: #dcfce7; border-left: 3px solid #16a34a; }
        .msg-bubble { max-width: 75%; word-wrap: break-word; }
        .fade-in { animation: fadeIn 0.2s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .modal-overlay { backdrop-filter: blur(4px); }
        .stat-card { transition: transform 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gray-50 h-screen overflow-hidden">


    <!-- MAIN 3-COLUMN LAYOUT -->
    <div class="flex h-screen">

        <!-- COLUMN 1: SIDEBAR -->
        <div class="w-64 bg-white border-r border-gray-200 flex flex-col shadow-sm">
            <!-- Logo -->
            <div class="p-4 border-b border-gray-100">
                <h1 class="text-xl font-bold text-gray-800">Wa<span class="text-brand-600">Lead</span></h1>
                <p class="text-xs text-gray-500 mt-0.5">WhatsApp CRM v2.0</p>
            </div>

            <!-- Stats Cards -->
            <div class="p-3 space-y-2 border-b border-gray-100">
                <div class="grid grid-cols-2 gap-2">
                    <div class="stat-card bg-brand-50 rounded-lg p-2 text-center">
                        <div id="stat-sent" class="text-lg font-bold text-brand-700">0</div>
                        <div class="text-[10px] text-brand-600 uppercase">Sent Today</div>
                    </div>
                    <div class="stat-card bg-blue-50 rounded-lg p-2 text-center">
                        <div id="stat-replies" class="text-lg font-bold text-blue-700">0</div>
                        <div class="text-[10px] text-blue-600 uppercase">Replies</div>
                    </div>
                    <div class="stat-card bg-purple-50 rounded-lg p-2 text-center">
                        <div id="stat-rate" class="text-lg font-bold text-purple-700">0%</div>
                        <div class="text-[10px] text-purple-600 uppercase">Reply Rate</div>
                    </div>
                    <div class="stat-card bg-orange-50 rounded-lg p-2 text-center">
                        <div id="stat-remaining" class="text-lg font-bold text-orange-700">0</div>
                        <div class="text-[10px] text-orange-600 uppercase">Remaining</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="p-3 space-y-1 border-b border-gray-100 flex-1 overflow-y-auto">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-2">Filters</p>
                <button onclick="setFilter('all')" data-filter="all" class="filter-btn w-full text-left px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-gray-100 flex justify-between items-center">
                    <span>All Leads</span><span id="count-all" class="text-xs bg-gray-200 px-1.5 py-0.5 rounded">0</span>
                </button>
                <button onclick="setFilter('replied')" data-filter="replied" class="filter-btn w-full text-left px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-gray-100 flex justify-between items-center">
                    <span>&#9989; Replied</span><span id="count-replied" class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded">0</span>
                </button>
                <button onclick="setFilter('sent')" data-filter="sent" class="filter-btn w-full text-left px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-gray-100 flex justify-between items-center">
                    <span>&#10148; Sent</span><span id="count-sent-filter" class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">0</span>
                </button>
                <button onclick="setFilter('pending')" data-filter="pending" class="filter-btn w-full text-left px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-gray-100 flex justify-between items-center">
                    <span>&#9203; Pending</span><span id="count-pending" class="text-xs bg-yellow-100 text-yellow-700 px-1.5 py-0.5 rounded">0</span>
                </button>
                <button onclick="setFilter('has_website')" data-filter="has_website" class="filter-btn w-full text-left px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-gray-100 flex justify-between items-center">
                    <span>&#127760; Has Website</span><span class="text-xs text-gray-400">&#8250;</span>
                </button>
                <button onclick="setFilter('no_website')" data-filter="no_website" class="filter-btn w-full text-left px-3 py-2 rounded-lg text-sm text-gray-700 hover:bg-gray-100 flex justify-between items-center">
                    <span>&#10060; No Website</span><span class="text-xs text-gray-400">&#8250;</span>
                </button>
            </div>

            <!-- Action Buttons -->
            <div class="p-3 space-y-2 border-t border-gray-100">
                <button onclick="openModal('import')" class="w-full bg-brand-600 text-white py-2 px-3 rounded-lg text-sm font-medium hover:bg-brand-700 transition">
                    &#128196; Import CSV
                </button>
                <button onclick="openModal('campaign')" class="w-full bg-gray-800 text-white py-2 px-3 rounded-lg text-sm font-medium hover:bg-gray-900 transition">
                    &#128640; Run Campaign
                </button>
                <button onclick="openModal('settings')" class="w-full bg-gray-100 text-gray-700 py-2 px-3 rounded-lg text-sm font-medium hover:bg-gray-200 transition">
                    &#9881; Settings
                </button>
            </div>

            <!-- Connection Status -->
            <div class="p-3 border-t border-gray-100">
                <div class="flex items-center gap-2">
                    <div id="conn-dot" class="w-2 h-2 rounded-full bg-yellow-400"></div>
                    <span id="conn-text" class="text-xs text-gray-500">Connecting...</span>
                </div>
            </div>
        </div>


        <!-- COLUMN 2: LEAD LIST -->
        <div class="w-80 bg-white border-r border-gray-200 flex flex-col">
            <!-- Search -->
            <div class="p-3 border-b border-gray-100">
                <div class="relative">
                    <input type="text" id="search-input" placeholder="Search leads..." 
                        class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                        oninput="debounceSearch()">
                    <svg class="absolute left-2.5 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
            </div>

            <!-- Lead List -->
            <div id="lead-list" class="flex-1 overflow-y-auto scrollbar-thin">
                <div class="p-4 text-center text-gray-400 text-sm">Loading leads...</div>
            </div>

            <!-- Pagination -->
            <div class="p-2 border-t border-gray-100 flex items-center justify-between">
                <button onclick="prevPage()" id="btn-prev" class="px-2 py-1 text-xs text-gray-500 hover:text-gray-700 disabled:opacity-30" disabled>&#9664; Prev</button>
                <span id="page-info" class="text-xs text-gray-500">Page 1</span>
                <button onclick="nextPage()" id="btn-next" class="px-2 py-1 text-xs text-gray-500 hover:text-gray-700 disabled:opacity-30">Next &#9654;</button>
            </div>
        </div>

        <!-- COLUMN 3: CHAT PANEL -->
        <div class="flex-1 flex flex-col bg-gray-50">
            <!-- Chat Header -->
            <div id="chat-header" class="bg-white border-b border-gray-200 p-4 flex items-center justify-between shadow-sm">
                <div id="chat-header-info" class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-brand-100 rounded-full flex items-center justify-center">
                        <span class="text-brand-700 font-bold text-sm" id="chat-avatar">?</span>
                    </div>
                    <div>
                        <h3 id="chat-name" class="font-semibold text-gray-800">Select a lead</h3>
                        <p id="chat-phone" class="text-xs text-gray-500">Click on a lead to start chatting</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="getLeadDetails()" id="btn-details" class="hidden px-3 py-1.5 text-xs bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition">
                        Get Details
                    </button>
                    <button onclick="refreshChat()" id="btn-refresh" class="hidden px-3 py-1.5 text-xs bg-brand-100 text-brand-700 rounded-lg hover:bg-brand-200 transition">
                        &#8635; Refresh
                    </button>
                </div>
            </div>

            <!-- Chat Messages -->
            <div id="chat-messages" class="flex-1 overflow-y-auto p-4 scrollbar-thin">
                <div class="flex items-center justify-center h-full text-gray-400">
                    <div class="text-center">
                        <div class="text-4xl mb-2">&#128172;</div>
                        <p>Select a lead to view conversation</p>
                    </div>
                </div>
            </div>

            <!-- Message Input -->
            <div id="chat-input-area" class="bg-white border-t border-gray-200 p-3 hidden">
                <div class="flex gap-2">
                    <input type="text" id="msg-input" placeholder="Type a message..." 
                        class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                        onkeypress="if(event.key==='Enter')sendMsg()">
                    <button onclick="sendMsg()" class="px-5 py-2.5 bg-brand-600 text-white rounded-xl text-sm font-medium hover:bg-brand-700 transition shadow-sm">
                        Send
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ============ MODALS ============ -->

    <!-- IMPORT CSV MODAL -->
    <div id="modal-import" class="fixed inset-0 bg-black/40 modal-overlay hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 mx-4">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-gray-800">&#128196; Import CSV</h2>
                <button onclick="closeModal('import')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-3 text-xs text-gray-600">
                    <p class="font-medium mb-1">Expected CSV Format:</p>
                    <code class="text-brand-700">Business Name, Address, Phone, Website, Rating, Reviews, Status</code>
                </div>
                <form id="import-form" enctype="multipart/form-data">
                    <div class="border-2 border-dashed border-gray-200 rounded-xl p-6 text-center hover:border-brand-400 transition cursor-pointer" onclick="document.getElementById('csv-file').click()">
                        <input type="file" id="csv-file" name="csv_file" accept=".csv" class="hidden" onchange="updateFileName(this)">
                        <div class="text-3xl mb-2">&#128206;</div>
                        <p id="file-name" class="text-sm text-gray-500">Click to select CSV file</p>
                    </div>
                    <label class="flex items-center gap-2 mt-3 text-sm text-gray-600">
                        <input type="checkbox" id="has-header" name="has_header" value="1" checked class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        First row is header
                    </label>
                </form>
                <div id="import-result" class="hidden"></div>
                <button onclick="importCSV()" id="btn-import" class="w-full bg-brand-600 text-white py-2.5 rounded-lg font-medium hover:bg-brand-700 transition">
                    Import Leads
                </button>
            </div>
        </div>
    </div>

    <!-- CAMPAIGN MODAL -->
    <div id="modal-campaign" class="fixed inset-0 bg-black/40 modal-overlay hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 mx-4">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-gray-800">&#128640; Run Campaign</h2>
                <button onclick="closeModal('campaign')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="text-sm font-medium text-gray-700">Campaign Name</label>
                    <input type="text" id="camp-name" placeholder="e.g., Patna Toy Shops Outreach" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700">Message Template</label>
                    <textarea id="camp-template" rows="4" placeholder="Hi {business_name}! I came across your shop at {address} and..."
                        class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none resize-none"></textarea>
                    <p class="text-[10px] text-gray-400 mt-1">Variables: {business_name}, {address}, {website}, {rating}, {reviews}</p>
                </div>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" id="camp-ai" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        Use Groq AI personalization
                    </label>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Filter</label>
                        <select id="camp-filter" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none">
                            <option value="pending">Pending Only</option>
                            <option value="has_website">Has Website</option>
                            <option value="no_website">No Website</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700">Max Messages</label>
                        <input type="number" id="camp-limit" value="10" min="1" max="50" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    </div>
                </div>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-xs text-yellow-800">
                    &#9888; Anti-ban: Messages will be sent with 120-300 second delays between each.
                </div>
                <div class="flex gap-2">
                    <button onclick="previewCampaign()" class="flex-1 bg-gray-100 text-gray-700 py-2.5 rounded-lg font-medium hover:bg-gray-200 transition">Preview</button>
                    <button onclick="startCampaign()" class="flex-1 bg-brand-600 text-white py-2.5 rounded-lg font-medium hover:bg-brand-700 transition">Start Campaign</button>
                </div>
                <div id="camp-result" class="hidden"></div>
            </div>
        </div>
    </div>


    <!-- SETTINGS MODAL -->
    <div id="modal-settings" class="fixed inset-0 bg-black/40 modal-overlay hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 mx-4">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-gray-800">&#9881; Settings</h2>
                <button onclick="closeModal('settings')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="text-sm font-medium text-gray-700">Node.js Server URL</label>
                    <input type="text" id="set-node-url" value="<?php echo NODE_SERVER_URL; ?>" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none" readonly>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700">Webhook URL (for Node to call PHP)</label>
                    <input type="text" id="set-webhook-url" placeholder="https://yourdomain.com/walead/webhook.php" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700">Groq API Key</label>
                    <input type="password" id="set-groq-key" placeholder="gsk_..." class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Min Delay (sec)</label>
                        <input type="number" id="set-min-delay" value="<?php echo MIN_DELAY_SECONDS; ?>" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700">Max Delay (sec)</label>
                        <input type="number" id="set-max-delay" value="<?php echo MAX_DELAY_SECONDS; ?>" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    </div>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700">Max Messages/Day</label>
                    <input type="number" id="set-max-msgs" value="<?php echo MAX_MESSAGES_PER_DAY; ?>" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div class="bg-gray-50 rounded-lg p-3 text-xs text-gray-600">
                    <p><strong>Node Server:</strong> <span id="set-node-status" class="text-yellow-600">Checking...</span></p>
                    <p class="mt-1"><strong>Signature Verification:</strong> <span class="text-red-600">Disabled</span> (HF Spaces)</p>
                </div>
                <button onclick="saveSettings()" class="w-full bg-brand-600 text-white py-2.5 rounded-lg font-medium hover:bg-brand-700 transition">Save Settings</button>
            </div>
        </div>
    </div>

    <!-- LEAD DETAILS MODAL -->
    <div id="modal-details" class="fixed inset-0 bg-black/40 modal-overlay hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 mx-4">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-gray-800">&#128203; Lead Details</h2>
                <button onclick="closeModal('details')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div id="details-content" class="space-y-3">
                <p class="text-gray-500 text-sm">Loading...</p>
            </div>
        </div>
    </div>


    <!-- ============ JAVASCRIPT ============ -->
    <script>
    // ============ STATE ============
    const API_URL = 'api.php';
    const NODE_URL = '<?php echo NODE_SERVER_URL; ?>';
    let currentFilter = 'all';
    let currentPage = 1;
    let totalPages = 1;
    let selectedLead = null;
    let chatPollInterval = null;
    let lastMessageTime = null;
    let searchTimeout = null;

    // Socket.io connection to Node server
    let socket = null;
    try {
        socket = io(NODE_URL, { transports: ['websocket', 'polling'] });
        socket.on('connect', () => { updateConnectionStatus('connected'); });
        socket.on('disconnect', () => { updateConnectionStatus('disconnected'); });
        socket.on('message_received', (data) => { handleInboundRealtime(data); });
        socket.on('message_sent', (data) => { handleSentRealtime(data); });
        socket.on('bulk_progress', (data) => { handleBulkProgress(data); });
        socket.on('status', (data) => { updateConnectionStatus(data.status); });
    } catch(e) {
        console.error('Socket.io connection failed:', e);
    }

    // ============ INITIALIZATION ============
    document.addEventListener('DOMContentLoaded', () => {
        loadLeads();
        loadStats();
        checkNodeStatus();
        // Poll stats every 10 seconds
        setInterval(loadStats, 10000);
        // Check node status every 30 seconds
        setInterval(checkNodeStatus, 30000);
    });

    // ============ LEADS ============
    async function loadLeads() {
        const search = document.getElementById('search-input').value;
        const url = `${API_URL}?action=get_leads&filter=${currentFilter}&page=${currentPage}&search=${encodeURIComponent(search)}`;
        
        try {
            const res = await fetch(url);
            const data = await res.json();
            
            if (data.success) {
                renderLeads(data.leads);
                totalPages = data.pages;
                document.getElementById('page-info').textContent = `Page ${currentPage} of ${totalPages || 1}`;
                document.getElementById('btn-prev').disabled = currentPage <= 1;
                document.getElementById('btn-next').disabled = currentPage >= totalPages;
                document.getElementById('count-all').textContent = data.total;
            }
        } catch(e) {
            console.error('Load leads error:', e);
        }
    }

    function renderLeads(leads) {
        const container = document.getElementById('lead-list');
        if (!leads || leads.length === 0) {
            container.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">No leads found</div>';
            return;
        }

        container.innerHTML = leads.map(lead => {
            const isActive = selectedLead && selectedLead.id == lead.id;
            const statusBadge = getStatusBadge(lead.status);
            const initial = (lead.business_name || '?')[0].toUpperCase();
            const lastMsg = lead.last_message ? truncate(lead.last_message, 40) : 'No messages yet';
            const time = lead.last_message_time ? formatTime(lead.last_message_time) : '';
            
            return `<div class="lead-item p-3 border-b border-gray-50 cursor-pointer ${isActive ? 'active' : ''}" onclick="selectLead(${lead.id})">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-brand-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <span class="text-brand-700 font-bold text-xs">${initial}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-medium text-gray-800 truncate">${escapeHtml(lead.business_name)}</h4>
                            <span class="text-[10px] text-gray-400 flex-shrink-0">${time}</span>
                        </div>
                        <div class="flex items-center justify-between mt-0.5">
                            <p class="text-xs text-gray-500 truncate">${escapeHtml(lastMsg)}</p>
                            ${statusBadge}
                        </div>
                    </div>
                </div>
            </div>`;
        }).join('');
    }


    // ============ CHAT ============
    async function selectLead(id) {
        // Fetch lead details
        try {
            const res = await fetch(`${API_URL}?action=get_lead&id=${id}`);
            const data = await res.json();
            
            if (data.success) {
                selectedLead = data.lead;
                renderChatHeader(data.lead);
                renderMessages(data.messages);
                document.getElementById('chat-input-area').classList.remove('hidden');
                document.getElementById('btn-details').classList.remove('hidden');
                document.getElementById('btn-refresh').classList.remove('hidden');
                
                // Start polling for new messages
                startChatPolling();
                
                // Update active state in list
                document.querySelectorAll('.lead-item').forEach(el => el.classList.remove('active'));
                event.currentTarget?.classList.add('active');
            }
        } catch(e) {
            console.error('Select lead error:', e);
        }
    }

    function renderChatHeader(lead) {
        const initial = (lead.business_name || '?')[0].toUpperCase();
        document.getElementById('chat-avatar').textContent = initial;
        document.getElementById('chat-name').textContent = lead.business_name;
        document.getElementById('chat-phone').textContent = `${lead.phone} | ${lead.status}`;
    }

    function renderMessages(messages) {
        const container = document.getElementById('chat-messages');
        
        if (!messages || messages.length === 0) {
            container.innerHTML = '<div class="flex items-center justify-center h-full text-gray-400"><div class="text-center"><div class="text-3xl mb-2">&#128172;</div><p class="text-sm">No messages yet. Send the first message!</p></div></div>';
            return;
        }

        container.innerHTML = messages.map(msg => {
            const isOutbound = msg.direction === 'outbound';
            const time = formatTime(msg.created_at);
            return `<div class="flex ${isOutbound ? 'justify-end' : 'justify-start'} mb-3 fade-in">
                <div class="msg-bubble px-4 py-2.5 rounded-2xl ${isOutbound ? 'bg-brand-600 text-white rounded-br-md' : 'bg-white text-gray-800 border border-gray-200 rounded-bl-md shadow-sm'}">
                    <p class="text-sm whitespace-pre-wrap">${escapeHtml(msg.body)}</p>
                    <p class="text-[10px] ${isOutbound ? 'text-green-200' : 'text-gray-400'} mt-1 text-right">${time}</p>
                </div>
            </div>`;
        }).join('');

        // Scroll to bottom
        container.scrollTop = container.scrollHeight;
        
        // Track last message time for polling
        if (messages.length > 0) {
            lastMessageTime = messages[messages.length - 1].created_at;
        }
    }

    async function sendMsg() {
        const input = document.getElementById('msg-input');
        const message = input.value.trim();
        if (!message || !selectedLead) return;

        input.value = '';
        input.disabled = true;

        // Optimistically add to chat
        appendMessage({ body: message, direction: 'outbound', created_at: new Date().toISOString() });

        try {
            const res = await fetch(`${API_URL}?action=send_message`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lead_id: selectedLead.id, message })
            });
            const data = await res.json();
            
            if (!data.success) {
                showToast('Failed to send: ' + (data.error || 'Unknown error'), 'error');
            }
        } catch(e) {
            showToast('Network error sending message', 'error');
        }

        input.disabled = false;
        input.focus();
    }

    function appendMessage(msg) {
        const container = document.getElementById('chat-messages');
        // Remove empty state if present
        if (container.querySelector('.text-gray-400')) {
            container.innerHTML = '';
        }

        const isOutbound = msg.direction === 'outbound';
        const time = formatTime(msg.created_at);
        const html = `<div class="flex ${isOutbound ? 'justify-end' : 'justify-start'} mb-3 fade-in">
            <div class="msg-bubble px-4 py-2.5 rounded-2xl ${isOutbound ? 'bg-brand-600 text-white rounded-br-md' : 'bg-white text-gray-800 border border-gray-200 rounded-bl-md shadow-sm'}">
                <p class="text-sm whitespace-pre-wrap">${escapeHtml(msg.body)}</p>
                <p class="text-[10px] ${isOutbound ? 'text-green-200' : 'text-gray-400'} mt-1 text-right">${time}</p>
            </div>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
        container.scrollTop = container.scrollHeight;
    }

    // 5-second polling for new messages
    function startChatPolling() {
        if (chatPollInterval) clearInterval(chatPollInterval);
        chatPollInterval = setInterval(pollNewMessages, 5000);
    }

    async function pollNewMessages() {
        if (!selectedLead) return;
        try {
            const since = lastMessageTime ? `&since=${encodeURIComponent(lastMessageTime)}` : '';
            const res = await fetch(`${API_URL}?action=get_messages&lead_id=${selectedLead.id}${since}`);
            const data = await res.json();
            
            if (data.success && data.messages && data.messages.length > 0) {
                data.messages.forEach(msg => appendMessage(msg));
                lastMessageTime = data.messages[data.messages.length - 1].created_at;
                // Refresh leads list for status update
                loadLeads();
                loadStats();
            }
        } catch(e) { /* silently fail */ }
    }

    function refreshChat() {
        if (selectedLead) selectLead(selectedLead.id);
    }


    // ============ REAL-TIME HANDLERS ============
    function handleInboundRealtime(data) {
        // If currently viewing this lead's chat, append message
        if (selectedLead && data.resolved_phone) {
            const leadPhone = selectedLead.phone.replace(/[^0-9]/g, '');
            const incomingPhone = data.resolved_phone.replace(/[^0-9]/g, '');
            if (leadPhone.endsWith(incomingPhone.slice(-10)) || incomingPhone.endsWith(leadPhone.slice(-10))) {
                appendMessage({ body: data.body, direction: 'inbound', created_at: new Date().toISOString() });
            }
        }
        // Always refresh stats and lead list
        loadStats();
        loadLeads();
        showToast('New reply received!', 'success');
    }

    function handleSentRealtime(data) {
        loadStats();
    }

    function handleBulkProgress(data) {
        const resultEl = document.getElementById('camp-result');
        if (resultEl) {
            resultEl.classList.remove('hidden');
            resultEl.innerHTML = `<div class="bg-blue-50 border border-blue-200 rounded-lg p-2 text-xs text-blue-800">
                Sent to ${data.phone} | Status: ${data.status} | Remaining: ${data.remaining}
            </div>`;
        }
    }

    // ============ STATS ============
    async function loadStats() {
        try {
            const res = await fetch(`${API_URL}?action=get_stats`);
            const data = await res.json();
            if (data.success) {
                document.getElementById('stat-sent').textContent = data.stats.sent_today;
                document.getElementById('stat-replies').textContent = data.stats.total_replies;
                document.getElementById('stat-rate').textContent = data.stats.reply_rate + '%';
                document.getElementById('stat-remaining').textContent = data.stats.remaining;
                document.getElementById('count-replied').textContent = data.stats.replied;
                document.getElementById('count-pending').textContent = data.stats.pending;
            }
        } catch(e) { /* silently fail */ }
    }

    // ============ FILTERS & SEARCH ============
    function setFilter(filter) {
        currentFilter = filter;
        currentPage = 1;
        loadLeads();
        // Update active filter UI
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.classList.remove('bg-brand-50', 'text-brand-700', 'font-medium');
            if (btn.dataset.filter === filter) {
                btn.classList.add('bg-brand-50', 'text-brand-700', 'font-medium');
            }
        });
    }

    function debounceSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            currentPage = 1;
            loadLeads();
        }, 300);
    }

    function prevPage() { if (currentPage > 1) { currentPage--; loadLeads(); } }
    function nextPage() { if (currentPage < totalPages) { currentPage++; loadLeads(); } }

    // ============ LEAD DETAILS ============
    function getLeadDetails() {
        if (!selectedLead) return;
        const lead = selectedLead;
        const content = `
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="text-[10px] text-gray-400 uppercase">Business Name</label>
                    <p class="text-sm font-medium text-gray-800">${escapeHtml(lead.business_name)}</p>
                </div>
                <div class="col-span-2">
                    <label class="text-[10px] text-gray-400 uppercase">Address</label>
                    <p class="text-sm text-gray-700">${escapeHtml(lead.address || 'N/A')}</p>
                </div>
                <div>
                    <label class="text-[10px] text-gray-400 uppercase">Phone</label>
                    <p class="text-sm text-gray-700">${escapeHtml(lead.phone)}</p>
                </div>
                <div>
                    <label class="text-[10px] text-gray-400 uppercase">Status</label>
                    <p class="text-sm">${getStatusBadge(lead.status)}</p>
                </div>
                <div class="col-span-2">
                    <label class="text-[10px] text-gray-400 uppercase">Website</label>
                    <p class="text-sm text-gray-700">${lead.website ? `<a href="${escapeHtml(lead.website)}" target="_blank" class="text-brand-600 underline">${escapeHtml(lead.website)}</a>` : 'No website'}</p>
                </div>
                <div>
                    <label class="text-[10px] text-gray-400 uppercase">Rating</label>
                    <p class="text-sm text-gray-700">${lead.rating || '0'} / 5</p>
                </div>
                <div>
                    <label class="text-[10px] text-gray-400 uppercase">Reviews</label>
                    <p class="text-sm text-gray-700">${lead.reviews || '0'}</p>
                </div>
                <div>
                    <label class="text-[10px] text-gray-400 uppercase">Last Contacted</label>
                    <p class="text-sm text-gray-700">${lead.last_contacted ? formatTime(lead.last_contacted) : 'Never'}</p>
                </div>
                <div>
                    <label class="text-[10px] text-gray-400 uppercase">Last Reply</label>
                    <p class="text-sm text-gray-700">${lead.last_reply ? formatTime(lead.last_reply) : 'None'}</p>
                </div>
                <div class="col-span-2">
                    <label class="text-[10px] text-gray-400 uppercase">Created</label>
                    <p class="text-sm text-gray-700">${formatTime(lead.created_at)}</p>
                </div>
            </div>`;
        document.getElementById('details-content').innerHTML = content;
        openModal('details');
    }


    // ============ CSV IMPORT ============
    async function importCSV() {
        const fileInput = document.getElementById('csv-file');
        if (!fileInput.files.length) {
            showToast('Please select a CSV file', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('csv_file', fileInput.files[0]);
        formData.append('has_header', document.getElementById('has-header').checked ? '1' : '0');

        document.getElementById('btn-import').disabled = true;
        document.getElementById('btn-import').textContent = 'Importing...';

        try {
            const res = await fetch('import.php', { method: 'POST', body: formData });
            const data = await res.json();

            const resultEl = document.getElementById('import-result');
            resultEl.classList.remove('hidden');

            if (data.success) {
                resultEl.innerHTML = `<div class="bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-800">
                    &#9989; ${data.message}<br>
                    <span class="text-xs">Imported: ${data.imported} | Skipped: ${data.skipped}</span>
                </div>`;
                loadLeads();
                loadStats();
            } else {
                resultEl.innerHTML = `<div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-800">
                    &#10060; ${data.error}
                </div>`;
            }
        } catch(e) {
            showToast('Import failed: ' + e.message, 'error');
        }

        document.getElementById('btn-import').disabled = false;
        document.getElementById('btn-import').textContent = 'Import Leads';
    }

    function updateFileName(input) {
        const name = input.files[0]?.name || 'Click to select CSV file';
        document.getElementById('file-name').textContent = name;
    }

    // ============ CAMPAIGN ============
    async function startCampaign() {
        const name = document.getElementById('camp-name').value || 'Campaign ' + new Date().toLocaleDateString();
        const template = document.getElementById('camp-template').value;
        const useAI = document.getElementById('camp-ai').checked;
        const filter = document.getElementById('camp-filter').value;
        const limit = document.getElementById('camp-limit').value;

        if (!template && !useAI) {
            showToast('Enter a message template or enable AI', 'error');
            return;
        }

        try {
            const res = await fetch(`${API_URL}?action=start_campaign`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, message_template: template, use_ai: useAI, filter, limit: parseInt(limit) })
            });
            const data = await res.json();
            
            const resultEl = document.getElementById('camp-result');
            resultEl.classList.remove('hidden');

            if (data.success) {
                resultEl.innerHTML = `<div class="bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-800">
                    &#9989; ${data.message}
                </div>`;
                loadStats();
                loadLeads();
            } else {
                resultEl.innerHTML = `<div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-800">
                    &#10060; ${data.error}
                </div>`;
            }
        } catch(e) {
            showToast('Campaign start failed', 'error');
        }
    }

    async function previewCampaign() {
        const template = document.getElementById('camp-template').value;
        const useAI = document.getElementById('camp-ai').checked;

        try {
            const res = await fetch('campaign.php?action=generate_preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ template, use_ai: useAI })
            });
            const data = await res.json();
            
            const resultEl = document.getElementById('camp-result');
            resultEl.classList.remove('hidden');

            if (data.success) {
                resultEl.innerHTML = `<div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-800">
                    <p class="font-medium mb-1">Preview for: ${escapeHtml(data.lead)}</p>
                    <p class="italic">"${escapeHtml(data.preview)}"</p>
                </div>`;
            } else {
                resultEl.innerHTML = `<div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-800">${data.error}</div>`;
            }
        } catch(e) {
            showToast('Preview failed', 'error');
        }
    }


    // ============ SETTINGS ============
    async function saveSettings() {
        const webhookUrl = document.getElementById('set-webhook-url').value;
        try {
            await fetch(`${API_URL}?action=save_config`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ webhook_url: webhookUrl })
            });
            showToast('Settings saved!', 'success');
            closeModal('settings');
        } catch(e) {
            showToast('Failed to save settings', 'error');
        }
    }

    async function checkNodeStatus() {
        try {
            const res = await fetch(`${API_URL}?action=get_node_status`);
            const data = await res.json();
            if (data.success && data.node_status) {
                updateConnectionStatus(data.node_status.status);
                const statusEl = document.getElementById('set-node-status');
                if (statusEl) {
                    statusEl.textContent = data.node_status.status;
                    statusEl.className = data.node_status.status === 'connected' ? 'text-green-600 font-medium' : 'text-yellow-600';
                }
            } else {
                updateConnectionStatus('offline');
            }
        } catch(e) {
            updateConnectionStatus('offline');
        }
    }

    function updateConnectionStatus(status) {
        const dot = document.getElementById('conn-dot');
        const text = document.getElementById('conn-text');
        
        const states = {
            'connected': { color: 'bg-green-500', label: 'Connected' },
            'authenticated': { color: 'bg-green-400', label: 'Authenticated' },
            'waiting_for_scan': { color: 'bg-yellow-400', label: 'Scan QR Code' },
            'disconnected': { color: 'bg-red-400', label: 'Disconnected' },
            'offline': { color: 'bg-red-500', label: 'Node Offline' },
            'auth_failed': { color: 'bg-red-500', label: 'Auth Failed' }
        };

        const state = states[status] || { color: 'bg-gray-400', label: status || 'Unknown' };
        dot.className = `w-2 h-2 rounded-full ${state.color}`;
        text.textContent = state.label;
    }

    // ============ MODALS ============
    function openModal(name) {
        document.getElementById(`modal-${name}`).classList.remove('hidden');
        if (name === 'settings') checkNodeStatus();
    }

    function closeModal(name) {
        document.getElementById(`modal-${name}`).classList.add('hidden');
    }

    // Close modal on outside click
    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.add('hidden');
        }
    });

    // ============ UTILITY FUNCTIONS ============
    function getStatusBadge(status) {
        const badges = {
            'pending': '<span class="text-[10px] bg-yellow-100 text-yellow-700 px-1.5 py-0.5 rounded">Pending</span>',
            'sent': '<span class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">Sent</span>',
            'replied': '<span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded">Replied</span>',
            'failed': '<span class="text-[10px] bg-red-100 text-red-700 px-1.5 py-0.5 rounded">Failed</span>',
            'opted_out': '<span class="text-[10px] bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded">Opted Out</span>'
        };
        return badges[status] || badges['pending'];
    }

    function formatTime(dateStr) {
        if (!dateStr) return '';
        try {
            const d = new Date(dateStr);
            const now = new Date();
            const isToday = d.toDateString() === now.toDateString();
            if (isToday) {
                return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
            }
            return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
        } catch(e) {
            return dateStr;
        }
    }

    function truncate(str, len) {
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-green-600' : type === 'error' ? 'bg-red-600' : 'bg-gray-800';
        toast.className = `fixed bottom-4 right-4 ${bgColor} text-white px-4 py-2.5 rounded-lg shadow-lg text-sm z-[100] fade-in`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
    </script>
</body>
</html>
