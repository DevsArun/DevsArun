/**
 * WaLead WhatsApp CRM Engine
 * Node.js server for Hugging Face Spaces (Docker, port 7860)
 * Features: WhatsApp Web.js, Socket.io, LID-to-Phone mapping, QR code, Webhook
 */

const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode');
const axios = require('axios');
const cors = require('cors');
const bodyParser = require('body-parser');

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: '*' } });

const PORT = 7860;

// Middleware
app.use(cors());
app.use(bodyParser.json({ limit: '50mb' }));
app.use(bodyParser.urlencoded({ extended: true, limit: '50mb' }));

// ============ STATE MANAGEMENT ============
let qrCodeData = null;
let clientReady = false;
let clientInfo = null;
let lastActivity = null;
let webhookUrl = process.env.WEBHOOK_URL || '';
let debugLogs = [];
const MAX_LOGS = 500;

// CRITICAL: LID-to-Phone mapping (in-memory)
const lidToPhoneMap = new Map();
const phoneToLidMap = new Map();

function addDebugLog(type, message, data = null) {
    const log = {
        timestamp: new Date().toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' }),
        type,
        message,
        data: data ? JSON.stringify(data).substring(0, 500) : null
    };
    debugLogs.unshift(log);
    if (debugLogs.length > MAX_LOGS) debugLogs.pop();
    io.emit('debug_log', log);
}

function getIST() {
    return new Date().toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' });
}


// ============ WHATSAPP CLIENT SETUP ============
const client = new Client({
    authStrategy: new LocalAuth({ dataPath: '/app/.wwebjs_auth' }),
    puppeteer: {
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-accelerated-2d-canvas',
            '--no-first-run',
            '--no-zygote',
            '--single-process',
            '--disable-gpu'
        ],
        executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium'
    }
});

client.on('qr', async (qr) => {
    addDebugLog('QR', 'New QR code generated');
    qrCodeData = await qrcode.toDataURL(qr);
    clientReady = false;
    io.emit('qr', qrCodeData);
});

client.on('ready', () => {
    addDebugLog('STATUS', 'WhatsApp client is READY');
    clientReady = true;
    qrCodeData = null;
    clientInfo = client.info;
    lastActivity = getIST();
    io.emit('ready', { status: 'ready', info: client.info });
});

client.on('authenticated', () => {
    addDebugLog('AUTH', 'Client authenticated successfully');
});

client.on('auth_failure', (msg) => {
    addDebugLog('ERROR', 'Authentication failed', { message: msg });
    clientReady = false;
});

client.on('disconnected', (reason) => {
    addDebugLog('STATUS', 'Client disconnected', { reason });
    clientReady = false;
    qrCodeData = null;
});

// ============ INBOUND MESSAGE HANDLER ============
client.on('message', async (message) => {
    try {
        lastActivity = getIST();
        const chatId = message.from;
        let resolvedPhone = null;

        addDebugLog('INBOUND', `Message from: ${chatId}`, {
            body: message.body?.substring(0, 100),
            type: message.type
        });

        // CRITICAL: Resolve LID to phone number
        if (chatId.endsWith('@lid')) {
            if (lidToPhoneMap.has(chatId)) {
                resolvedPhone = lidToPhoneMap.get(chatId);
                addDebugLog('LID_RESOLVE', `Resolved via mapping: ${chatId} -> ${resolvedPhone}`);
            } else {
                try {
                    const contact = await message.getContact();
                    if (contact && contact.number) {
                        resolvedPhone = contact.number;
                        lidToPhoneMap.set(chatId, resolvedPhone);
                        phoneToLidMap.set(resolvedPhone, chatId);
                        addDebugLog('LID_RESOLVE', `Resolved via getContact: ${chatId} -> ${resolvedPhone}`);
                    }
                } catch (e) {
                    addDebugLog('LID_ERROR', `getContact failed for ${chatId}`, { error: e.message });
                }
            }

            if (!resolvedPhone) {
                try {
                    const chat = await message.getChat();
                    if (chat && chat.id && chat.id.user) {
                        resolvedPhone = chat.id.user;
                        lidToPhoneMap.set(chatId, resolvedPhone);
                        phoneToLidMap.set(resolvedPhone, chatId);
                        addDebugLog('LID_RESOLVE', `Resolved via chat.id: ${chatId} -> ${resolvedPhone}`);
                    }
                } catch (e) {
                    addDebugLog('LID_ERROR', `chat.id fallback failed for ${chatId}`, { error: e.message });
                }
            }
        } else if (chatId.endsWith('@c.us')) {
            resolvedPhone = chatId.replace('@c.us', '');
        }

        const payload = {
            from: chatId,
            phone: resolvedPhone,
            body: message.body,
            timestamp: getIST(),
            type: message.type,
            isGroup: message.from.endsWith('@g.us')
        };
        io.emit('message_received', payload);

        // Forward to PHP webhook (no signature verification)
        if (webhookUrl && resolvedPhone) {
            try {
                await axios.post(webhookUrl, {
                    event: 'message_received',
                    phone: resolvedPhone,
                    message: message.body,
                    timestamp: getIST(),
                    raw_from: chatId,
                    type: message.type
                }, { timeout: 10000, headers: { 'Content-Type': 'application/json' } });
                addDebugLog('WEBHOOK', `Forwarded to ${webhookUrl}`, { phone: resolvedPhone });
            } catch (e) {
                addDebugLog('WEBHOOK_ERROR', `Failed to forward`, { error: e.message });
            }
        }
    } catch (err) {
        addDebugLog('ERROR', 'Error processing inbound message', { error: err.message });
    }
});

