/**
 * WaLead WhatsApp CRM Engine v2.0
 * Node.js Server with LID-to-Phone Mapping
 * Runs on Hugging Face Spaces (Docker, port 7860)
 */

const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const bodyParser = require('body-parser');
const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode');
const axios = require('axios');

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
  cors: { origin: '*', methods: ['GET', 'POST'] }
});

// Middleware
app.use(cors());
app.use(bodyParser.json({ limit: '50mb' }));
app.use(bodyParser.urlencoded({ extended: true, limit: '50mb' }));

// ============ CONFIGURATION ============
const PORT = process.env.PORT || 7860;
const WEBHOOK_URL = process.env.WEBHOOK_URL || '';
const DEBUG_MODE = process.env.DEBUG_MODE || 'true';

// ============ STATE MANAGEMENT ============
let clientReady = false;
let qrCodeData = null;
let connectionStatus = 'disconnected';
let lastActivity = null;
let messagesSentToday = 0;
let messagesReceived = 0;
let startTime = new Date();

// CRITICAL: LID-to-Phone mapping
const lidToPhoneMap = new Map();
const phoneToLidMap = new Map();

// Message queue for anti-ban
const messageQueue = [];
let isProcessingQueue = false;


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

// QR Code Event
client.on('qr', async (qr) => {
  debugLog('QR Code received');
  connectionStatus = 'waiting_for_scan';
  try {
    qrCodeData = await qrcode.toDataURL(qr);
    io.emit('qr', qrCodeData);
  } catch (err) {
    debugLog('QR generation error: ' + err.message);
  }
});

// Ready Event
client.on('ready', () => {
  debugLog('WhatsApp client is READY');
  clientReady = true;
  connectionStatus = 'connected';
  qrCodeData = null;
  lastActivity = new Date();
  io.emit('status', { status: 'connected' });
});

// Authenticated Event
client.on('authenticated', () => {
  debugLog('WhatsApp client authenticated');
  connectionStatus = 'authenticated';
});

// Auth Failure Event
client.on('auth_failure', (msg) => {
  debugLog('Authentication failed: ' + msg);
  connectionStatus = 'auth_failed';
  clientReady = false;
});

// Disconnected Event
client.on('disconnected', (reason) => {
  debugLog('WhatsApp disconnected: ' + reason);
  connectionStatus = 'disconnected';
  clientReady = false;
  qrCodeData = null;
});


// ============ INBOUND MESSAGE HANDLER (CRITICAL LID LOGIC) ============
client.on('message', async (message) => {
  try {
    debugLog(`Inbound message from: ${message.from}`);
    messagesReceived++;
    lastActivity = new Date();

    let resolvedPhone = null;
    const senderId = message.from;

    // Check if sender is @lid format
    if (senderId.endsWith('@lid')) {
      debugLog(`LID format detected: ${senderId}`);

      // Step 1: Check in-memory mapping
      if (lidToPhoneMap.has(senderId)) {
        resolvedPhone = lidToPhoneMap.get(senderId);
        debugLog(`Resolved from mapping: ${senderId} -> ${resolvedPhone}`);
      } else {
        // Step 2: Try getContact()
        try {
          const contact = await message.getContact();
          if (contact && contact.number) {
            resolvedPhone = contact.number;
            // Store in mapping
            lidToPhoneMap.set(senderId, resolvedPhone);
            phoneToLidMap.set(resolvedPhone, senderId);
            debugLog(`Resolved via getContact: ${senderId} -> ${resolvedPhone}`);
          }
        } catch (contactErr) {
          debugLog(`getContact failed: ${contactErr.message}`);
        }

        // Step 3: Fallback to chat.id
        if (!resolvedPhone) {
          try {
            const chat = await message.getChat();
            if (chat && chat.id && chat.id._serialized) {
              const chatId = chat.id._serialized;
              if (chatId.endsWith('@c.us')) {
                resolvedPhone = chatId.replace('@c.us', '');
                lidToPhoneMap.set(senderId, resolvedPhone);
                phoneToLidMap.set(resolvedPhone, senderId);
                debugLog(`Resolved via chat.id: ${senderId} -> ${resolvedPhone}`);
              }
            }
          } catch (chatErr) {
            debugLog(`chat.id fallback failed: ${chatErr.message}`);
          }
        }
      }
    } else if (senderId.endsWith('@c.us')) {
      // Standard phone format
      resolvedPhone = senderId.replace('@c.us', '');
    }

    // Build webhook payload
    const payload = {
      event: 'message_received',
      from: senderId,
      resolved_phone: resolvedPhone,
      body: message.body,
      timestamp: getISTTimestamp(),
      message_id: message.id._serialized,
      has_media: message.hasMedia,
      type: message.type
    };

    debugLog(`Webhook payload: ${JSON.stringify(payload)}`);

    // Send to webhook
    if (WEBHOOK_URL) {
      try {
        await axios.post(WEBHOOK_URL, payload, {
          headers: { 'Content-Type': 'application/json' },
          timeout: 10000
        });
        debugLog('Webhook delivered successfully');
      } catch (webhookErr) {
        debugLog(`Webhook delivery failed: ${webhookErr.message}`);
      }
    }

    // Emit via Socket.io for real-time updates
    io.emit('message_received', payload);

  } catch (err) {
    debugLog(`Message handler error: ${err.message}`);
  }
});


