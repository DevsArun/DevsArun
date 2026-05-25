/**
 * ============================================================
 * WhatsApp CRM Engine - Hugging Face Spaces Version
 * ============================================================
 * Modified for HF Docker Space (Free Tier)
 * Port: 7860 (mandatory for HF)
 * Session: LocalAuth with backup awareness
 * Self-ping: Keeps space alive
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

// ============================================================
// CONFIGURATION
// ============================================================
const PORT = process.env.PORT || 7860; // HF requires 7860
const API_KEY = process.env.API_KEY || 'default_dev_key_change_this';
const WEBHOOK_URL = process.env.WEBHOOK_URL || '';
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET || 'default_secret';
const CORS_ORIGIN = process.env.SOCKET_CORS_ORIGIN || '*';
const SESSION_NAME = process.env.SESSION_NAME || 'wa-crm-session';
const SELF_PING_URL = process.env.SELF_PING_URL || ''; // HF space URL for keep-alive
const SPACE_URL = process.env.SPACE_URL || `http://localhost:${PORT}`;

// ============================================================
// EXPRESS + SOCKET.IO SETUP
// ============================================================
const app = express();
const server = http.createServer(app);

const io = new Server(server, {
    cors: {
        origin: CORS_ORIGIN === '*' ? true : CORS_ORIGIN.split(','),
        methods: ['GET', 'POST'],
        credentials: true
    }
});

app.use(cors({
    origin: CORS_ORIGIN === '*' ? true : CORS_ORIGIN.split(','),
    credentials: true
}));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// ============================================================
// WHATSAPP CLIENT
// ============================================================
let waClient = null;
let waStatus = 'disconnected';
let qrCodeData = null;
let qrCodeImage = null; // base64 PNG for web display
let lastQRTime = 0;

function initWhatsApp() {
    waClient = new Client({
        authStrategy: new LocalAuth({
            clientId: SESSION_NAME,
            dataPath: './wa_session'
        }),
        puppeteer: {
            headless: true,
            executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu',
                '--single-process',
                '--disable-extensions'
            ]
        }
    });

    // QR Code Event
    waClient.on('qr', async (qr) => {
        qrCodeData = qr;
        waStatus = 'qr_pending';
        lastQRTime = Date.now();

        // Generate QR as base64 image for web display
        try {
            qrCodeImage = await QRCode.toDataURL(qr, { width: 256, margin: 2 });
        } catch (e) {
            qrCodeImage = null;
        }

        // Also show in terminal
        qrcode.generate(qr, { small: true });
        console.log('[WA] QR Code generated - scan with WhatsApp');
        console.log('[WA] Or visit /qr endpoint in browser to see QR code');

        io.emit('wa:qr', { qr, image: qrCodeImage });
        io.emit('wa:status', { status: waStatus });
    });

    // Authenticated
    waClient.on('authenticated', () => {
        waStatus = 'authenticated';
        qrCodeData = null;
        qrCodeImage = null;
        console.log('[WA] Authenticated successfully');
        io.emit('wa:status', { status: waStatus });
    });

    // Ready
    waClient.on('ready', () => {
        waStatus = 'ready';
        console.log('[WA] Client is ready!');
        io.emit('wa:status', { status: waStatus });
    });

    // Auth Failure
    waClient.on('auth_failure', (msg) => {
        waStatus = 'failed';
        console.error('[WA] Auth failure:', msg);
        io.emit('wa:status', { status: waStatus, error: msg });
    });

    // Disconnected
    waClient.on('disconnected', (reason) => {
        waStatus = 'disconnected';
        console.log('[WA] Disconnected:', reason);
        io.emit('wa:status', { status: waStatus, reason });
        setTimeout(() => {
            console.log('[WA] Attempting reconnection...');
            waClient.initialize();
        }, 15000);
    });

    // ── INBOUND MESSAGE ──
    waClient.on('message', async (msg) => {
        if (msg.from === 'status@broadcast') return;

        // Handle both @c.us and @lid formats
        const phone = msg.from.replace('@c.us', '').replace('@lid', '');
        const messageData = {
            event: 'message_received',
            phone: phone,
            message: msg.body,
            wa_message_id: msg.id._serialized,
            timestamp: msg.timestamp,
            type: msg.type,
            from_name: msg._data?.notifyName || null
        };

        console.log(`[WA] Inbound from ${phone}: ${msg.body.substring(0, 50)}...`);
        io.emit('message:inbound', messageData);
        await sendWebhook(messageData);
    });

    // ── OUTBOUND ACK ──
    waClient.on('message_create', async (msg) => {
        if (!msg.fromMe) return;

        const phone = msg.to.replace('@c.us', '');
        const messageData = {
            event: 'message_sent_ack',
            phone: phone,
            message: msg.body,
            wa_message_id: msg.id._serialized,
            timestamp: msg.timestamp,
            type: msg.type
        };

        io.emit('message:outbound_ack', messageData);
    });

    console.log('[WA] Initializing WhatsApp client...');
    waClient.initialize();
}

// ============================================================
// WEBHOOK - Send to PHP
// ============================================================
async function sendWebhook(data) {
    if (!WEBHOOK_URL) {
        console.log('[WEBHOOK] No webhook URL configured, skipping');
        return;
    }

    try {
        // Send data object directly — axios will stringify with correct Content-Type
        const payload = JSON.stringify(data);
        const signature = crypto
            .createHmac('sha256', WEBHOOK_SECRET)
            .update(payload)
            .digest('hex');

        // Use axios.post with object (NOT pre-stringified string)
        // This ensures proper Content-Type and body transmission
        const response = await axios.post(WEBHOOK_URL, data, {
            headers: {
                'Content-Type': 'application/json',
                'X-Webhook-Signature': signature,
                'X-Webhook-Source': 'wa-engine'
            },
            timeout: 15000,
            maxRedirects: 5
        });

        console.log(`[WEBHOOK] Sent: ${data.event} for ${data.phone} — Status: ${response.status}`);
    } catch (error) {
        console.error(`[WEBHOOK] Failed: ${error.message}`);
        if (error.response) {
            console.error(`[WEBHOOK] Status: ${error.response.status}, Body: ${JSON.stringify(error.response.data)}`);
        }
    }
}

// ============================================================
// API KEY AUTH MIDDLEWARE
// ============================================================
function authenticateAPI(req, res, next) {
    const apiKey = req.headers['x-api-key'];
    if (!apiKey || apiKey !== API_KEY) {
        return res.status(401).json({ success: false, error: 'Unauthorized' });
    }
    next();
}

// ============================================================
// ROUTES
// ============================================================

// Home - Simple status page
app.get('/', (req, res) => {
    res.send(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>WhatsApp CRM Engine</title>
            <style>
                body { font-family: -apple-system, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .card { background: #1e293b; border-radius: 16px; padding: 40px; text-align: center; max-width: 500px; width: 90%; box-shadow: 0 8px 32px rgba(0,0,0,0.3); }
                h1 { color: #10b981; margin: 0 0 8px; font-size: 24px; }
                .status { font-size: 14px; color: #94a3b8; margin-bottom: 24px; }
                .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
                .badge.ready { background: #064e3b; color: #6ee7b7; }
                .badge.pending { background: #78350f; color: #fbbf24; }
                .badge.offline { background: #7f1d1d; color: #fca5a5; }
                .qr-section { margin-top: 20px; }
                .qr-section img { border-radius: 8px; background: white; padding: 8px; }
                a { color: #10b981; text-decoration: none; }
                .info { margin-top: 16px; font-size: 12px; color: #64748b; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>🟢 WhatsApp CRM Engine</h1>
                <p class="status">Running on Hugging Face Spaces</p>
                <span class="badge ${waStatus === 'ready' ? 'ready' : waStatus === 'qr_pending' ? 'pending' : 'offline'}">
                    WhatsApp: ${waStatus}
                </span>
                ${waStatus === 'qr_pending' ? '<div class="qr-section"><p>Scan QR Code:</p><a href="/qr">👉 Open QR Code Page</a></div>' : ''}
                ${waStatus === 'ready' ? '<p style="margin-top:16px;color:#6ee7b7;">✓ Connected & Ready to send messages</p>' : ''}
                <div class="info">
                    <p>Health: <a href="/health">/health</a></p>
                    <p>QR Code: <a href="/qr">/qr</a></p>
                </div>
            </div>
        </body>
        </html>
    `);
});

// Health Check (public - no auth needed)
app.get('/health', (req, res) => {
    res.json({
        success: true,
        status: 'running',
        whatsapp: waStatus,
        uptime: process.uptime(),
        memory: process.memoryUsage(),
        timestamp: new Date().toISOString(),
        platform: 'huggingface-spaces'
    });
});

// QR Code Page (for scanning from browser)
app.get('/qr', (req, res) => {
    if (waStatus === 'ready') {
        return res.send(`
            <html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#6ee7b7;">
            <div style="text-align:center">
                <h2>✓ WhatsApp Already Connected!</h2>
                <p>No QR needed. Engine is ready.</p>
                <a href="/" style="color:#10b981">← Back</a>
            </div></body></html>
        `);
    }

    if (!qrCodeImage) {
        return res.send(`
            <html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#fbbf24;">
            <div style="text-align:center">
                <h2>⏳ Waiting for QR Code...</h2>
                <p>QR code is being generated. Refresh in 5 seconds.</p>
                <meta http-equiv="refresh" content="5">
                <a href="/" style="color:#10b981">← Back</a>
            </div></body></html>
        `);
    }

    res.send(`
        <html><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#0f172a;color:#e2e8f0;">
        <div style="text-align:center">
            <h2>📱 Scan QR Code with WhatsApp</h2>
            <p style="color:#94a3b8;font-size:13px;">WhatsApp → Three dots → Linked Devices → Link a Device</p>
            <img src="${qrCodeImage}" alt="QR Code" style="border-radius:12px;margin:20px 0;background:white;padding:12px;">
            <p style="color:#64748b;font-size:12px;">QR refreshes automatically. If expired, reload this page.</p>
            <meta http-equiv="refresh" content="20">
            <br><a href="/" style="color:#10b981">← Back to Home</a>
        </div></body></html>
    `);
});

// WA Status (authenticated)
app.get('/wa-status', authenticateAPI, (req, res) => {
    res.json({
        success: true,
        status: waStatus,
        qr: qrCodeData,
        qr_image: qrCodeImage
    });
});

// ── SEND MESSAGE ──
app.post('/send-message', authenticateAPI, async (req, res) => {
    try {
        const { phone, message, lead_id } = req.body;

        if (!phone || !message) {
            return res.status(400).json({ success: false, error: 'Phone and message required' });
        }

        if (waStatus !== 'ready') {
            return res.status(503).json({ success: false, error: 'WhatsApp not ready', wa_status: waStatus });
        }

        const chatId = phone.replace(/[^0-9]/g, '') + '@c.us';
        const sentMsg = await waClient.sendMessage(chatId, message);

        const responseData = {
            success: true,
            wa_message_id: sentMsg.id._serialized,
            phone: phone,
            lead_id: lead_id || null,
            timestamp: Date.now()
        };

        io.emit('message:sent', { ...responseData, message });

        await sendWebhook({
            event: 'message_sent',
            phone: phone.replace(/[^0-9]/g, ''),
            message: message,
            wa_message_id: sentMsg.id._serialized,
            lead_id: lead_id || null,
            timestamp: Math.floor(Date.now() / 1000)
        });

        console.log(`[API] Message sent to ${phone}`);
        res.json(responseData);

    } catch (error) {
        console.error(`[API] Send failed:`, error.message);
        res.status(500).json({ success: false, error: error.message });
    }
});

// ── CHECK NUMBER ──
app.post('/check-number', authenticateAPI, async (req, res) => {
    try {
        const { phone } = req.body;

        if (!phone) {
            return res.status(400).json({ success: false, error: 'Phone required' });
        }

        if (waStatus !== 'ready') {
            return res.status(503).json({ success: false, error: 'WhatsApp not ready' });
        }

        const cleanPhone = phone.replace(/[^0-9]/g, '');
        const chatId = cleanPhone + '@c.us';
        const isRegistered = await waClient.isRegisteredUser(chatId);

        res.json({
            success: true,
            phone: cleanPhone,
            is_registered: isRegistered,
            whatsapp_status: isRegistered ? 'valid' : 'invalid'
        });

    } catch (error) {
        res.status(500).json({ success: false, error: error.message });
    }
});

// ── BATCH CHECK ──
app.post('/check-numbers-batch', authenticateAPI, async (req, res) => {
    try {
        const { phones } = req.body;
        if (!phones || !Array.isArray(phones)) {
            return res.status(400).json({ success: false, error: 'Phones array required' });
        }

        if (waStatus !== 'ready') {
            return res.status(503).json({ success: false, error: 'WhatsApp not ready' });
        }

        const batch = phones.slice(0, 10);
        const results = [];

        for (const phone of batch) {
            const cleanPhone = phone.replace(/[^0-9]/g, '');
            try {
                const isRegistered = await waClient.isRegisteredUser(cleanPhone + '@c.us');
                results.push({ phone: cleanPhone, is_registered: isRegistered });
            } catch (err) {
                results.push({ phone: cleanPhone, is_registered: false, error: err.message });
            }
            await new Promise(resolve => setTimeout(resolve, 500));
        }

        res.json({ success: true, results });
    } catch (error) {
        res.status(500).json({ success: false, error: error.message });
    }
});

// ============================================================
// SOCKET.IO
// ============================================================
io.on('connection', (socket) => {
    console.log(`[SOCKET] Connected: ${socket.id}`);
    socket.emit('wa:status', { status: waStatus });

    if (qrCodeImage && waStatus === 'qr_pending') {
        socket.emit('wa:qr', { qr: qrCodeData, image: qrCodeImage });
    }

    socket.on('ping', () => socket.emit('pong', { timestamp: Date.now() }));
    socket.on('disconnect', () => console.log(`[SOCKET] Disconnected: ${socket.id}`));
});

// ============================================================
// SELF-PING (Keep HF Space Alive)
// Pings itself every 10 minutes to prevent sleep
// ============================================================
function startSelfPing() {
    const pingUrl = SELF_PING_URL || `http://localhost:${PORT}/health`;

    setInterval(async () => {
        try {
            await axios.get(pingUrl, { timeout: 5000 });
            console.log('[PING] Self-ping successful');
        } catch (e) {
            console.log('[PING] Self-ping failed (ok if local)');
        }
    }, 10 * 60 * 1000); // Every 10 minutes
}

// ============================================================
// START SERVER
// ============================================================
server.listen(PORT, '0.0.0.0', () => {
    console.log(`
╔══════════════════════════════════════════════════╗
║   WhatsApp CRM Engine (Hugging Face)             ║
║   Port: ${PORT}                                      ║
║   Platform: Hugging Face Spaces (Free)           ║
║   URL: ${SPACE_URL}       ║
╚══════════════════════════════════════════════════╝
    `);

    // Start WhatsApp
    initWhatsApp();

    // Start self-ping to prevent space sleep
    startSelfPing();
});

// Graceful shutdown
process.on('SIGINT', async () => {
    console.log('\n[SERVER] Shutting down...');
    if (waClient) await waClient.destroy();
    server.close();
    process.exit(0);
});

process.on('SIGTERM', async () => {
    console.log('\n[SERVER] SIGTERM received...');
    if (waClient) await waClient.destroy();
    server.close();
    process.exit(0);
});
