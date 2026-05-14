import express, { Request, Response, NextFunction } from 'express';
import { ZodError } from 'zod';

import { internalAuthMiddleware } from './auth.js';
import { createLogger } from './logging/logger.js';
import { CredentialResolver } from './registry/CredentialResolver.js';
import { ToolRegistry } from './registry/ToolRegistry.js';
import {
    HandshakeRequestSchema,
    InvokeToolRequestSchema,
    type SidecarConfig,
} from './types/mcp.js';

const logger = createLogger('server');

export interface SidecarServerOptions {
    config: SidecarConfig;
    toolRegistry?: ToolRegistry;
    credentialResolver?: CredentialResolver;
}

export function createSidecarServer(options: SidecarServerOptions) {
    const { config } = options;
    const app = express();
    app.disable('x-powered-by');
    app.use(express.json({ limit: '4mb' }));

    const toolRegistry =
        options.toolRegistry ??
        new ToolRegistry({ defaultTimeoutMs: config.defaultTimeoutMs });

    const credentialResolver =
        options.credentialResolver ??
        (config.laravelBaseUrl && config.internalAuthToken
            ? new CredentialResolver({
                  laravelBaseUrl: config.laravelBaseUrl,
                  internalAuthToken: config.internalAuthToken,
              })
            : undefined);

    app.get('/healthz', (_req, res) => {
        res.json({
            status: 'ok',
            version: '1.0.0',
            uptime_s: Math.floor(process.uptime()),
        });
    });

    app.use(internalAuthMiddleware(config.internalAuthToken));

    app.post('/handshake', async (req, res, next) => {
        try {
            const parsed = HandshakeRequestSchema.parse(req.body);
            const augmented = await maybeResolveCredentials(parsed, credentialResolver);
            const client = await toolRegistry.getClient(augmented);
            const result = await client.handshake();
            res.json(result);
        } catch (error) {
            next(error);
        }
    });

    app.post('/invoke-tool', async (req, res, next) => {
        try {
            const parsed = InvokeToolRequestSchema.parse(req.body);
            const augmented = await maybeResolveCredentials(parsed, credentialResolver);
            const client = await toolRegistry.getClient(augmented);
            const result = await client.invokeTool(augmented.tool_name, augmented.tool_input);
            res.json(result);
        } catch (error) {
            next(error);
        }
    });

    app.post('/invalidate', async (req, res, next) => {
        try {
            const tenantId = String(req.body?.tenant_id ?? '');
            const serverId = Number(req.body?.server_id ?? 0);
            if (!tenantId || !Number.isFinite(serverId) || serverId <= 0) {
                res.status(400).json({ error: 'tenant_id and server_id required' });
                return;
            }
            await toolRegistry.invalidate(tenantId, serverId);
            credentialResolver?.invalidate(tenantId, serverId);
            res.json({ ok: true });
        } catch (error) {
            next(error);
        }
    });

    app.use((error: unknown, _req: Request, res: Response, _next: NextFunction) => {
        if (error instanceof ZodError) {
            res.status(400).json({
                error: 'Validation failed',
                issues: error.issues,
            });
            return;
        }
        const message = error instanceof Error ? error.message : String(error);
        logger.error('Unhandled sidecar error', { error: message });
        res.status(500).json({ error: message });
    });

    return { app, toolRegistry, credentialResolver };
}

async function maybeResolveCredentials<T extends { tenant_id: string; server_id: number; auth_config?: Record<string, unknown> }>(
    parsed: T,
    resolver: CredentialResolver | undefined,
): Promise<T> {
    if (parsed.auth_config && Object.keys(parsed.auth_config).length > 0) {
        return parsed;
    }
    if (!resolver) {
        return parsed;
    }
    try {
        const resolved = await resolver.resolve(parsed.tenant_id, parsed.server_id);
        return { ...parsed, auth_config: resolved };
    } catch (error) {
        logger.warn('Falling back to inline auth_config (resolver failed)', {
            tenant_id: parsed.tenant_id,
            server_id: parsed.server_id,
            error: error instanceof Error ? error.message : String(error),
        });
        return parsed;
    }
}

export function loadConfig(): SidecarConfig {
    const port = Number(process.env.MCP_SIDECAR_PORT ?? '3535');
    const bindAddress = process.env.MCP_SIDECAR_BIND ?? '127.0.0.1';
    const laravelBaseUrl = process.env.MCP_SIDECAR_LARAVEL_URL ?? '';
    const internalAuthToken = process.env.MCP_SIDECAR_INTERNAL_TOKEN ?? '';
    const defaultTimeoutMs = Number(process.env.MCP_SIDECAR_DEFAULT_TIMEOUT_MS ?? '30000');
    const maxConcurrentInvocations = Number(
        process.env.MCP_SIDECAR_MAX_CONCURRENT ?? '32',
    );

    if (!Number.isFinite(port) || port <= 0) {
        throw new Error('MCP_SIDECAR_PORT must be a positive integer');
    }

    return {
        port,
        bindAddress,
        laravelBaseUrl,
        internalAuthToken,
        defaultTimeoutMs,
        maxConcurrentInvocations,
    };
}

async function main(): Promise<void> {
    const config = loadConfig();
    if (!config.internalAuthToken) {
        logger.warn('MCP_SIDECAR_INTERNAL_TOKEN is empty — all authenticated requests will be rejected');
    }
    const { app, toolRegistry } = createSidecarServer({ config });
    const server = app.listen(config.port, config.bindAddress, () => {
        logger.info('MCP sidecar listening', {
            port: config.port,
            bind: config.bindAddress,
        });
    });

    const shutdown = async (signal: string) => {
        logger.info(`Received ${signal} — shutting down`);
        server.close(() => {
            void toolRegistry.closeAll().then(() => process.exit(0));
        });
        setTimeout(() => process.exit(1), 5_000).unref();
    };

    process.on('SIGTERM', () => void shutdown('SIGTERM'));
    process.on('SIGINT', () => void shutdown('SIGINT'));
}

if (import.meta.url === `file://${process.argv[1]}`) {
    void main().catch((error) => {
        logger.error('Fatal sidecar startup error', {
            error: error instanceof Error ? error.message : String(error),
        });
        process.exit(1);
    });
}
