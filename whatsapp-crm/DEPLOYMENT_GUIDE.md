# WhatsApp CRM — Complete Deployment Guide
# Step-by-Step: Zero se Production Tak

---

## 📋 PREREQUISITES (Ye Pehle Se Ready Hona Chahiye)

| Item | Status |
|------|--------|
| Hostinger hosting (files uploaded) | ✅ Done |
| MySQL database (connected) | ✅ Done |
| schema.sql imported in DB | ✅ Done |
| Groq API key (free) | Get from https://console.groq.com |
| A Cloud VPS (for Node.js) | Need to buy |
| WhatsApp Business number (ya personal) | Ready rakhna |

---

## 🖥️ STEP 1: VPS KAHAN SE LEIN?

### Recommended VPS Providers (Cheapest to Best):

| Provider | Price | RAM | Best For |
|----------|-------|-----|----------|
| **Contabo** | ₹350/mo | 4GB | Budget friendly |
| **Hetzner** | ₹400/mo | 2GB | Fast EU servers |
| **DigitalOcean** | ₹500/mo | 1GB | Easy UI |
| **Vultr** | ₹500/mo | 1GB | Good for India |
| **AWS Lightsail** | ₹300/mo | 512MB | Free tier possible |
| **Oracle Cloud** | FREE | 1GB | Always Free tier! |

### 🎯 Best Free Option: Oracle Cloud Free Tier
- 1 GB RAM, 1 CPU — enough for WhatsApp engine
- Always free (no credit card charge)
- Sign up: https://cloud.oracle.com/

### 🎯 Best Paid Option: Contabo VPS S (₹350/mo)
- 4 GB RAM, 2 CPU — very comfortable
- https://contabo.com/

### VPS Order Karte Waqt:
- **OS Choose:** Ubuntu 22.04 LTS
- **Location:** Closest to you (Mumbai/Singapore)
- **Authentication:** Password ya SSH key (password easy hai beginners ke liye)

---

## 🔑 STEP 2: VPS ME LOGIN KAISE KAREIN

### Windows Users:
1. Download **PuTTY** — https://putty.org/
2. Ya better: Download **MobaXterm** — https://mobaxterm.mobatek.net/ (free)
3. Open MobaXterm → New Session → SSH
4. Enter:
   - Host: `YOUR_VPS_IP` (jo provider ne diya)
   - Username: `root`
   - Password: (jo set kiya tha)

### Mac/Linux Users:
```bash
ssh root@YOUR_VPS_IP
# Enter password when asked
```

### First Login Success Dikhega:
```
Welcome to Ubuntu 22.04 LTS
root@vps:~#
```

**Yahan se sab kaam hoga terminal mein.**

---

## ⚙️ STEP 3: VPS PE ENVIRONMENT SETUP

### 3.1 — System Update
```bash
apt update && apt upgrade -y
```

### 3.2 — Node.js 18+ Install
```bash
curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
apt install -y nodejs
```

### Verify:
```bash
node --version
# v18.x.x hona chahiye

npm --version
# 9.x.x ya upar
```

### 3.3 — PM2 Install (Process Manager)
```bash
npm install -g pm2
```

### 3.4 — Google Chrome / Chromium Install (Puppeteer ke liye)
```bash
apt install -y chromium-browser
# Ya:
apt install -y gconf-service libasound2 libatk1.0-0 libatk-bridge2.0-0 \
  libcups2 libdbus-1-3 libdrm2 libgbm1 libgtk-3-0 libnspr4 libnss3 \
  libx11-xcb1 libxcomposite1 libxdamage1 libxrandr2 xdg-utils \
  libpango-1.0-0 libcairo2 libgdk-pixbuf2.0-0 fonts-liberation
```

### 3.5 — Git Install (agar nahi hai)
```bash
apt install -y git
```

---

## 📦 STEP 4: NODE.JS APP UPLOAD KAISE KAREIN

### Option A: Git Clone (Recommended)
```bash
# Home directory mein ja
cd /root

# Repo clone kar
git clone https://github.com/DevsArun/DevsArun.git

# Node app folder mein ja
cd DevsArun/whatsapp-crm/nodejs
```

### Option B: Manual Upload via SFTP
1. Open **MobaXterm** (ya FileZilla)
2. Connect via SFTP to your VPS IP
3. Navigate to `/root/`
4. Create folder: `whatsapp-crm`
5. Upload these 4 files inside:
   - `server.js`
   - `package.json`
   - `ecosystem.config.js`
   - `.env.example`

---

## 🔐 STEP 5: .env FILE CONFIGURE KARO

```bash
# Agar git clone kiya:
cd /root/DevsArun/whatsapp-crm/nodejs

# .env file create karo
cp .env.example .env

# Edit karo
nano .env
```

