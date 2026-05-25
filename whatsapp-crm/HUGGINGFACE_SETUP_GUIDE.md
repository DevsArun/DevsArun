# 🤗 WhatsApp CRM — Hugging Face Free Deployment Guide
# Zero Budget Complete Setup

---

## 🎯 OVERVIEW

Tere paas:
- ✅ Hostinger pe PHP + MySQL (already done)
- ❌ VPS ke liye budget nahi

**Solution:** Hugging Face Spaces (Docker) pe Node.js WhatsApp engine FREE mein host karo.

### What You Get FREE:
- 2 vCPU
- 16 GB RAM
- 50 GB Storage
- Public HTTPS URL
- Docker container support
- Unlimited runtime (with self-ping)

---

## 📋 PREREQUISITES

| Item | Where to Get |
|------|-------------|
| Hugging Face account | https://huggingface.co/join (FREE) |
| Git installed on PC | https://git-scm.com/downloads |
| Hostinger (already done) | ✅ |
| Groq API key | https://console.groq.com (FREE) |

---

## 🚀 STEP 1: HUGGING FACE ACCOUNT BANAO

1. Go to: https://huggingface.co/join
2. Email se sign up karo (FREE, no card needed)
3. Email verify karo
4. Profile complete karo

**Done! 2 minute ka kaam.**

---

## 🏗️ STEP 2: NEW SPACE CREATE KARO

1. Login ke baad: https://huggingface.co/new-space
2. Fill these details:

| Field | Value |
|-------|-------|
| **Space name** | `whatsapp-crm-engine` |
| **License** | MIT (ya koi bhi) |
| **SDK** | **Docker** ← YE IMPORTANT HAI |
| **Docker template** | **Blank** |
| **Hardware** | **CPU Basic (Free)** |
| **Visibility** | **Private** (recommended — sensitive app hai) |

3. Click **"Create Space"**

### URL jo milega:
```
https://huggingface.co/spaces/TERA_USERNAME/whatsapp-crm-engine
```

### App URL (running app):
```
https://TERA_USERNAME-whatsapp-crm-engine.hf.space
```

---

## 🔐 STEP 3: SECRETS CONFIGURE KARO (Environment Variables)

### Space Settings mein jao:
1. Apne Space pe jao
2. Click **Settings** tab (gear icon)
3. Scroll down to **"Repository Secrets"**
4. Ye secrets add karo one by one:

| Secret Name | Value | Description |
|-------------|-------|-------------|
| `API_KEY` | `mera_strong_api_key_32chars_min` | PHP se connect ke liye |
| `WEBHOOK_URL` | `https://TERI_DOMAIN.com/webhook.php` | Hostinger webhook |
| `WEBHOOK_SECRET` | `mera_webhook_secret_32chars` | HMAC signature |
| `SOCKET_CORS_ORIGIN` | `https://TERI_DOMAIN.com` | Dashboard domain |
| `SELF_PING_URL` | `https://TERA_USERNAME-whatsapp-crm-engine.hf.space/health` | Keep-alive |
| `SPACE_URL` | `https://TERA_USERNAME-whatsapp-crm-engine.hf.space` | Self reference |

### Strong Key Generate Kaise Karein:
Browser console mein (F12 → Console):
```javascript
// 32 character random key
console.log(Array.from(crypto.getRandomValues(new Uint8Array(16))).map(b => b.toString(16).padStart(2, '0')).join(''))
```
Ya koi online random string generator use karo.

---

## 📤 STEP 4: FILES UPLOAD KARO (Git Method)

### Option A: Git se (Recommended — Fast & Clean)

#### Step 4.1 — HF CLI Install:
```bash
pip install huggingface_hub
```

#### Step 4.2 — Login:
```bash
huggingface-cli login
# Token paste karo (HF Settings → Access Tokens → New Token → Write)
```

#### Step 4.3 — Clone Space:
```bash
git clone https://huggingface.co/spaces/TERA_USERNAME/whatsapp-crm-engine
cd whatsapp-crm-engine
```

#### Step 4.4 — Files Copy:
Apne project ke `nodejs-hf/` folder se ye 4 files copy karo is folder mein:
- `Dockerfile`
- `server.js`
- `package.json`
- `README.md`

#### Step 4.5 — Push:
```bash
git add .
git commit -m "Initial deployment - WhatsApp CRM Engine"
git push
```

### Option B: Web UI se Upload (Easy — No Git Needed)

