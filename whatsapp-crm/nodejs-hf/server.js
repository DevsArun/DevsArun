/**
 * ============================================================
 * WhatsApp CRM Engine - Hugging Face Spaces Version
 * COMPLETE FINAL FIX — LID phone resolution via mapping
 * ============================================================
 */

require('dotenv').config();
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
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

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: true, methods: ['GET', 'POST'], credentials: true } });

app.use(cors({ origin: true, credentials: true }));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// ══════════════════════════════════════════════════════════
// PHONE-TO-LID MAPPING
// When we SEND a message to 917004667347@c.us, WhatsApp might
// respond from 188209946435616@lid. We store this mapping so
// when inbound comes from @lid, we know the real phone.
// ══════════════════════════════════════════════════════════
const phoneToLid = {};  // { "188209946435616": "917004667347" }
const lidToPhone = {};  // reverse lookup

function mapLidToPhone(lid, phone) {
    if (lid && phone) {
        const cleanLid = lid.replace(/[^0-9]/g, '');
        const cleanPhone = phone.replace(/[^0-9]/g, '');
        phoneToLid[cleanLid] = cleanPhone;
        lidToPhone[cleanPhone] = cleanLid;
        console.log(`[MAP] Stored: LID ${cleanLid} → Phone ${cleanPhone}`);
    }
}

function resolvePhone(rawFrom) {
    const clean = rawFrom.replace(/[^0-9]/g, '');
    // Check if this is a known LID
    if (phoneToLid[clean]) {
        console.log(`[MAP] Resolved LID ${clean} → Phone ${phoneToLid[clean]}`);
        return phoneToLid[clean];
    }
    // If it looks like a normal phone (10-12 digits), return as-is
    if (clean.length <= 12) {
        return clean;
    }
    // Unknown LID — return as-is, PHP will try to match
    return clean;
}

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
    // INBOUND MESSAGE
    // ══════════════════════════════════════════════════════════
    waClient.on('message', async (msg) => {
        if (msg.from === 'status@broadcast') return;

        // Get the raw "from" ID
        const rawFrom = msg.from || '';
        let phone = '';

        // STEP 1: Try to resolve from our LID mapping (most reliable)
        const fromClean = rawFrom.replace('@c.us', '').replace('@lid', '').replace(/[^0-9]/g, '');
        phone = resolvePhone(fromClean);

        // STEP 2: If still looks like LID (>12 digits), try getContact
        if (phone.length > 12) {
            try {
                const contact = await msg.getContact();
                if (contact && contact.number) {
                    phone = contact.number.replace(/[^0-9]/g, '');
                    // Store this mapping for future
                    mapLidToPhone(fromClean, phone);
                    console.log(`[WA] getContact resolved: ${phone}`);
                }
            } catch (e) {
                console.log(`[WA] getContact failed: ${e.message}`);
            }
        }

        // STEP 3: If still >12, try chat ID
        if (phone.length > 12) {
            try {
                const chat = await msg.getChat();
                if (chat && chat.id && chat.id._serialized && chat.id._serialized.includes('@c.us')) {
                    phone = chat.id._serialized.replace('@c.us', '').replace(/[^0-9]/g, '');
                    mapLidToPhone(fromClean, phone);
                    console.log(`[WA] Chat ID resolved: ${phone}`);
                }
            } catch (e) {}
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

        console.log(`[WA] Inbound | From: ${rawFrom} | Resolved Phone: ${phone} | Msg: ${msg.body.substring(0, 40)}`);
        io.emit('message:inbound', messageData);
        await sendWebhook(messageData);
    });

    // ══════════════════════════════════════════════════════════
    // OUTBOUND MESSAGE — Store LID mapping
    // ══════════════════════════════════════════════════════════
    waClient.on('message_create', async (msg) => {
        if (!msg.fromMe) return;

        const rawTo = msg.to || '';
        const toClean = rawTo.replace('@c.us', '').replace('@lid', '').replace(/[^0-9]/g, '');

        // If we sent to @c.us (normal phone), and the chat has an associated LID,
        // store the mapping. The msg.id contains the chat context.
        // For now, store rawTo as the phone we sent to
        if (rawTo.includes('@c.us') && toClean.length <= 12) {
            // Try to get LID from chat
            try {
                const chat = await msg.getChat();
                if (chat && chat.id) {
                    const chatIdStr = chat.id._serialized || '';
                    if (chatIdStr.includes('@lid')) {
                        const lid = chatIdStr.replace('@lid', '').replace(/[^0-9]/g, '');
                        mapLidToPhone(lid, toClean);
                    }
                }
            } catch (e) {}
        }

        io.emit('message:outbound_ack', {
            event: 'message_sent_ack',
            phone: toClean,
            message: msg.body,
            wa_message_id: msg.id._serialized,
            timestamp: msg.timestamp
        });
    });

    console.log('[WA] Initializing...');
    waClient.initialize();
}

// ══════════════════════════════════════════════════════════
// WEBHOOK — Send to PHP
// ══════════════════════════════════════════════════════════
async function sendWebhook(data) {
    if (!WEBHOOK_URL) { console.log('[WEBHOOK] No URL configured'); return; }
    try {
        const response = await axios.post(WEBHOOK_URL, data, {
            headers: { 'Content-Type': 'application/json', 'X-Webhook-Source': 'wa-engine' },
            timeout: 15000,
            maxRedirects: 5
        });
        console.log(`[WEBHOOK] OK: ${data.event} for ${data.phone} — Status: ${response.status}`);
    } catch (error) {
        console.error(`[WEBHOOK] FAILED: ${error.message}`);
        if (error.response) console.error(`[WEBHOOK] Response: ${error.response.status} ${JSON.stringify(error.response.data)}`);
    }
}

