# syntax=docker/dockerfile:1.7

# ----- Build stage --------------------------------------------------------
FROM node:22-alpine AS builder
WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY tsconfig.json tsconfig.test.json ./
COPY src ./src
RUN npm run build

# ----- Runtime stage ------------------------------------------------------
FROM node:22-alpine AS runtime
WORKDIR /app

# supervisor + tini for clean signal handling
RUN apk add --no-cache supervisor tini curl \
    && addgroup -S mcp \
    && adduser -S -G mcp -H -D mcp

COPY package.json package-lock.json* ./
RUN npm ci --omit=dev --no-audit --no-fund \
    && npm cache clean --force

COPY --from=builder /app/dist ./dist
COPY supervisord.conf /etc/supervisord.conf

ENV NODE_ENV=production
ENV MCP_SIDECAR_PORT=3535
ENV MCP_SIDECAR_BIND=0.0.0.0
ENV MCP_SIDECAR_LOG_LEVEL=info

EXPOSE 3535

USER mcp

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fs http://127.0.0.1:3535/healthz || exit 1

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