// ============ MESSAGE ACK HANDLER ============
client.on('message_ack', (message, ack) => {
    const ackStatus = ['ERROR', 'PENDING', 'SERVER', 'DEVICE', 'READ', 'PLAYED'];
    io.emit('message_ack', {
        id: message.id._serialized,
        to: message.to,
        ack: ack,
        ackName: ackStatus[ack + 1] || 'UNKNOWN'
    });
});


// ============ API ROUTES ============

app.get('/', (req, res) => { res.send(getStatusPageHTML()); });

app.get('/status', (req, res) => {
    res.json({
        status: clientReady ? 'connected' : 'disconnected',
        info: clientInfo,
        lastActivity,
        uptime: process.uptime(),
        lidMappings: lidToPhoneMap.size,
        timestamp: getIST()
    });
});

app.get('/qr', (req, res) => { res.send(getQRPageHTML()); });

app.get('/qr-data', (req, res) => {
    res.json({ qr: qrCodeData, ready: clientReady });
});

// Send message - CRITICAL: captures LID mapping
app.post('/send-message', async (req, res) => {
    try {
        const { phone, message } = req.body;
        if (!phone || !message) {
            return res.status(400).json({ success: false, error: 'Phone and message required' });
        }
        if (!clientReady) {
            return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
        }

        let formattedPhone = phone.toString().replace(/[^0-9]/g, '');
        if (formattedPhone.startsWith('0')) formattedPhone = '91' + formattedPhone.substring(1);
        if (!formattedPhone.startsWith('91') && formattedPhone.length === 10) {
            formattedPhone = '91' + formattedPhone;
        }
        const chatId = formattedPhone + '@c.us';

        addDebugLog('SEND', `Sending to ${chatId}`, { messagePreview: message.substring(0, 50) });

        const sentMsg = await client.sendMessage(chatId, message);
        lastActivity = getIST();

        // CRITICAL: Capture LID mapping from sent message
        if (sentMsg && sentMsg.to) {
            const toId = sentMsg.to;
            if (toId.endsWith('@lid')) {
                lidToPhoneMap.set(toId, formattedPhone);
                phoneToLidMap.set(formattedPhone, toId);
                addDebugLog('LID_MAP', `Mapped: ${toId} -> ${formattedPhone}`);
            }
        }

        // Also try to get chat LID after sending
        try {
            const chat = await sentMsg.getChat();
            if (chat && chat.id && chat.id._serialized) {
                const chatLid = chat.id._serialized;
                if (chatLid.endsWith('@lid')) {
                    lidToPhoneMap.set(chatLid, formattedPhone);
                    phoneToLidMap.set(formattedPhone, chatLid);
                    addDebugLog('LID_MAP', `Chat LID mapped: ${chatLid} -> ${formattedPhone}`);
                }
            }
        } catch (e) { /* Non-critical */ }

        io.emit('message_sent', {
            phone: formattedPhone,
            message,
            timestamp: getIST(),
            id: sentMsg.id._serialized
        });

        res.json({
            success: true,
            messageId: sentMsg.id._serialized,
            to: chatId,
            timestamp: getIST()
        });
    } catch (err) {
        addDebugLog('SEND_ERROR', err.message, { stack: err.stack?.substring(0, 200) });
        res.status(500).json({ success: false, error: err.message });
    }
});