// AUTH MIDDLEWARE
function authenticateAPI(req, res, next) {
    const apiKey = req.headers['x-api-key'];
    if (!apiKey || apiKey !== API_KEY) return res.status(401).json({ success: false, error: 'Unauthorized' });
    next();
}

// ══════════════════════════════════════════════════════════
// ROUTES
// ══════════════════════════════════════════════════════════
app.get('/', (req, res) => {
    res.send(`<!DOCTYPE html><html><head><title>WA CRM</title><style>body{font-family:-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{background:#1e293b;border-radius:16px;padding:40px;text-align:center;max-width:500px;width:90%}h1{color:#10b981;font-size:24px}.badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}.ready{background:#064e3b;color:#6ee7b7}.pending{background:#78350f;color:#fbbf24}.offline{background:#7f1d1d;color:#fca5a5}a{color:#10b981}</style></head><body><div class="card"><h1>WhatsApp CRM Engine</h1><span class="badge ${waStatus === 'ready' ? 'ready' : waStatus === 'qr_pending' ? 'pending' : 'offline'}">${waStatus}</span>${waStatus === 'qr_pending' ? '<p><a href="/qr">Scan QR</a></p>' : ''}${waStatus === 'ready' ? '<p style="color:#6ee7b7">✓ Ready</p>' : ''}<p style="font-size:12px;color:#64748b"><a href="/health">/health</a> | <a href="/qr">/qr</a> | <a href="/map">/map</a></p></div></body></html>`);
});

app.get('/health', (req, res) => { res.json({ success: true, status: 'running', whatsapp: waStatus, uptime: process.uptime(), timestamp: new Date().toISOString() }); });

app.get('/qr', (req, res) => {
    if (waStatus === 'ready') return res.send('<html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#6ee7b7"><h2>✓ Connected</h2></body></html>');
    if (!qrCodeImage) return res.send('<html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#fbbf24"><h2>Generating QR...</h2><meta http-equiv="refresh" content="5"></body></html>');
    res.send(`<html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#e2e8f0"><div style="text-align:center"><h2>Scan QR Code</h2><img src="${qrCodeImage}" style="background:white;padding:12px;border-radius:12px"><meta http-equiv="refresh" content="20"></div></body></html>`);
});

// Debug: show current LID mappings
app.get('/map', (req, res) => { res.json({ phoneToLid, lidToPhone, total: Object.keys(phoneToLid).length }); });

app.get('/wa-status', authenticateAPI, (req, res) => { res.json({ success: true, status: waStatus }); });

// ══════════════════════════════════════════════════════════
// SEND MESSAGE — Also stores LID mapping
// ══════════════════════════════════════════════════════════
app.post('/send-message', authenticateAPI, async (req, res) => {
    try {
        const { phone, message, lead_id } = req.body;
        if (!phone || !message) return res.status(400).json({ success: false, error: 'Phone and message required' });
        if (waStatus !== 'ready') return res.status(503).json({ success: false, error: 'WhatsApp not ready' });

        const cleanPhone = phone.replace(/[^0-9]/g, '');
        const chatId = cleanPhone + '@c.us';
        const sentMsg = await waClient.sendMessage(chatId, message);

        // CRITICAL: After sending, check if chat uses LID and store mapping
        try {
            const chat = await sentMsg.getChat();
            if (chat && chat.id && chat.id._serialized) {
                const chatIdStr = chat.id._serialized;
                if (chatIdStr.includes('@lid')) {
                    const lid = chatIdStr.replace('@lid', '').replace(/[^0-9]/g, '');
                    mapLidToPhone(lid, cleanPhone);
                } else if (chatIdStr.includes('@c.us')) {
                    // Normal format — map to itself
                    const cus = chatIdStr.replace('@c.us', '').replace(/[^0-9]/g, '');
                    if (cus !== cleanPhone) {
                        mapLidToPhone(cus, cleanPhone);
                    }
                }
            }
        } catch (e) {
            console.log(`[MAP] Could not get chat after send: ${e.message}`);
        }

        const responseData = { success: true, wa_message_id: sentMsg.id._serialized, phone: cleanPhone, lead_id: lead_id || null, timestamp: Date.now() };
        io.emit('message:sent', { ...responseData, message });

        await sendWebhook({ event: 'message_sent', phone: cleanPhone, message, wa_message_id: sentMsg.id._serialized, lead_id: lead_id || null, timestamp: Math.floor(Date.now() / 1000) });

        console.log(`[API] Sent to ${cleanPhone}`);
        res.json(responseData);
    } catch (error) {
        console.error(`[API] Send failed:`, error.message);
        res.status(500).json({ success: false, error: error.message });
    }
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

// SOCKET.IO
io.on('connection', (socket) => {
    socket.emit('wa:status', { status: waStatus });
    if (qrCodeImage && waStatus === 'qr_pending') socket.emit('wa:qr', { qr: qrCodeData, image: qrCodeImage });
    socket.on('disconnect', () => {});
});

// SELF-PING
setInterval(async () => { try { await axios.get(SELF_PING_URL || `http://localhost:${PORT}/health`, { timeout: 5000 }); } catch (e) {} }, 10 * 60 * 1000);

// START
server.listen(PORT, '0.0.0.0', () => { console.log(`[SERVER] Running on port ${PORT}`); initWhatsApp(); });
process.on('SIGINT', async () => { if (waClient) await waClient.destroy(); process.exit(0); });
process.on('SIGTERM', async () => { if (waClient) await waClient.destroy(); process.exit(0); });