### .env file mein ye fill karo:

```env
# Server
PORT=3001
NODE_ENV=production

# API Key — Strong random string banao (koi bhi 32+ chars)
# Ye SAME key Hostinger ke app.php mein bhi jaayegi
API_KEY=mera_super_secret_api_key_123456789abcdef

# Hostinger Webhook URL — Jahan Node events bhejega
WEBHOOK_URL=https://yourdomain.com/webhook.php

# Webhook Secret — SAME secret Hostinger ke app.php mein bhi hoga
WEBHOOK_SECRET=mera_webhook_secret_key_abcdef123456

# CORS — Tumhari Hostinger domain
SOCKET_CORS_ORIGIN=https://yourdomain.com

# Session name (change mat karo jab tak zaroor na ho)
SESSION_NAME=wa-crm-session
```

### Save karo: `Ctrl + X` → `Y` → `Enter`

---

## 📥 STEP 6: DEPENDENCIES INSTALL

```bash
# Make sure you're in nodejs folder
cd /root/DevsArun/whatsapp-crm/nodejs

# Install all packages
npm install
```

Ye 2-5 minute lega. Ye install hoga:
- whatsapp-web.js
- puppeteer (+ chromium download)
- express
- socket.io
- axios
- cors
- dotenv

### Agar Puppeteer error aaye:
```bash
# Skip chromium download (system chromium use karega)
PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true npm install

# Server.js mein puppeteer args mein add karo:
# executablePath: '/usr/bin/chromium-browser'
```

---

## 🚀 STEP 7: PEHLI BAAR APP START KARO (QR Code Ke Liye)

### Important: Pehli baar PM2 se MAT chalaao. Direct run karo taaki QR dikhe:

```bash
node server.js
```

### Ye dikhega:
```
╔══════════════════════════════════════════════╗
║   WhatsApp CRM Engine                        ║
║   Port: 3001                                 ║
║   Environment: production                    ║
╚══════════════════════════════════════════════╝

[WA] Initializing WhatsApp client...
[WA] QR Code generated - scan with WhatsApp
```

### QR CODE TERMINAL MEIN DIKHEGA:
```
█████████████████████████████
█ ▄▄▄▄▄ █  ▀█▀ █▀█ ▄▄▄▄▄ █
█ █   █ █▄▀▀▀▄▀██  █   █ █
█ ▄▄▄▄▄ █ ▀█▀▄▀▀█ ▄▄▄▄▄ █
█▄▄▄▄▄▄▄█▄█▄█▄▄▄█▄▄▄▄▄▄▄█
```

---

## 📱 STEP 8: QR CODE SCAN KAISE KAREIN

1. **WhatsApp** open karo apne phone pe
2. Three dots menu (⋮) → **Linked Devices**
3. **"Link a Device"** tap karo
4. Terminal mein jo QR code dikh raha hai, **scan karo**
5. 2-3 second mein connect ho jaayega

### Success message:
```
[WA] Authenticated successfully
[WA] Client is ready!
```

### 🎉 WhatsApp Connected! Ab `Ctrl + C` press karo server rok ke.

---

## 🔄 STEP 9: PM2 SE PERMANENT RUN KARO

Ab server permanently background mein chalega (reboot ke baad bhi):

```bash
# PM2 se start
pm2 start ecosystem.config.js

# Status check
pm2 status
```

### Dikhega:
```
┌─────────────────┬────┬─────────┬──────┬───────┐
│ App name        │ id │ mode    │ cpu  │ memory│
├─────────────────┼────┼─────────┼──────┼───────┤
│ whatsapp-engine │ 0  │ fork    │ 0.3% │ 180MB │
└─────────────────┴────┴─────────┴──────┴───────┘
```

### PM2 Useful Commands:
```bash
# Logs dekhna
pm2 logs whatsapp-engine

# Restart
pm2 restart whatsapp-engine

# Stop
pm2 stop whatsapp-engine

# Reboot ke baad auto-start setup
pm2 save
pm2 startup
```

### Auto-start Enable (IMPORTANT):
```bash
pm2 startup
# Jo command dikhe, woh copy paste karo
pm2 save
```

Ab VPS reboot hone pe bhi app auto-start hoga.

---

## 🌐 STEP 10: PORT OPEN KARO (FIREWALL)

Node server port 3001 pe chal raha hai. Bahar se access ke liye port open karna padega:

```bash
# UFW firewall
ufw allow 3001
ufw allow 22
ufw enable

# Verify
ufw status
```

### Check karo browser mein:
```
http://YOUR_VPS_IP:3001/health
```

### Response aana chahiye:
```json
{
  "success": true,
  "status": "running",
  "whatsapp": "ready",
  "uptime": 125.4
}
```

**Agar ye response aa raha hai = Node.js engine LIVE hai!** ✅