// ============ API ROUTES ============

// Health check
app.get('/', (req, res) => {
  res.send(getStatusPageHTML());
});

// Status API
app.get('/status', (req, res) => {
  res.json({
    status: connectionStatus,
    ready: clientReady,
    uptime: getUptime(),
    messages_sent_today: messagesSentToday,
    messages_received: messagesReceived,
    last_activity: lastActivity ? getISTFromDate(lastActivity) : null,
    lid_mappings: lidToPhoneMap.size,
    queue_size: messageQueue.length,
    timestamp: getISTTimestamp()
  });
});

// QR Code page
app.get('/qr', (req, res) => {
  res.send(getQRPageHTML());
});

// QR Code data endpoint
app.get('/qr-data', (req, res) => {
  res.json({
    qr: qrCodeData,
    status: connectionStatus
  });
});

// Send message (CRITICAL: captures LID mapping)
app.post('/send-message', async (req, res) => {
  try {
    const { phone, message } = req.body;

    if (!phone || !message) {
      return res.status(400).json({ success: false, error: 'Phone and message required' });
    }

    if (!clientReady) {
      return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
    }

    // Format phone number
    const formattedPhone = formatPhone(phone);
    const chatId = `${formattedPhone}@c.us`;

    debugLog(`Sending message to: ${chatId}`);

    // Send message
    const sentMsg = await client.sendMessage(chatId, message);
    messagesSentToday++;
    lastActivity = new Date();

    // CRITICAL: Capture LID mapping from the sent message's chat
    try {
      const chat = await sentMsg.getChat();
      if (chat && chat.id && chat.id._serialized) {
        const actualChatId = chat.id._serialized;
        if (actualChatId.endsWith('@lid')) {
          lidToPhoneMap.set(actualChatId, formattedPhone);
          phoneToLidMap.set(formattedPhone, actualChatId);
          debugLog(`LID mapped on send: ${actualChatId} -> ${formattedPhone}`);
        }
      }
    } catch (mapErr) {
      debugLog(`LID mapping on send failed: ${mapErr.message}`);
    }

    // Emit via Socket.io
    io.emit('message_sent', {
      phone: formattedPhone,
      message: message,
      timestamp: getISTTimestamp(),
      message_id: sentMsg.id._serialized
    });

    res.json({
      success: true,
      message_id: sentMsg.id._serialized,
      phone: formattedPhone,
      timestamp: getISTTimestamp()
    });

  } catch (err) {
    debugLog(`Send message error: ${err.message}`);
    res.status(500).json({ success: false, error: err.message });
  }
});


