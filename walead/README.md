# WaLead — WhatsApp CRM + Cold Outreach Dashboard v2.0

Premium Silicon Valley-grade WhatsApp CRM with real-time messaging, campaign automation, and Groq AI personalization.

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    WaLead CRM System                      │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ┌──────────────┐    ┌──────────────┐    ┌───────────┐ │
│  │   Frontend    │◄──►│  PHP Backend  │◄──►│   MySQL   │ │
│  │  (Tailwind)   │    │  (Hostinger)  │    │    DB     │ │
│  └──────────────┘    └──────┬───────┘    └───────────┘ │
│                              │                           │
│                              │ HTTP/Webhook              │
│                              ▼                           │
│                     ┌──────────────────┐                │
│                     │   Node.js Server  │                │
│                     │  (HuggingFace)    │                │
│                     │  WhatsApp Web.js  │                │
│                     │  LID Mapping      │                │
│                     │  Socket.io        │                │
│                     └──────────────────┘                │
│                              │                           │
│                              ▼                           │
│                     ┌──────────────────┐                │
│                     │    WhatsApp       │                │
│                     │    Business       │                │
│                     └──────────────────┘                │
└─────────────────────────────────────────────────────────┘
```

## Tech Stack

| Component | Technology | Hosting |
|-----------|-----------|---------|
| Frontend | HTML + Tailwind CSS CDN + Vanilla JS | Hostinger |
| Backend | PHP 8.x | Hostinger Shared |
| Database | MySQL | Hostinger |
| WhatsApp Engine | Node.js + whatsapp-web.js | HuggingFace Spaces (Docker) |
| Real-time | Socket.io | HuggingFace |
| AI | Groq API (Llama 3.1 70B) | Cloud |

## Critical: LID Format Support

WhatsApp Business accounts use `@lid` format internally instead of phone numbers:
- Outbound: `917004667347@c.us` → WhatsApp maps to `188209946435616@lid`
- The Node.js server maintains an **in-memory LID-to-phone mapping**
- On send: captures chat's LID and maps to phone
- On receive: resolves LID via mapping → getContact() → chat.id fallback

## Setup

### 1. Database (Hostinger)
```sql
-- Run walead/database/schema.sql in phpMyAdmin
```

### 2. PHP Backend (Hostinger)
1. Upload all files from `walead/php/` to your Hostinger hosting
2. Edit `config.php` with your DB credentials and API keys
3. Set webhook URL to `https://yourdomain.com/walead/webhook.php`

### 3. Node.js Server (HuggingFace Space)
1. Create a new Space: `itschol0408/whatsapp-crm-engine`
2. Select Docker SDK
3. Upload files from `walead/node-server/`
4. Set environment variables:
   - `WEBHOOK_URL=https://yourdomain.com/walead/webhook.php`
   - `DEBUG_MODE=true`
5. Build and run — visit `/qr` to scan QR code

### 4. Configuration
1. Open dashboard (`app.php`)
2. Go to Settings
3. Set webhook URL and verify Node connection

## Features

- **3-Column Dashboard**: Sidebar + Lead List + Chat
- **Real-time Chat**: 5-second polling + Socket.io events
- **Filters**: All, Replied, Sent, Pending, Has Website, No Website
- **Stats**: Sent Today, Replies, Reply Rate, Remaining
- **CSV Import**: Bulk import leads with duplicate detection
- **Campaign Runner**: Groq AI personalized messages
- **Anti-Ban**: 120-300 second random delays between sends
- **Get Details**: Full lead information panel
- **IST Timezone**: All timestamps in Indian Standard Time
- **No Signature Verification**: Disabled for HF Spaces reliability

## CSV Format

```
Business Name, Address, Phone, Website, Rating, Reviews, Status
```

Example: `Toy World Patna,Fraser Road Patna,917004667347,https://toyworld.in,4.2,156,pending`

## API Endpoints

### PHP (api.php)
- `GET ?action=get_leads&filter=all&page=1&search=`
- `GET ?action=get_lead&id=1`
- `GET ?action=get_messages&lead_id=1&since=`
- `POST ?action=send_message` — `{lead_id, message}`
- `GET ?action=get_stats`
- `POST ?action=start_campaign` — `{message_template, use_ai, filter, limit}`
- `GET ?action=get_node_status`

### Node.js
- `GET /` — Status page
- `GET /qr` — QR code page
- `GET /status` — JSON status
- `POST /send-message` — `{phone, message}`
- `POST /send-bulk` — `{messages: [{phone, message}]}`
- `GET /messages/:phone` — Chat history
- `GET /lid-mappings` — View LID map

## File Structure

```
walead/
├── README.md
├── node-server/
│   ├── Dockerfile
│   ├── package.json
│   ├── server.js
│   └── .dockerignore
├── php/
│   ├── app.php          (Main dashboard)
│   ├── api.php          (API endpoints)
│   ├── config.php       (Configuration)
│   ├── db.php           (Database connection)
│   ├── webhook.php      (Inbound webhook receiver)
│   ├── import.php       (CSV import handler)
│   ├── campaign.php     (Campaign runner)
│   └── .htaccess        (Security + CORS)
├── database/
│   └── schema.sql       (MySQL schema)
└── assets/
    └── sample_patna_toy_shops.csv
```
