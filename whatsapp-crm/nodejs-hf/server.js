/**
 * ============================================================
 * WhatsApp CRM Engine - Hugging Face Spaces Version
 * FINAL FIXED — LID phone resolution + proper webhook
 * ============================================================
 */

require('dotenv').config();
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const crypto = require('crypto');
const axios = require('axios');
const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');
const QRCode = require('qrcode');

const PORT = process.env.PORT || 7860;
const API_KEY = process.env.API_KEY || 'default_dev_key_change_this';
const WEBHOOK_URL = process.env.WEBHOOK_URL || '';
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET || 'default_secret';
const CORS_ORIGIN = process.env.SOCKET_CORS_ORIGIN || '*';
const SESSION_NAME = process.env.SESSION_NAME || 'wa-crm-session';
const SELF_PING_URL = process.env.SELF_PING_URL || '';
const SPACE_URL = process.env.SPACE_URL || `http://localhost:${PORT}`;

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: true, methods: ['GET', 'POST'], credentials: true } });

app.use(cors({ origin: true, credentials: true }));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

let waClient = null;
let waStatus = 'disconnected';
let qrCodeData = null;
let qrCodeImage = null;

function initWhatsApp() {
    waClient = new Client({
        authStrategy: new LocalAuth({ clientId: SESSION_NAME, dataPath: './wa_session' }),
        puppeteer: {
            headless: true,
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium',
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-accelerated-2d-canvas', '--no-first-run', '--no-zygote', '--disable-gpu', '--single-process', '--disable-extensions']
        }
    });

    waClient.on('qr', async (qr) => {
        qrCodeData = qr;
        waStatus = 'qr_pending';
        try { qrCodeImage = await QRCode.toDataURL(qr, { width: 256, margin: 2 }); } catch (e) { qrCodeImage = null; }
        qrcode.generate(qr, { small: true });
        console.log('[WA] QR Code generated');
        io.emit('wa:qr', { qr, image: qrCodeImage });
        io.emit('wa:status', { status: waStatus });
    });

    waClient.on('authenticated', () => { waStatus = 'authenticated'; qrCodeData = null; qrCodeImage = null; console.log('[WA] Authenticated'); io.emit('wa:status', { status: waStatus }); });
    waClient.on('ready', () => { waStatus = 'ready'; console.log('[WA] Client is ready!'); io.emit('wa:status', { status: waStatus }); });
    waClient.on('auth_failure', (msg) => { waStatus = 'failed'; console.error('[WA] Auth failure:', msg); io.emit('wa:status', { status: waStatus }); });
    waClient.on('disconnected', (reason) => {
        waStatus = 'disconnected';
        console.log('[WA] Disconnected:', reason);
        io.emit('wa:status', { status: waStatus });
        setTimeout(() => { console.log('[WA] Reconnecting...'); waClient.initialize(); }, 15000);
    });

    // ══════════════════════════════════════════════════════════
    // INBOUND MESSAGE — RESOLVE ACTUAL PHONE FROM LID
    // ══════════════════════════════════════════════════════════
    waClient.on('message', async (msg) => {
        if (msg.from === 'status@broadcast') return;

        let phone = '';

        // Try to get REAL phone number (LID format doesn't contain phone)
        try {
            const contact = await msg.getContact();
            if (contact && contact.number) {
                phone = contact.number; // This is the actual phone like "917004667347"
                console.log(`[WA] Resolved phone from contact: ${phone}`);
            }
        } catch (e) {
            console.log(`[WA] getContact failed: ${e.message}`);
        }

        // Fallback: try msg._data.from or msg._data.author
        if (!phone) {
            const rawFrom = msg._data?.from || msg.from || '';
            // If it's @c.us format, extract phone
            if (rawFrom.includes('@c.us')) {
                phone = rawFrom.replace('@c.us', '');
                console.log(`[WA] Got phone from @c.us: ${phone}`);
            } else if (rawFrom.includes('@lid')) {
                // LID — try _data.notifyName or participant
                phone = rawFrom.replace('@lid', '');
                console.log(`[WA] WARNING: Only have LID: ${phone} — will try LIKE match in PHP`);
            } else {
                phone = rawFrom.replace(/[^0-9]/g, '');
            }
        }

        // Also try to get phone from chat
        if (!phone || phone.length > 15) {
            try {
                const chat = await msg.getChat();
                if (chat && chat.id && chat.id._serialized) {
                    const chatId = chat.id._serialized;
                    if (chatId.includes('@c.us')) {
                        phone = chatId.replace('@c.us', '');
                        console.log(`[WA] Got phone from chat ID: ${phone}`);
                    }
                }
            } catch (e) {
                console.log(`[WA] getChat failed: ${e.message}`);
            }
        }

        const messageData = {
            event: 'message_received',
            phone: phone,
            message: msg.body,
            wa_message_id: msg.id._serialized,
            timestamp: msg.timestamp,
            type: msg.type,
            from_name: msg._data?.notifyName || null
        };

        console.log(`[WA] Inbound | Phone: ${phone} | Name: ${msg._data?.notifyName} | Msg: ${msg.body.substring(0, 40)}...`);
        io.emit('message:inbound', messageData);
        await sendWebhook(messageData);
    });

    // OUTBOUND ACK
    waClient.on('message_create', async (msg) => {
        if (!msg.fromMe) return;
        const phone = msg.to.replace('@c.us', '').replace('@lid', '');
        io.emit('message:outbound_ack', { event: 'message_sent_ack', phone, message: msg.body, wa_message_id: msg.id._serialized, timestamp: msg.timestamp });
    });

    console.log('[WA] Initializing...');
    waClient.initialize();
}

