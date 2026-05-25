/**
 * ============================================================
 * WhatsApp CRM Engine - Node.js Server
 * Express + Socket.io + whatsapp-web.js
 * ============================================================
 * 
 * This runs on Cloud VPS (Ubuntu).
 * Communicates with Hostinger PHP via webhooks.
 * Dashboard connects via Socket.io for live updates.
 */

require('dotenv').config();
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const crypto = require('crypto');
const axios = require('axios');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');

// ============================================================
// CONFIGURATION
// ============================================================
const PORT = process.env.PORT || 3001;
const API_KEY = process.env.API_KEY;
const WEBHOOK_URL = process.env.WEBHOOK_URL;
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET;
const CORS_ORIGIN = process.env.SOCKET_CORS_ORIGIN || '*';
const SESSION_NAME = process.env.SESSION_NAME || 'wa-crm-session';

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
// WHATSAPP CLIENT SETUP
// ============================================================
let waClient = null;
let waStatus = 'disconnected'; // disconnected, qr_pending, authenticated, ready, failed
let qrCode = null;

function initWhatsApp() {
  waClient = new Client({
    authStrategy: new LocalAuth({
      clientId: SESSION_NAME,
      dataPath: './wa_session'
    }),
    puppeteer: {
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-accelerated-2d-canvas',
        '--no-first-run',
        '--no-zygote',
        '--disable-gpu',
        '--single-process'
      ]
    }
  });

  // QR Code Event
  waClient.on('qr', (qr) => {
    qrCode = qr;
    waStatus = 'qr_pending';
    qrcode.generate(qr, { small: true });
    console.log('[WA] QR Code generated - scan with WhatsApp');
    io.emit('wa:qr', { qr });
    io.emit('wa:status', { status: waStatus });
  });

  // Authenticated
  waClient.on('authenticated', () => {
    waStatus = 'authenticated';
    qrCode = null;
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
    // Attempt reconnection after 10 seconds
    setTimeout(() => {
      console.log('[WA] Attempting reconnection...');
      waClient.initialize();
    }, 10000);
  });

  // ============================================================
  // INBOUND MESSAGE HANDLER
  // ============================================================
  waClient.on('message', async (msg) => {
    if (msg.from === 'status@broadcast') return; // Skip status updates

    const phone = msg.from.replace('@c.us', '');
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

    // Emit to dashboard via Socket.io
    io.emit('message:inbound', messageData);

    // Send to PHP webhook
    await sendWebhook(messageData);
  });

  // ============================================================
  // OUTBOUND MESSAGE ACK (sent from this device)
  // ============================================================
  waClient.on('message_create', async (msg) => {
    if (!msg.fromMe) return; // Only our sent messages

    const phone = msg.to.replace('@c.us', '');
    const messageData = {
      event: 'message_sent_ack',
      phone: phone,
      message: msg.body,
      wa_message_id: msg.id._serialized,
      timestamp: msg.timestamp,
      type: msg.type
    };

    // Emit to dashboard
    io.emit('message:outbound_ack', messageData);
  });

  // Initialize
  console.log('[WA] Initializing WhatsApp client...');
  waClient.initialize();
}

// ============================================================
// WEBHOOK HELPER - Send data to PHP
// ============================================================
async function sendWebhook(data) {
  try {
    const payload = JSON.stringify(data);
    const signature = crypto
      .createHmac('sha256', WEBHOOK_SECRET)
      .update(payload)
      .digest('hex');

    await axios.post(WEBHOOK_URL, data, {
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Signature': signature,
        'X-Webhook-Source': 'wa-engine'
      },
      timeout: 10000
    });

    console.log(`[WEBHOOK] Sent: ${data.event} for ${data.phone}`);
  } catch (error) {
    console.error(`[WEBHOOK] Failed: ${error.message}`);
  }
}

// ============================================================
// MIDDLEWARE - API Key Authentication
// ============================================================
function authenticateAPI(req, res, next) {
  const apiKey = req.headers['x-api-key'];
  if (!apiKey || apiKey !== API_KEY) {
    return res.status(401).json({
      success: false,
      error: 'Unauthorized - Invalid API key'
    });
  }
  next();
}

// ============================================================
// API ROUTES
// ============================================================

// Health Check
app.get('/health', (req, res) => {
  res.json({
    success: true,
    status: 'running',
    whatsapp: waStatus,
    uptime: process.uptime(),
    memory: process.memoryUsage(),
    timestamp: new Date().toISOString()
  });
});

// Get WA Status
app.get('/wa-status', authenticateAPI, (req, res) => {
  res.json({
    success: true,
    status: waStatus,
    qr: qrCode
  });
});