1. Space pe jao
2. **"Files"** tab click karo
3. **"Add file"** → **"Upload files"**
4. Ye 4 files drag-and-drop karo:
   - `Dockerfile`
   - `server.js`
   - `package.json`
   - `README.md`
5. **"Commit changes"** click karo

---

## ⏳ STEP 5: BUILD WAIT KARO

Push/Upload ke baad:
1. Space automatically build start karega
2. **"Building"** status dikhega (2-5 min lagega)
3. Logs mein progress dikhega
4. **"Running"** status aa jaaye = DONE! ✅

### Build Logs Check:
- Space page pe "Logs" button click karo
- Build progress dekhte raho
- Success: `[WA] Initializing WhatsApp client...`

### Agar Build Fail Ho:
- Logs padhte se error samajh aa jaayega
- Usually typo ya missing file hota hai
- Fix karo aur re-push karo

---

## 📱 STEP 6: QR CODE SCAN KARO

### Build complete hone ke baad:

1. Browser mein open karo:
```
https://TERA_USERNAME-whatsapp-crm-engine.hf.space/qr
```

2. QR code dikhega screen pe

3. Phone pe:
   - WhatsApp open karo
   - Three dots (⋮) → **Linked Devices**
   - **"Link a Device"** tap karo
   - Browser pe dikha QR code **scan karo**

4. 3-5 second mein connected ho jaayega

5. Page pe dikhega: **"✓ WhatsApp Already Connected!"**

### Verify:
```
https://TERA_USERNAME-whatsapp-crm-engine.hf.space/health
```

Response:
```json
{
  "success": true,
  "status": "running",
  "whatsapp": "ready",
  "platform": "huggingface-spaces"
}
```

**🎉 WhatsApp Engine LIVE hai!**

---

## 🔗 STEP 7: HOSTINGER SE CONNECT KARO

### Hostinger pe `config/app.php` edit karo:

```php
// ============================================================
// NODE.JS WHATSAPP ENGINE — HF Space URL
// ============================================================
define('NODE_API_URL', 'https://TERA_USERNAME-whatsapp-crm-engine.hf.space');
define('NODE_API_KEY', 'mera_strong_api_key_32chars_min'); // Same as HF secret

// ============================================================
// WEBHOOK SECURITY — Same as HF secret
// ============================================================
define('WEBHOOK_SECRET', 'mera_webhook_secret_32chars');

// ============================================================
// SOCKET.IO — Frontend connects to HF Space
// ============================================================
define('SOCKET_URL', 'https://TERA_USERNAME-whatsapp-crm-engine.hf.space');
```

### MATCHING TABLE:

| Hostinger (app.php) | HF Secret Name | MUST MATCH |
|---------------------|----------------|------------|
| `NODE_API_KEY` | `API_KEY` | ✅ |
| `WEBHOOK_SECRET` | `WEBHOOK_SECRET` | ✅ |
| `SOCKET_URL` | Space URL | ✅ |
| `NODE_API_URL` | Space URL | ✅ |

---

## ✅ STEP 8: FULL TEST KARO

### Test 1: Health Check
```
Browser: https://TERA_USERNAME-whatsapp-crm-engine.hf.space/health
Expected: {"whatsapp":"ready"}
```

### Test 2: Dashboard
```
Browser: https://TERI_DOMAIN.com/dashboard.php
Expected: Engine: Online, WhatsApp: Connected
```

### Test 3: CSV Upload
1. Dashboard → "Upload CSV" → apna CSV select karo
2. Leads import ho jaayengi

### Test 4: Manual Message
1. Lead select karo → message type karo → Send
2. WhatsApp pe message jaana chahiye

### Test 5: Campaign
1. "Start Campaign" click karo
2. Messages one-by-one jayenge (2-5 min gap)

---

## ⚠️ IMPORTANT: SPACE SLEEP PROBLEM & SOLUTION

### Problem:
HF Free tier mein agar 48 hours koi traffic na aaye toh space **sleep** ho jaata hai.

### Solution Built-in:
Maine server mein **self-ping** system daal diya hai — har 10 minute mein khud ko ping karta hai.

### Extra Safety (UptimeRobot - FREE):
1. Go to: https://uptimerobot.com (free sign up)
2. Add new monitor:
   - Type: HTTP(S)
   - URL: `https://TERA_USERNAME-whatsapp-crm-engine.hf.space/health`
   - Interval: 5 minutes
3. Done! Ab space kabhi sleep nahi hoga.

---

## ⚠️ IMPORTANT: QR CODE RE-SCAN KARNA PADEGA (Kabhi Kabhi)

