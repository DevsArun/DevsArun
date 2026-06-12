# WaLead — WhatsApp CRM + Cold Outreach Dashboard

Production-ready WhatsApp CRM with real-time chat, AI-powered personalization, and anti-ban protections.

## Architecture

```
┌─────────────────┐     ┌──────────────────────┐     ┌─────────────────┐
│  Frontend        │────▶│  PHP Backend          │────▶│  MySQL Database  │
│  (Tailwind CSS)  │     │  (Hostinger)          │     │  (Hostinger)     │
│  index.html      │     │  app.php              │     │  walead_crm      │
└────────┬─────────┘     └──────────┬───────────┘     └─────────────────┘
         │                          │
         │    Socket.io             │  HTTP POST
         ▼                          ▼
┌─────────────────────────────────────────────┐
│  Node.js Server (Hugging Face Spaces)        │
│  WhatsApp Web.js + LID-to-Phone Mapping      │
│  Docker Container, Port 7860                 │
│  itschol0408/whatsapp-crm-engine             │
└─────────────────────────────────────────────┘
```

## Tech Stack

| Component | Technology | Hosting |
|-----------|-----------|---------|
| Frontend | HTML + Tailwind CSS CDN + Vanilla JS | Hostinger |
| Backend API | PHP 8.x | Hostinger Shared |
| Database | MySQL | Hostinger |
| WhatsApp Engine | Node.js + whatsapp-web.js | HF Spaces (FREE) |
| Real-time | Socket.io | HF Spaces |
| AI Messages | Groq API (Llama 3.1 70B) | API |

## Features

- Premium 3-column dashboard (White + Green theme)
- Real-time chat (5-sec polling + Socket.io)
- LID-to-phone resolution for WhatsApp Business @lid format
- Campaign runner with 120-300s anti-ban delays
- Groq AI personalized messages per lead
- CSV import (Patna toy shops format)
- Filters: All, Replied, Sent, Pending, Has Website, No Website
- Stats: Sent Today, Replies, Reply Rate, Remaining
- IST timezone for all timestamps
- Settings panel for API keys, webhook URL, delays

## Critical: @lid Format

WhatsApp Business uses `@lid` format (e.g., `188209946435616@lid`) instead of `@c.us`. The Node server maintains an in-memory mapping:
1. On send: captures chat LID → maps to phone
2. On receive from @lid: resolves via mapping → getContact() → chat.id

## Setup

### 1. Database
- Create `walead_crm` in Hostinger MySQL
- Import `php/schema.sql`
- Update `php/config.php`

### 2. PHP (Hostinger)
- Upload `php/` contents to public_html
- Edit `config.php` credentials
- Test: `https://yourdomain.com/app.php?action=get_stats`

### 3. Node.js (HF Spaces)
- Create Space: `itschol0408/whatsapp-crm-engine` (Docker SDK)
- Upload `node-server/` contents
- Set env: `WEBHOOK_URL=https://yourdomain.com/app.php?action=webhook`
- Scan QR at Space URL/qr

### 4. Connect
- Settings → Node URL, Webhook URL, Groq Key
- Import CSV → Start Campaign

## CSV Format

```csv
Business Name,Address,Phone,Website,Rating,Reviews
Patna Toy World,Boring Road Patna,9876543210,https://example.com,4.2,45
```

## File Structure

```
walead/
├── README.md
├── node-server/
│   ├── Dockerfile
│   ├── .dockerignore
│   ├── package.json
│   └── server.js
└── php/
    ├── .htaccess
    ├── config.php
    ├── app.php
    ├── index.html
    └── schema.sql
```