// Send bulk messages with anti-ban delay
app.post('/send-bulk', async (req, res) => {
  try {
    const { messages } = req.body;
    // messages = [{ phone, message }]

    if (!messages || !Array.isArray(messages) || messages.length === 0) {
      return res.status(400).json({ success: false, error: 'Messages array required' });
    }

    if (!clientReady) {
      return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
    }

    // Add to queue
    messages.forEach(msg => {
      messageQueue.push({
        phone: msg.phone,
        message: msg.message,
        added_at: new Date()
      });
    });

    // Start processing if not already
    if (!isProcessingQueue) {
      processMessageQueue();
    }

    res.json({
      success: true,
      queued: messages.length,
      total_in_queue: messageQueue.length,
      timestamp: getISTTimestamp()
    });

  } catch (err) {
    debugLog(`Bulk send error: ${err.message}`);
    res.status(500).json({ success: false, error: err.message });
  }
});

// Get LID mappings
app.get('/lid-mappings', (req, res) => {
  const mappings = {};
  lidToPhoneMap.forEach((phone, lid) => {
    mappings[lid] = phone;
  });
  res.json({ success: true, mappings, count: lidToPhoneMap.size });
});

// Webhook endpoint (for PHP to update config)
app.post('/update-config', (req, res) => {
  const { webhook_url } = req.body;
  if (webhook_url) {
    // Update webhook URL dynamically
    process.env.WEBHOOK_URL = webhook_url;
    debugLog(`Webhook URL updated to: ${webhook_url}`);
  }
  res.json({ success: true, timestamp: getISTTimestamp() });
});

// Get messages for a specific phone (polling endpoint)
app.get('/messages/:phone', async (req, res) => {
  try {
    const phone = formatPhone(req.params.phone);
    const limit = parseInt(req.query.limit) || 50;

    if (!clientReady) {
      return res.status(503).json({ success: false, error: 'WhatsApp not connected' });
    }

    const chatId = `${phone}@c.us`;
    let chat = null;

    // Try direct phone format first
    try {
      chat = await client.getChatById(chatId);
    } catch (e) {
      // Try LID format
      if (phoneToLidMap.has(phone)) {
        const lidId = phoneToLidMap.get(phone);
        try {
          chat = await client.getChatById(lidId);
        } catch (e2) {
          debugLog(`Could not find chat for ${phone}`);
        }
      }
    }

    if (!chat) {
      return res.json({ success: true, messages: [], phone });
    }

    const messages = await chat.fetchMessages({ limit });
    const formatted = messages.map(msg => ({
      id: msg.id._serialized,
      body: msg.body,
      from_me: msg.fromMe,
      timestamp: getISTFromDate(new Date(msg.timestamp * 1000)),
      type: msg.type,
      has_media: msg.hasMedia
    }));

    res.json({ success: true, messages: formatted, phone });

  } catch (err) {
    debugLog(`Get messages error: ${err.message}`);
    res.status(500).json({ success: false, error: err.message });
  }
});


// ============ MESSAGE QUEUE PROCESSOR (ANTI-BAN) ============
async function processMessageQueue() {
  if (isProcessingQueue || messageQueue.length === 0) return;
  isProcessingQueue = true;

  while (messageQueue.length > 0) {
    const item = messageQueue.shift();
    try {
      const formattedPhone = formatPhone(item.phone);
      const chatId = `${formattedPhone}@c.us`;

      const sentMsg = await client.sendMessage(chatId, item.message);
      messagesSentToday++;
      lastActivity = new Date();

      // Capture LID mapping
      try {
        const chat = await sentMsg.getChat();
        if (chat && chat.id && chat.id._serialized) {
          const actualChatId = chat.id._serialized;
          if (actualChatId.endsWith('@lid')) {
            lidToPhoneMap.set(actualChatId, formattedPhone);
            phoneToLidMap.set(formattedPhone, actualChatId);
            debugLog(`LID mapped (bulk): ${actualChatId} -> ${formattedPhone}`);
          }
        }
      } catch (mapErr) {
        debugLog(`LID mapping (bulk) failed: ${mapErr.message}`);
      }

      // Emit progress
      io.emit('bulk_progress', {
        phone: formattedPhone,
        status: 'sent',
        remaining: messageQueue.length,
        timestamp: getISTTimestamp()
      });

      debugLog(`Bulk sent to ${formattedPhone}, remaining: ${messageQueue.length}`);

      // Anti-ban delay: 120-300 seconds
      if (messageQueue.length > 0) {
        const delay = getRandomDelay(120, 300);
        debugLog(`Anti-ban delay: ${delay}s`);
        await sleep(delay * 1000);
      }

    } catch (err) {
      debugLog(`Queue processing error for ${item.phone}: ${err.message}`);
      io.emit('bulk_progress', {
        phone: item.phone,
        status: 'failed',
        error: err.message,
        remaining: messageQueue.length,
        timestamp: getISTTimestamp()
      });
    }
  }

  isProcessingQueue = false;
  debugLog('Message queue processing complete');
  io.emit('bulk_complete', { timestamp: getISTTimestamp() });
}