// ══════════════════════════════════════════════════════════
// WEBHOOK
// ══════════════════════════════════════════════════════════
async function sendWebhook(data) {
    if (!WEBHOOK_URL) { console.log('[WEBHOOK] No URL configured'); return; }
    try {
        const response = await axios.post(WEBHOOK_URL, data, {
            headers: { 'Content-Type': 'application/json', 'X-Webhook-Source': 'wa-engine' },
            timeout: 15000,
            maxRedirects: 5
        });
        console.log(`[WEBHOOK] Sent: ${data.event} for ${data.phone} — Status: ${response.status}`);
    } catch (error) {
        console.error(`[WEBHOOK] Failed: ${error.message}`);
        if (error.response) console.error(`[WEBHOOK] Status: ${error.response.status}, Body: ${JSON.stringify(error.response.data)}`);
    }
}

// AUTH MIDDLEWARE
function authenticateAPI(req, res, next) {
    const apiKey = req.headers['x-api-key'];
    if (!apiKey || apiKey !== API_KEY) return res.status(401).json({ success: false, error: 'Unauthorized' });
    next();
}

// ROUTES
app.get('/', (req, res) => {
    res.send(`<!DOCTYPE html><html><head><title>WA CRM Engine</title><style>body{font-family:-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{background:#1e293b;border-radius:16px;padding:40px;text-align:center;max-width:500px;width:90%}h1{color:#10b981;margin:0 0 8px;font-size:24px}.badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}.badge.ready{background:#064e3b;color:#6ee7b7}.badge.pending{background:#78350f;color:#fbbf24}.badge.offline{background:#7f1d1d;color:#fca5a5}a{color:#10b981}</style></head><body><div class="card"><h1>🟢 WhatsApp CRM Engine</h1><p style="color:#94a3b8;font-size:14px">Running on Hugging Face Spaces</p><span class="badge ${waStatus === 'ready' ? 'ready' : waStatus === 'qr_pending' ? 'pending' : 'offline'}">WhatsApp: ${waStatus}</span>${waStatus === 'qr_pending' ? '<p><a href="/qr">👉 Scan QR Code</a></p>' : ''}${waStatus === 'ready' ? '<p style="color:#6ee7b7;margin-top:16px">✓ Connected & Ready</p>' : ''}<p style="font-size:12px;color:#64748b;margin-top:16px"><a href="/health">/health</a> | <a href="/qr">/qr</a></p></div></body></html>`);
});

app.get('/health', (req, res) => { res.json({ success: true, status: 'running', whatsapp: waStatus, uptime: process.uptime(), timestamp: new Date().toISOString(), platform: 'huggingface-spaces' }); });

