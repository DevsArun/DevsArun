---
title: WhatsApp CRM Engine
emoji: 💬
colorFrom: green
colorTo: emerald
sdk: docker
pinned: false
---

# WhatsApp CRM Engine

WhatsApp messaging engine for CRM cold outreach system.

## Features
- WhatsApp Web connection via whatsapp-web.js
- REST API for sending messages
- Number validation (check WhatsApp registration)
- Real-time Socket.io events
- Self-ping to prevent sleep
- QR code web interface for easy linking

## Endpoints
- `GET /` - Status page
- `GET /health` - Health check
- `GET /qr` - QR code for WhatsApp linking
- `POST /send-message` - Send message (requires API key)
- `POST /check-number` - Validate number (requires API key)

## Setup
Configure secrets in Space settings:
- `API_KEY` - Your API key
- `WEBHOOK_URL` - PHP webhook URL
- `WEBHOOK_SECRET` - HMAC secret
- `SOCKET_CORS_ORIGIN` - Your domain