// ============ HELPER FUNCTIONS ============
function formatPhone(phone) {
  // Remove all non-digit characters
  let cleaned = phone.toString().replace(/[^0-9]/g, '');
  // Remove leading + if present in original
  // Ensure it starts with country code (default 91 for India)
  if (cleaned.length === 10) {
    cleaned = '91' + cleaned;
  }
  return cleaned;
}

function getISTTimestamp() {
  return new Date().toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' });
}

function getISTFromDate(date) {
  return date.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' });
}

function getUptime() {
  const diff = Date.now() - startTime.getTime();
  const hours = Math.floor(diff / 3600000);
  const minutes = Math.floor((diff % 3600000) / 60000);
  return `${hours}h ${minutes}m`;
}

function getRandomDelay(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function debugLog(msg) {
  if (DEBUG_MODE === 'true') {
    const ist = getISTTimestamp();
    console.log(`[WaLead ${ist}] ${msg}`);
  }
}


// ============ STATUS PAGE HTML ============
function getStatusPageHTML() {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WaLead Engine - Status</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 50%, #f0fdf4 100%); }
    .pulse { animation: pulse 2s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
  </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-lg w-full border border-green-100">
    <div class="text-center mb-6">
      <h1 class="text-3xl font-bold text-gray-800">WaLead Engine</h1>
      <p class="text-green-600 font-medium">WhatsApp CRM Engine v2.0</p>
    </div>
    <div id="status-container" class="space-y-4">
      <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
        <span class="text-gray-600">Status</span>
        <span id="conn-status" class="font-semibold text-yellow-600">Loading...</span>
      </div>
      <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
        <span class="text-gray-600">Uptime</span>
        <span id="uptime" class="font-semibold text-gray-800">-</span>
      </div>
      <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
        <span class="text-gray-600">Messages Sent Today</span>
        <span id="sent" class="font-semibold text-green-600">0</span>
      </div>
      <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
        <span class="text-gray-600">Messages Received</span>
        <span id="received" class="font-semibold text-blue-600">0</span>
      </div>
      <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
        <span class="text-gray-600">LID Mappings</span>
        <span id="mappings" class="font-semibold text-purple-600">0</span>
      </div>
      <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
        <span class="text-gray-600">Queue Size</span>
        <span id="queue" class="font-semibold text-orange-600">0</span>
      </div>
      <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
        <span class="text-gray-600">Last Activity</span>
        <span id="last-activity" class="font-semibold text-gray-800">-</span>
      </div>
    </div>
    <div class="mt-6 flex gap-3">
      <a href="/qr" class="flex-1 text-center bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 transition">QR Code</a>
      <a href="/status" class="flex-1 text-center bg-gray-600 text-white py-2 px-4 rounded-lg hover:bg-gray-700 transition">API Status</a>
    </div>
    <p class="text-center text-xs text-gray-400 mt-4">Powered by WaLead CRM Engine</p>
  </div>
  <script>
    async function fetchStatus() {
      try {
        const res = await fetch('/status');
        const data = await res.json();
        document.getElementById('conn-status').textContent = data.status;
        document.getElementById('conn-status').className = 'font-semibold ' + 
          (data.status === 'connected' ? 'text-green-600' : 'text-yellow-600');
        document.getElementById('uptime').textContent = data.uptime;
        document.getElementById('sent').textContent = data.messages_sent_today;
        document.getElementById('received').textContent = data.messages_received;
        document.getElementById('mappings').textContent = data.lid_mappings;
        document.getElementById('queue').textContent = data.queue_size;
        document.getElementById('last-activity').textContent = data.last_activity || 'None';
      } catch(e) { console.error(e); }
    }
    fetchStatus();
    setInterval(fetchStatus, 5000);
  </script>
</body>
</html>`;
}


// ============ QR PAGE HTML ============
function getQRPageHTML() {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WaLead - Scan QR</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-green-50 to-white flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-md w-full text-center border border-green-100">
    <h1 class="text-2xl font-bold text-gray-800 mb-2">WaLead Engine</h1>
    <p class="text-green-600 mb-6">Scan QR Code with WhatsApp</p>
    <div id="qr-container" class="mb-4">
      <div id="qr-loading" class="p-8">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-green-600 mx-auto"></div>
        <p class="text-gray-500 mt-4">Waiting for QR code...</p>
      </div>
      <img id="qr-image" class="mx-auto hidden rounded-lg border-2 border-green-200" style="max-width:280px" />
      <div id="connected-msg" class="hidden p-8">
        <div class="text-6xl mb-4">&#10004;</div>
        <p class="text-green-600 font-bold text-xl">Connected!</p>
        <p class="text-gray-500 mt-2">WhatsApp is ready</p>
      </div>
    </div>
    <p id="status-text" class="text-sm text-gray-500">Status: <span id="qr-status">Initializing...</span></p>
    <a href="/" class="inline-block mt-4 text-green-600 hover:text-green-700 text-sm">&larr; Back to Dashboard</a>
  </div>
  <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
  <script>
    const socket = io();
    
    async function checkQR() {
      try {
        const res = await fetch('/qr-data');
        const data = await res.json();
        updateUI(data.status, data.qr);
      } catch(e) { console.error(e); }
    }

    function updateUI(status, qr) {
      document.getElementById('qr-status').textContent = status;
      if (status === 'connected') {
        document.getElementById('qr-loading').classList.add('hidden');
        document.getElementById('qr-image').classList.add('hidden');
        document.getElementById('connected-msg').classList.remove('hidden');
      } else if (qr) {
        document.getElementById('qr-loading').classList.add('hidden');
        document.getElementById('connected-msg').classList.add('hidden');
        document.getElementById('qr-image').src = qr;
        document.getElementById('qr-image').classList.remove('hidden');
      }
    }

    socket.on('qr', (qr) => { updateUI('waiting_for_scan', qr); });
    socket.on('status', (data) => { updateUI(data.status, null); });

    checkQR();
    setInterval(checkQR, 3000);
  </script>
</body>
</html>`;
}


// ============ SOCKET.IO CONNECTION ============
io.on('connection', (socket) => {
  debugLog(`Socket connected: ${socket.id}`);
  socket.emit('status', { status: connectionStatus });
  if (qrCodeData) {
    socket.emit('qr', qrCodeData);
  }
});

// ============ DAILY RESET ============
function resetDailyCounters() {
  const now = new Date();
  const istHour = parseInt(now.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', hour: 'numeric', hour12: false }));
  if (istHour === 0) {
    messagesSentToday = 0;
    debugLog('Daily counters reset');
  }
}
setInterval(resetDailyCounters, 3600000); // Check every hour

// ============ START SERVER ============
server.listen(PORT, '0.0.0.0', () => {
  console.log(`
  ╔══════════════════════════════════════╗
  ║    WaLead WhatsApp CRM Engine v2.0   ║
  ║    Running on port ${PORT}              ║
  ║    Status: http://0.0.0.0:${PORT}       ║
  ║    QR:     http://0.0.0.0:${PORT}/qr    ║
  ╚══════════════════════════════════════╝
  `);
  debugLog('Server started, initializing WhatsApp client...');
  client.initialize();
});

// Graceful shutdown
process.on('SIGTERM', async () => {
  debugLog('SIGTERM received, shutting down...');
  try {
    await client.destroy();
  } catch (e) {}
  process.exit(0);
});

process.on('SIGINT', async () => {
  debugLog('SIGINT received, shutting down...');
  try {
    await client.destroy();
  } catch (e) {}
  process.exit(0);
});