app.get('/qr', (req, res) => {
    if (waStatus === 'ready') return res.send('<html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#6ee7b7"><div style="text-align:center"><h2>✓ WhatsApp Connected!</h2><a href="/" style="color:#10b981">← Back</a></div></body></html>');
    if (!qrCodeImage) return res.send('<html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#fbbf24"><div style="text-align:center"><h2>⏳ Generating QR...</h2><meta http-equiv="refresh" content="5"><a href="/" style="color:#10b981">← Back</a></div></body></html>');
    res.send(`<html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#e2e8f0"><div style="text-align:center"><h2>📱 Scan QR Code</h2><img src="${qrCodeImage}" style="border-radius:12px;margin:20px 0;background:white;padding:12px"><meta http-equiv="refresh" content="20"><br><a href="/" style="color:#10b981">← Back</a></div></body></html>`);
});

app.get('/wa-status', authenticateAPI, (req, res) => { res.json({ success: true, status: waStatus, qr: qrCodeData }); });

app.post('/send-message', authenticateAPI, async (req, res) => {
    try {
        const { phone, message, lead_id } = req.body;
        if (!phone || !message) return res.status(400).json({ success: false, error: 'Phone and message required' });
        if (waStatus !== 'ready') return res.status(503).json({ success: false, error: 'WhatsApp not ready' });
        const chatId = phone.replace(/[^0-9]/g, '') + '@c.us';
        const sentMsg = await waClient.sendMessage(chatId, message);
        const responseData = { success: true, wa_message_id: sentMsg.id._serialized, phone, lead_id: lead_id || null, timestamp: Date.now() };
        io.emit('message:sent', { ...responseData, message });
        await sendWebhook({ event: 'message_sent', phone: phone.replace(/[^0-9]/g, ''), message, wa_message_id: sentMsg.id._serialized, lead_id: lead_id || null, timestamp: Math.floor(Date.now() / 1000) });
        console.log(`[API] Sent to ${phone}`);
        res.json(responseData);
    } catch (error) { console.error(`[API] Send failed:`, error.message); res.status(500).json({ success: false, error: error.message }); }
});

app.post('/check-number', authenticateAPI, async (req, res) => {
    try {
        const { phone } = req.body;
        if (!phone) return res.status(400).json({ success: false, error: 'Phone required' });
        if (waStatus !== 'ready') return res.status(503).json({ success: false, error: 'WhatsApp not ready' });
        const cleanPhone = phone.replace(/[^0-9]/g, '');
        const isRegistered = await waClient.isRegisteredUser(cleanPhone + '@c.us');
        res.json({ success: true, phone: cleanPhone, is_registered: isRegistered });
    } catch (error) { res.status(500).json({ success: false, error: error.message }); }
});

app.post('/check-numbers-batch', authenticateAPI, async (req, res) => {
    try {
        const { phones } = req.body;
        if (!phones || !Array.isArray(phones)) return res.status(400).json({ success: false, error: 'Phones array required' });
        if (waStatus !== 'ready') return res.status(503).json({ success: false, error: 'WhatsApp not ready' });
        const results = [];
        for (const phone of phones.slice(0, 10)) {
            const cleanPhone = phone.replace(/[^0-9]/g, '');
            try { const isRegistered = await waClient.isRegisteredUser(cleanPhone + '@c.us'); results.push({ phone: cleanPhone, is_registered: isRegistered }); }
            catch (err) { results.push({ phone: cleanPhone, is_registered: false, error: err.message }); }
            await new Promise(resolve => setTimeout(resolve, 500));
        }
        res.json({ success: true, results });
    } catch (error) { res.status(500).json({ success: false, error: error.message }); }
});

// SOCKET.IO
io.on('connection', (socket) => {
    console.log(`[SOCKET] Connected: ${socket.id}`);
    socket.emit('wa:status', { status: waStatus });
    if (qrCodeImage && waStatus === 'qr_pending') socket.emit('wa:qr', { qr: qrCodeData, image: qrCodeImage });
    socket.on('disconnect', () => console.log(`[SOCKET] Disconnected: ${socket.id}`));
});

// SELF-PING
setInterval(async () => {
    try { await axios.get(SELF_PING_URL || `http://localhost:${PORT}/health`, { timeout: 5000 }); } catch (e) {}
}, 10 * 60 * 1000);

// START
server.listen(PORT, '0.0.0.0', () => {
    console.log(`[SERVER] WhatsApp CRM Engine running on port ${PORT}`);
    initWhatsApp();
});

process.on('SIGINT', async () => { if (waClient) await waClient.destroy(); server.close(); process.exit(0); });
process.on('SIGTERM', async () => { if (waClient) await waClient.destroy(); server.close(); process.exit(0); });