app.post('/set-webhook', (req, res) => {
    const { url } = req.body;
    webhookUrl = url || '';
    addDebugLog('CONFIG', `Webhook URL set to: ${webhookUrl}`);
    res.json({ success: true, webhookUrl });
});

app.get('/get-webhook', (req, res) => { res.json({ webhookUrl }); });

app.get('/logs', (req, res) => {
    const limit = parseInt(req.query.limit) || 100;
    res.json({ logs: debugLogs.slice(0, limit) });
});

app.post('/clear-logs', (req, res) => { debugLogs = []; res.json({ success: true }); });

app.get('/lid-map', (req, res) => {
    const map = {};
    lidToPhoneMap.forEach((phone, lid) => { map[lid] = phone; });
    res.json({ mappings: map, count: lidToPhoneMap.size });
});

app.post('/lid-map/add', (req, res) => {
    const { lid, phone } = req.body;
    if (lid && phone) {
        lidToPhoneMap.set(lid, phone);
        phoneToLidMap.set(phone, lid);
        addDebugLog('LID_MAP', `Manual mapping: ${lid} -> ${phone}`);
        res.json({ success: true });
    } else {
        res.status(400).json({ success: false, error: 'lid and phone required' });
    }
});

app.get('/health', (req, res) => {
    res.json({ status: 'ok', uptime: process.uptime(), timestamp: getIST() });
});