### Kab:
- Space rebuild hone pe (file changes push karne pe)
- Rare: WhatsApp session expire hone pe

### Kaise:
1. `/qr` page open karo
2. Dubara scan karo
3. 5 second mein reconnect

### Tip:
Jab tak zaroorat na ho, files mat push karo. Ek baar set up ho gaya toh chalne do.

---

## 🧯 TROUBLESHOOTING

### "Space is building" bahut der se
- 5 min se zyada lage toh logs check karo
- Usually npm install mein time lagta hai pehli baar

### "WhatsApp: qr_pending" — QR nahi dikh raha
- `/qr` endpoint kholo browser mein
- 10-15 sec wait karo, auto-refresh hoga
- Agar still nahi toh space restart karo (Settings → Restart)

### "CORS error" dashboard pe
- HF Secrets mein `SOCKET_CORS_ORIGIN` check karo
- Teri exact domain honi chahiye (with https://)

### "Webhook failed" — messages DB mein nahi ja rahe
- Hostinger pe `webhook.php` accessible hai check karo
- `WEBHOOK_URL` secret sahi hai check karo
- Hostinger pe SSL certificate active hai? (HTTPS zaruri)

### Space har baar restart pe QR maangta hai
- Ye normal hai free tier pe (no persistent disk)
- Session `/app/wa_session/` mein save hota hai but restart pe delete
- Solution: UptimeRobot lagao taaki restart hi na ho

### "Memory limit exceeded"
- Free tier mein 16GB hai — usually problem nahi hoga
- Agar ho toh space restart karo

---

## 📊 COMPLETE FLOW DIAGRAM (HF Version)

```
┌──────────────────────────────────────────────┐
│  TU (Browser)                                │
│  https://teri-domain.com/dashboard.php       │
│                                              │
│  ┌─────────────────────────────────────┐    │
│  │  HOSTINGER                          │    │
│  │  PHP + MySQL + Dashboard            │    │
│  │                                     │    │
│  │  campaign.php ──cURL──────────────────────► HF Space API
│  │  webhook.php  ◄──POST────────────────────── HF Space
│  │  dashboard.js ──Socket.io────────────────── HF Space
│  └─────────────────────────────────────┘    │
└──────────────────────────────────────────────┘
                        │
                        ▼
┌──────────────────────────────────────────────┐
│  HUGGING FACE SPACE (FREE)                   │
│  https://username-whatsapp-crm-engine.hf.space│
│                                              │
│  Docker Container:                           │
│  Node.js + Express + Socket.io               │
│  + whatsapp-web.js + Puppeteer + Chromium   │
│                                              │
│  Endpoints:                                  │
│  /health → Status                            │
│  /qr → QR code page (browser)               │
│  /send-message → Send WhatsApp              │
│  /check-number → Validate number            │
│                                              │
│  Self-ping every 10 min (anti-sleep)        │
└──────────────────────────────────────────────┘
                        │
                        ▼
┌──────────────────────────────────────────────┐
│  LEAD KA WHATSAPP                            │
│  (Message receive karta hai)                 │
└──────────────────────────────────────────────┘
```

---

## ⏱️ TIME ESTIMATE

| Step | Time |
|------|------|
| HF account create | 2 min |
| Space create | 2 min |
| Secrets add | 5 min |
| Files upload | 5 min |
| Build wait | 3-5 min |
| QR scan | 1 min |
| Hostinger app.php update | 5 min |
| Testing | 5 min |
| **TOTAL** | **~30 min** |

---

## 🎉 DONE!

Ab tera poora system FREE mein chal raha hai:
- WhatsApp Engine → Hugging Face (FREE)
- Dashboard + PHP → Hostinger (already paid)
- AI Messages → Groq (FREE tier: 30 req/min)
- Monitoring → UptimeRobot (FREE)

**Total extra cost: ₹0** 🚀

---

## 📝 QUICK REFERENCE

| What | URL |
|------|-----|
| HF Space | `https://huggingface.co/spaces/USERNAME/whatsapp-crm-engine` |
| App URL | `https://USERNAME-whatsapp-crm-engine.hf.space` |
| Health | `https://USERNAME-whatsapp-crm-engine.hf.space/health` |
| QR Scan | `https://USERNAME-whatsapp-crm-engine.hf.space/qr` |
| Dashboard | `https://YOUR-DOMAIN.com/dashboard.php` |
| Logs | Space page → Logs button |
| Restart | Space page → Settings → Factory Reboot |
