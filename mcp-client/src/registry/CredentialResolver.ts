import { request } from 'undici';
import { createLogger } from '../logging/logger.js';

const logger = createLogger('CredentialResolver');

export interface CredentialResolverOptions {
    laravelBaseUrl: string;
    internalAuthToken: string;
    cacheTtlMs?: number;
    timeoutMs?: number;
}

interface CacheEntry {
    payload: Record<string, unknown>;
    expiresAt: number;
}

/**
 * Resolves per-tenant per-server credentials by calling back into the Laravel
 * host. The host stores credentials encrypted at rest (Crypt::encryptString)
 * and is the single source of truth — the sidecar never persists secrets.
 */
export class CredentialResolver {
    private readonly cache = new Map<string, CacheEntry>();
    private readonly cacheTtlMs: number;
    private readonly timeoutMs: number;

    constructor(private readonly options: CredentialResolverOptions) {
        this.cacheTtlMs = options.cacheTtlMs ?? 30_000;
        this.timeoutMs = options.timeoutMs ?? 5_000;
    }

    async resolve(tenantId: string, serverId: number): Promise<Record<string, unknown>> {
        const key = `${tenantId}::${serverId}`;
        const now = Date.now();
        const cached = this.cache.get(key);
        if (cached && cached.expiresAt > now) {
            return cached.payload;
        }

        const url = new URL('/api/mcp/credentials', this.options.laravelBaseUrl);
        url.searchParams.set('tenant_id', tenantId);
        url.searchParams.set('server_id', String(serverId));

        try {
            const response = await request(url.toString(), {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${this.options.internalAuthToken}`,
                },
                bodyTimeout: this.timeoutMs,
                headersTimeout: this.timeoutMs,
            });

            if (response.statusCode !== 200) {
                const body = await response.body.text();
                throw new Error(
                    `Credential resolve failed: HTTP ${response.statusCode} ${body.slice(0, 200)}`,
                );
            }

            const json = (await response.body.json()) as Record<string, unknown>;
            const payload = (json.auth_config as Record<string, unknown>) ?? {};
            this.cache.set(key, {
                payload,
                expiresAt: now + this.cacheTtlMs,
            });
            return payload;
        } catch (error) {
            logger.error('Failed to resolve credentials', {
                tenant_id: tenantId,
                server_id: serverId,
                error: error instanceof Error ? error.message : String(error),
            });
            throw error;
        }
    }

    invalidate(tenantId: string, serverId: number): void {
        this.cache.delete(`${tenantId}::${serverId}`);
    }

    clearCache(): void {
        this.cache.clear();
    }
}
