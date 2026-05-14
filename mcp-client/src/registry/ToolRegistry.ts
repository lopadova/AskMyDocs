import { createMcpClient } from '../clients/factory.js';
import type { McpClient } from '../clients/McpClientBase.js';
import { createLogger } from '../logging/logger.js';
import type { HandshakeRequest, InvokeToolRequest } from '../types/mcp.js';

const logger = createLogger('ToolRegistry');

interface CacheEntry {
    client: McpClient;
    serverName: string;
    expiresAt: number;
}

/**
 * Per-tenant, per-server short-lived client cache. The cache lifespan is
 * intentionally short because long-running stdio child_processes are kept
 * alive by the underlying transport — the cache only avoids the spawn cost
 * across rapid-succession calls.
 */
export class ToolRegistry {
    private readonly clients = new Map<string, CacheEntry>();
    private readonly clientTtlMs: number;
    private readonly defaultTimeoutMs: number;

    constructor(options: { clientTtlMs?: number; defaultTimeoutMs?: number } = {}) {
        this.clientTtlMs = options.clientTtlMs ?? 60_000;
        this.defaultTimeoutMs = options.defaultTimeoutMs ?? 30_000;
    }

    cacheKey(tenantId: string, serverId: number): string {
        return `${tenantId}::${serverId}`;
    }

    async getClient(request: InvokeToolRequest | HandshakeRequest): Promise<McpClient> {
        const key = this.cacheKey(request.tenant_id, request.server_id);
        const now = Date.now();
        const cached = this.clients.get(key);
        if (cached && cached.expiresAt > now) {
            return cached.client;
        }
        if (cached) {
            await this.safeClose(cached);
        }

        const client = createMcpClient({
            transport: request.transport,
            endpoint: request.endpoint,
            serverName: request.server_name,
            timeoutMs: request.timeout_ms ?? this.defaultTimeoutMs,
            authConfig: request.auth_config,
        });

        this.clients.set(key, {
            client,
            serverName: request.server_name,
            expiresAt: now + this.clientTtlMs,
        });
        return client;
    }

    async invalidate(tenantId: string, serverId: number): Promise<void> {
        const key = this.cacheKey(tenantId, serverId);
        const entry = this.clients.get(key);
        if (entry) {
            await this.safeClose(entry);
            this.clients.delete(key);
        }
    }

    async closeAll(): Promise<void> {
        const entries = Array.from(this.clients.values());
        this.clients.clear();
        for (const entry of entries) {
            await this.safeClose(entry);
        }
    }

    private async safeClose(entry: CacheEntry): Promise<void> {
        try {
            await entry.client.close();
        } catch (error) {
            logger.warn('Failed to close MCP client', {
                server: entry.serverName,
                error: error instanceof Error ? error.message : String(error),
            });
        }
    }
}