// ============================================================
// POST /send-message - Send WhatsApp message
// ============================================================
app.post('/send-message', authenticateAPI, async (req, res) => {
  try {
    const { phone, message, lead_id } = req.body;

    if (!phone || !message) {
      return res.status(400).json({
        success: false,
        error: 'Phone and message are required'
      });
    }

    if (waStatus !== 'ready') {
      return res.status(503).json({
        success: false,
        error: 'WhatsApp client not ready',
        wa_status: waStatus
      });
    }

    // Format phone for WhatsApp (remove + and add @c.us)
    const chatId = phone.replace(/[^0-9]/g, '') + '@c.us';

    // Send message
    const sentMsg = await waClient.sendMessage(chatId, message);

    const responseData = {
      success: true,
      wa_message_id: sentMsg.id._serialized,
      phone: phone,
      lead_id: lead_id || null,
      timestamp: Date.now()
    };

    // Emit to dashboard
    io.emit('message:sent', {
      ...responseData,
      message: message
    });

    // Send webhook to PHP
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
    res.status(500).json({
      success: false,
      error: error.message
    });
  }
});

// ============================================================
// POST /check-number - Verify WhatsApp registration
// ============================================================
app.post('/check-number', authenticateAPI, async (req, res) => {
  try {
    const { phone } = req.body;

    if (!phone) {
      return res.status(400).json({
        success: false,
        error: 'Phone number is required'
      });
    }

    if (waStatus !== 'ready') {
      return res.status(503).json({
        success: false,
        error: 'WhatsApp client not ready',
        wa_status: waStatus
      });
    }

    // Format phone and check registration
    const cleanPhone = phone.replace(/[^0-9]/g, '');
    const chatId = cleanPhone + '@c.us';

    const isRegistered = await waClient.isRegisteredUser(chatId);

    console.log(`[API] Number check: ${cleanPhone} = ${isRegistered ? 'VALID' : 'INVALID'}`);

    res.json({
      success: true,
      phone: cleanPhone,
      is_registered: isRegistered,
      whatsapp_status: isRegistered ? 'valid' : 'invalid'
    });

  } catch (error) {
    console.error(`[API] Check number failed:`, error.message);
    res.status(500).json({
      success: false,
      error: error.message
    });
  }
});

// ============================================================
// POST /check-numbers-batch - Batch validate (max 10 at a time)
// ============================================================
app.post('/check-numbers-batch', authenticateAPI, async (req, res) => {
  try {
    const { phones } = req.body;

    if (!phones || !Array.isArray(phones) || phones.length === 0) {
      return res.status(400).json({
        success: false,
        error: 'Phones array is required'
      });
    }

    if (waStatus !== 'ready') {
      return res.status(503).json({
        success: false,
        error: 'WhatsApp client not ready'
      });
    }

    // Limit batch size to prevent overload
    const batch = phones.slice(0, 10);
    const results = [];

    for (const phone of batch) {
      const cleanPhone = phone.replace(/[^0-9]/g, '');
      const chatId = cleanPhone + '@c.us';

      try {
        const isRegistered = await waClient.isRegisteredUser(chatId);
        results.push({ phone: cleanPhone, is_registered: isRegistered });
      } catch (err) {
        results.push({ phone: cleanPhone, is_registered: false, error: err.message });
      }

      // Small delay between checks to avoid rate limiting
      await new Promise(resolve => setTimeout(resolve, 500));
    }

    res.json({ success: true, results });

  } catch (error) {
    res.status(500).json({ success: false, error: error.message });
  }
});

// ============================================================
// SOCKET.IO CONNECTION
// ============================================================
io.on('connection', (socket) => {
  console.log(`[SOCKET] Client connected: ${socket.id}`);

  // Send current WA status to new connection
  socket.emit('wa:status', { status: waStatus });

  // Handle ping from dashboard
  socket.on('ping', () => {
    socket.emit('pong', { timestamp: Date.now() });
  });

  socket.on('disconnect', () => {
    console.log(`[SOCKET] Client disconnected: ${socket.id}`);
  });
});

// ============================================================
// START SERVER
// ============================================================
server.listen(PORT, () => {
  console.log(`
╔══════════════════════════════════════════════╗
║   WhatsApp CRM Engine                        ║
║   Port: ${PORT}                                  ║
║   Environment: ${process.env.NODE_ENV || 'development'}             ║
╚══════════════════════════════════════════════╝
  `);

  // Initialize WhatsApp after server starts
  initWhatsApp();
});

// ============================================================
// GRACEFUL SHUTDOWN
// ============================================================
process.on('SIGINT', async () => {
  console.log('\n[SERVER] Shutting down gracefully...');
  if (waClient) {
    await waClient.destroy();
  }
  server.close();
  process.exit(0);
});

process.on('SIGTERM', async () => {
  console.log('\n[SERVER] SIGTERM received, shutting down...');
  if (waClient) {
    await waClient.destroy();
  }
  server.close();
  process.exit(0);
});