---

## 🔗 STEP 11: HOSTINGER SE NODE KO CONNECT KARO

### Hostinger pe `config/app.php` edit karo (File Manager ya FTP se):

```php
// ============================================================
// NODE.JS WHATSAPP ENGINE — Apna VPS IP daalo
// ============================================================
define('NODE_API_URL', 'http://YOUR_VPS_IP:3001');
define('NODE_API_KEY', 'mera_super_secret_api_key_123456789abcdef');

// ============================================================
// GROQ AI API — https://console.groq.com se lo
// ============================================================
define('GROQ_API_KEY', 'gsk_your_actual_groq_key_here');

// ============================================================
// WEBHOOK SECURITY — Same as Node .env
// ============================================================
define('WEBHOOK_SECRET', 'mera_webhook_secret_key_abcdef123456');

// ============================================================
// SOCKET.IO — Frontend isse connect hoga
// ============================================================
define('SOCKET_URL', 'http://YOUR_VPS_IP:3001');
```

### IMPORTANT MATCHING RULES:

| Hostinger (app.php) | Node (.env) | MUST MATCH |
|---------------------|-------------|------------|
| `NODE_API_KEY` | `API_KEY` | ✅ Same hona chahiye |
| `WEBHOOK_SECRET` | `WEBHOOK_SECRET` | ✅ Same hona chahiye |
| `SOCKET_URL` | Server IP:Port | ✅ Same IP |
| — | `WEBHOOK_URL` | Your Hostinger domain/webhook.php |

---

## ✅ STEP 12: VERIFY FULL CONNECTION

### Test 1: Node Health Check
```
Browser mein open karo: http://YOUR_VPS_IP:3001/health
Response: {"success":true, "whatsapp":"ready"}
```

### Test 2: Dashboard Open Karo
```
Browser mein: https://yourdomain.com/dashboard.php
- Left sidebar mein Engine: Online dikhna chahiye
- WhatsApp: Connected dikhna chahiye
```

### Test 3: CSV Upload
1. Dashboard pe "Upload CSV" click karo
2. `Patna_toys_shopss.csv` select karo
3. Leads import ho jaayengi
4. Lead list mein dikhni chahiye

### Test 4: Manual Message Test
1. Kisi lead pe click karo
2. Chat mein message type karo
3. Send button dabao
4. WhatsApp pe message jaana chahiye

---

## 🚀 STEP 13: CAMPAIGN RUN KARO

### Option A: Dashboard Se (Recommended)
- "Start Campaign" button click karo
- Ye 5 leads ek batch mein process karega
- Phir manually next batch trigger karo

### Option B: Terminal/CLI Se
```bash
# SSH into VPS ya Hostinger terminal
php scripts/campaign.php --limit=10
```

### Option C: Cron Job Set Karo (Hostinger)
```
# Hostinger Control Panel → Cron Jobs
# Har 2 ghante:
0 */2 * * * /usr/bin/php /home/u123/public_html/scripts/campaign.php --limit=10
```

---

## 🛡️ STEP 14: SECURITY CHECKLIST

| Check | Action |
|-------|--------|
| API Key strong hai? | 32+ random characters use karo |
| Webhook Secret strong? | Different from API key, 32+ chars |
| .env file exposed nahi? | `chmod 600 .env` |
| Logs folder writable? | `chmod 755 logs/` |
| CORS configured? | Only your domain allowed |
| Rate limiting? | Built-in (60 req/min) |
| SQL Injection? | PDO prepared statements ✅ |

### Strong Key Generate Karo:
```bash
# Terminal mein ye run karo
openssl rand -hex 32
# Output copy karo — ye tumhara key hai
```

---

## 🧯 TROUBLESHOOTING — Common Issues

### Issue: QR Code nahi dikh raha
```bash
# Puppeteer chromium path check
which chromium-browser
# Output: /usr/bin/chromium-browser

# Server.js mein puppeteer args mein add karo:
# executablePath: '/usr/bin/chromium-browser'
```

### Issue: WhatsApp disconnect ho jaata hai
```bash
# Session clear karo
rm -rf wa_session/
# Phir restart: node server.js (QR dubara scan)
pm2 restart whatsapp-engine
```

### Issue: Port 3001 open nahi
```bash
# Check agar port listen ho raha
ss -tlnp | grep 3001

# Agar firewall block kar raha
ufw allow 3001
iptables -A INPUT -p tcp --dport 3001 -j ACCEPT
```

### Issue: Dashboard pe "Engine Offline" dikh raha
1. VPS pe check: `pm2 status` (running hai?)
2. Browser mein: `http://VPS_IP:3001/health` (response aata hai?)
3. `app.php` mein `NODE_API_URL` sahi hai?
4. `SOCKET_URL` mein IP sahi hai?

