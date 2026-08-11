# WhatsApp CRM + Cold Outreach Dashboard

A production-ready **Custom CRM + WhatsApp Cold Outreach System** for local business outreach.

## Architecture

```
┌─────────────────────────────────┐     ┌──────────────────────────────┐
│   HOSTINGER (Shared Hosting)    │     │   CLOUD VPS (Ubuntu/Node)    │
│                                 │     │                              │
│  • PHP Dashboard                │◄───►│  • Node.js + Express         │
│  • MySQL Database               │     │  • whatsapp-web.js           │
│  • API Endpoints                │     │  • Socket.io Server          │
│  • Webhook Receiver             │     │  • Puppeteer + LocalAuth     │
│  • Campaign Runner              │     │  • PM2 Managed               │
│  • Groq AI Integration          │     │                              │
└─────────────────────────────────┘     └──────────────────────────────┘
```

## Features

- CSV import with smart column mapping
- WhatsApp number validation (skip non-WA numbers)
- Groq AI personalized outreach messages
- Language adaptation (Hinglish for Bihar, regional for others)
- Anti-ban strategy (120-300s random delays)
- Real-time chat via Socket.io
- Manual reply from dashboard
- Auto-stop on lead reply
- Premium 3-column CRM dashboard (White + Green theme)

## Setup

### 1. Database (Hostinger MySQL)
```sql
-- Import schema
SOURCE sql/schema.sql;
-- Optional: seed data
SOURCE sql/seed.sql;
```

### 2. Hostinger (PHP)
1. Upload `hostinger/` contents to your web root
2. Edit `config/app.php` — set your Node VPS IP, API keys, Groq key
3. Edit `config/db.php` — set MySQL credentials
4. Create `uploads/` and `logs/` directories (chmod 755)

### 3. VPS (Node.js)
```bash
cd nodejs/
cp .env.example .env
# Edit .env with your settings
npm install
pm2 start ecosystem.config.js
```
Scan QR code on first run to link WhatsApp.

### 4. Run Campaign
```bash
# CLI
php scripts/campaign.php --limit=20

# Or via dashboard "Start Campaign" button
```

## CSV Format

```csv
"Business Name","Address","Phone","Website","Rating","Reviews","Status"
"Business ABC","Address here","+91 98765 43210","https://example.com","4.5","100","Open"
```

## Tech Stack

- **Frontend:** HTML + Tailwind CSS CDN + Vanilla JS + Socket.io client
- **Backend:** PHP 7.4+ / MySQL
- **Engine:** Node.js 18+ / whatsapp-web.js / Puppeteer
- **AI:** Groq API (Llama 3.1 70B)
- **Process:** PM2

## Security

- HMAC-SHA256 webhook verification
- API key authentication
- Rate limiting
- PDO prepared statements
- XSS protection
- CORS configuration

## Anti-Ban Strategy

- Only 1 outreach message per lead (never auto-reply)
- Random delay 120-300 seconds between sends
- Daily limit cap (default: 40/day)
- Natural, personalized messages (not templates)
- Stops completely when lead replies

---

Built by DevsArun