app.post('/restart', async (req, res) => {
    try {
        addDebugLog('ACTION', 'Restarting WhatsApp client...');
        await client.destroy();
        clientReady = false;
        qrCodeData = null;
        setTimeout(() => client.initialize(), 3000);
        res.json({ success: true, message: 'Client restarting...' });
    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});


// ============ SOCKET.IO EVENTS ============
io.on('connection', (socket) => {
    addDebugLog('SOCKET', `Client connected: ${socket.id}`);
    socket.emit('status', { ready: clientReady, info: clientInfo, qr: qrCodeData, lastActivity, lidMappings: lidToPhoneMap.size });
    socket.on('disconnect', () => { addDebugLog('SOCKET', `Client disconnected: ${socket.id}`); });
    socket.on('ping_status', () => {
        socket.emit('status', { ready: clientReady, info: clientInfo, lastActivity, lidMappings: lidToPhoneMap.size });
    });
});

// ============ STATUS PAGE HTML ============
function getStatusPageHTML() {
    return `<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>WaLead CRM Engine</title><script src="https://cdn.tailwindcss.com"></script><script src="/socket.io/socket.io.js"></script><style>body{background:linear-gradient(135deg,#f0fdf4 0%,#fff 50%,#f0fdf4 100%)}.glass{background:rgba(255,255,255,0.9);backdrop-filter:blur(10px)}.pulse{animation:pulse 2s infinite}@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.5}}</style></head><body class="min-h-screen p-8"><div class="max-w-4xl mx-auto"><div class="glass rounded-2xl shadow-xl p-8 border border-green-100"><div class="flex items-center gap-4 mb-8"><div class="w-12 h-12 bg-green-500 rounded-xl flex items-center justify-center"><svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg></div><div><h1 class="text-3xl font-bold text-gray-800">WaLead CRM Engine</h1><p class="text-gray-500">WhatsApp Business Integration Server</p></div></div><div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8"><div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm"><p class="text-sm text-gray-500 mb-1">Status</p><p id="status" class="text-lg font-semibold text-red-500">Disconnected</p></div><div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm"><p class="text-sm text-gray-500 mb-1">LID Mappings</p><p id="mappings" class="text-lg font-semibold text-gray-800">0</p></div><div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm"><p class="text-sm text-gray-500 mb-1">Last Activity</p><p id="activity" class="text-lg font-semibold text-gray-800">-</p></div></div><div class="bg-white rounded-xl p-4 border border-gray-100 shadow-sm mb-4"><h3 class="font-semibold text-gray-700 mb-2">Recent Logs</h3><div id="logs" class="font-mono text-xs max-h-64 overflow-y-auto space-y-1"></div></div><div class="flex gap-2"><a href="/qr" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition">QR Page</a><button onclick="restart()" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition">Restart</button></div></div></div><script>const socket=io();socket.on('status',(d)=>{document.getElementById('status').textContent=d.ready?'Connected':'Disconnected';document.getElementById('status').className='text-lg font-semibold '+(d.ready?'text-green-500':'text-red-500');document.getElementById('mappings').textContent=d.lidMappings||0;document.getElementById('activity').textContent=d.lastActivity||'-';});socket.on('debug_log',(log)=>{const el=document.getElementById('logs');const div=document.createElement('div');div.className='text-gray-600';div.textContent='['+log.timestamp+'] ['+log.type+'] '+log.message;el.prepend(div);if(el.children.length>50)el.lastChild.remove();});setInterval(()=>socket.emit('ping_status'),5000);async function restart(){await fetch('/restart',{method:'POST'});}</script></body></html>`;
}

// ============ QR PAGE HTML ============
function getQRPageHTML() {
    return `<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>WaLead - Scan QR</title><script src="https://cdn.tailwindcss.com"></script><script src="/socket.io/socket.io.js"></script><style>body{background:linear-gradient(135deg,#f0fdf4 0%,#fff 50%,#f0fdf4 100%)}.glass{background:rgba(255,255,255,0.95);backdrop-filter:blur(10px)}</style></head><body class="min-h-screen flex items-center justify-center p-8"><div class="glass rounded-2xl shadow-xl p-8 border border-green-100 text-center max-w-md w-full"><div class="w-16 h-16 bg-green-500 rounded-2xl flex items-center justify-center mx-auto mb-4"><svg class="w-9 h-9 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg></div><h1 class="text-2xl font-bold text-gray-800 mb-2">WaLead CRM Engine</h1><p class="text-gray-500 mb-6">Scan QR code with WhatsApp to connect</p><div id="qr-container" class="mb-6"><div id="qr-loading" class="py-12"><div class="animate-spin w-8 h-8 border-4 border-green-500 border-t-transparent rounded-full mx-auto mb-3"></div><p class="text-gray-400">Generating QR code...</p></div><img id="qr-image" class="mx-auto rounded-xl shadow-lg hidden max-w-[280px]"/><div id="connected" class="hidden py-12"><div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3"><svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></div><p class="text-green-600 font-semibold text-lg">Connected!</p><p id="conn-info" class="text-gray-500 mt-1"></p></div></div><a href="/" class="text-green-600 hover:underline text-sm">Back to Status</a></div><script>const socket=io();socket.on('qr',(qr)=>{document.getElementById('qr-loading').classList.add('hidden');document.getElementById('connected').classList.add('hidden');document.getElementById('qr-image').classList.remove('hidden');document.getElementById('qr-image').src=qr;});socket.on('ready',(data)=>{document.getElementById('qr-loading').classList.add('hidden');document.getElementById('qr-image').classList.add('hidden');document.getElementById('connected').classList.remove('hidden');if(data.info)document.getElementById('conn-info').textContent=data.info.pushname||'';});socket.on('status',(d)=>{if(d.ready){document.getElementById('qr-loading').classList.add('hidden');document.getElementById('qr-image').classList.add('hidden');document.getElementById('connected').classList.remove('hidden');}else if(d.qr){document.getElementById('qr-loading').classList.add('hidden');document.getElementById('connected').classList.add('hidden');document.getElementById('qr-image').classList.remove('hidden');document.getElementById('qr-image').src=d.qr;}});async function checkQR(){const r=await fetch('/qr-data');const d=await r.json();if(d.ready){document.getElementById('qr-loading').classList.add('hidden');document.getElementById('qr-image').classList.add('hidden');document.getElementById('connected').classList.remove('hidden');}else if(d.qr){document.getElementById('qr-loading').classList.add('hidden');document.getElementById('connected').classList.add('hidden');document.getElementById('qr-image').classList.remove('hidden');document.getElementById('qr-image').src=d.qr;}}checkQR();</script></body></html>`;
}

// ============ START SERVER ============
server.listen(PORT, '0.0.0.0', () => {
    console.log(`[WaLead] Server running on port ${PORT}`);
    console.log(`[WaLead] Status: http://localhost:${PORT}`);
    console.log(`[WaLead] QR Page: http://localhost:${PORT}/qr`);
    addDebugLog('SYSTEM', `Server started on port ${PORT}`);
});

client.initialize().catch(err => {
    addDebugLog('ERROR', 'Failed to initialize client', { error: err.message });
    console.error('[WaLead] Init error:', err.message);
});

process.on('SIGINT', async () => { await client.destroy(); process.exit(0); });
process.on('SIGTERM', async () => { await client.destroy(); process.exit(0); });
process.on('unhandledRejection', (reason) => {
    addDebugLog('ERROR', 'Unhandled rejection', { reason: String(reason).substring(0, 200) });
});