### Issue: Webhook nahi aa rahe (messages DB mein nahi ja rahe)
```bash
# Node logs check
pm2 logs whatsapp-engine

# Ye dikhna chahiye:
# [WEBHOOK] Sent: message_received for 91xxxxxxxx

# Agar "Failed" dikh raha:
# Hostinger pe webhook.php accessible hai? Check:
curl -X POST https://yourdomain.com/webhook.php
# 405 error = GOOD (means file accessible hai, POST body missing)
```

### Issue: Campaign mein "Groq API failed"
- Groq console pe check: https://console.groq.com
- Key active hai? Rate limit hit toh nahi?
- `app.php` mein `GROQ_API_KEY` sahi hai?
- Fallback messages tab bhi kaam karengi (system built-in hai)

### Issue: npm install mein error
```bash
# Node version check (18+ chahiye)
node --version

# Agar purana hai:
curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
apt install -y nodejs

# Memory issue:
npm install --max-old-space-size=512
```

---

## 📊 COMPLETE DATA FLOW (Visual)

```
YOU (Laptop/Phone)
    │
    ▼
┌─────────────────────────────────────┐
│  HOSTINGER (yourdomain.com)          │
│                                      │
│  dashboard.php ← Browser mein open  │
│       │                              │
│       ├── Upload CSV → import_csv.php → MySQL │
│       ├── Click "Start Campaign"              │
│       │       │                               │
│       │       ▼                               │
│       │   campaign.php                        │
│       │       │                               │
│       │       ├── Check number (cURL) ────────┼──► Node /check-number
│       │       ├── Generate msg (Groq API)     │
│       │       └── Send msg (cURL) ────────────┼──► Node /send-message
│       │                                       │
│       │   webhook.php ◄───────────────────────┼─── Node (events)
│       │       │                               │
│       │       └── Store in MySQL              │
│       │                                       │
│       └── Socket.io ◄─────────────────────────┼─── Node (real-time)
│            (live chat updates)                 │
└───────────────────────────────────────────────┘
                        │
                        │ (Internet)
                        ▼
┌───────────────────────────────────────────────┐
│  CLOUD VPS (YOUR_VPS_IP:3001)                 │
│                                               │
│  Node.js + Express + Socket.io                │
│       │                                       │
│       ├── /health         → Status check      │
│       ├── /check-number   → WA registration   │
│       ├── /send-message   → Send via WA       │
│       │                                       │
│  whatsapp-web.js + Puppeteer + LocalAuth      │
│       │                                       │
│       └── Events:                             │
│           ├── message (inbound) → webhook.php │
│           └── message_create    → Socket emit │
└───────────────────────────────────────────────┘
                        │
                        │ (WhatsApp Servers)
                        ▼
┌───────────────────────────────────────────────┐
│  LEAD KA WHATSAPP PHONE                       │
│  (Message receive/reply karta hai)            │
└───────────────────────────────────────────────┘
```

---

## ⏱️ TIMELINE — Kitna Time Lagega Setup Mein

| Step | Time |
|------|------|
| VPS buy + login | 10 min |
| System setup (Node, PM2, Chrome) | 15 min |
| App upload + npm install | 10 min |
| .env configure | 5 min |
| First run + QR scan | 5 min |
| PM2 permanent setup | 5 min |
| Hostinger app.php update | 5 min |
| Testing | 10 min |
| **TOTAL** | **~60 min** |

---

## 🎯 QUICK COMMAND SUMMARY

```bash
# === VPS SETUP (One time) ===
apt update && apt upgrade -y
curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
apt install -y nodejs chromium-browser git
npm install -g pm2

# === APP SETUP ===
cd /root
git clone https://github.com/DevsArun/DevsArun.git
cd DevsArun/whatsapp-crm/nodejs
cp .env.example .env
nano .env                    # Fill values
npm install

# === FIRST RUN (QR Scan) ===
node server.js               # QR dikhega, scan karo
# Connected hone ke baad Ctrl+C

# === PERMANENT RUN ===
pm2 start ecosystem.config.js
pm2 save
pm2 startup                  # Auto-start on reboot

# === FIREWALL ===
ufw allow 22
ufw allow 3001
ufw enable

# === MONITORING ===
pm2 status                   # App status
pm2 logs whatsapp-engine     # Live logs
pm2 restart whatsapp-engine  # Restart
```

---

## 🎉 DONE!

Jab sab set ho jaaye:
1. `https://yourdomain.com/dashboard.php` open karo
2. CSV upload karo
3. "Start Campaign" dabao
4. Leads ko messages jayenge
5. Replies dashboard mein live aayengi
6. Manually reply karo dashboard se

**Ab tu ek fully working WhatsApp CRM cold outreach system chala raha hai!** 🚀
