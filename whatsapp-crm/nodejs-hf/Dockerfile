# ============================================================
# WhatsApp CRM Engine - Hugging Face Docker Space
# Free Tier: 2 vCPU, 16GB RAM, 50GB Storage
# ============================================================

FROM node:18-slim

# Install Chromium and dependencies for Puppeteer
RUN apt-get update && apt-get install -y \
    chromium \
    fonts-liberation \
    libappindicator3-1 \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libgbm1 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libx11-xcb1 \
    libxcomposite1 \
    libxdamage1 \
    libxrandr2 \
    xdg-utils \
    wget \
    curl \
    ca-certificates \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# Set Puppeteer to use installed Chromium
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
ENV CHROMIUM_PATH=/usr/bin/chromium

# Create app directory
WORKDIR /app

# Create data directory for WhatsApp session persistence
RUN mkdir -p /app/wa_session /app/logs

# Copy package files
COPY package.json ./

# Install dependencies
RUN npm install --production

# Copy app source
COPY . .

# Hugging Face Spaces MUST use port 7860
ENV PORT=7860
ENV NODE_ENV=production

EXPOSE 7860

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s \
    CMD curl -f http://localhost:7860/health || exit 1

# Start the server
CMD ["node", "server.js"]
