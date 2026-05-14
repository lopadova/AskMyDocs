import { z } from 'zod';

export const TransportSchema = z.enum(['stdio', 'sse', 'http']);
export type Transport = z.infer<typeof TransportSchema>;

export const InvokeToolRequestSchema = z.object({
    tenant_id: z.string().min(1).max(100),
    server_id: z.number().int().positive(),
    server_name: z.string().min(1).max(200),
    transport: TransportSchema,
    endpoint: z.string().min(1),
    auth_config: z.record(z.unknown()).optional(),
    tool_name: z.string().min(1).max(200),
    tool_input: z.record(z.unknown()).default({}),
    timeout_ms: z.number().int().positive().max(120_000).optional(),
});
export type InvokeToolRequest = z.infer<typeof InvokeToolRequestSchema>;

export const HandshakeRequestSchema = z.object({
    tenant_id: z.string().min(1).max(100),
    server_id: z.number().int().positive(),
    server_name: z.string().min(1).max(200),
    transport: TransportSchema,
    endpoint: z.string().min(1),
    auth_config: z.record(z.unknown()).optional(),
    timeout_ms: z.number().int().positive().max(120_000).optional(),
});
export type HandshakeRequest = z.infer<typeof HandshakeRequestSchema>;

export interface InvokeToolResult {
    ok: boolean;
    result?: unknown;
    error?: string;
    duration_ms: number;
    status: 'ok' | 'error' | 'timeout' | 'denied';
}

export interface HandshakeResult {
    ok: boolean;
    protocol_version?: string;
    server_info?: {
        name?: string;
        version?: string;
    };
    capabilities?: Record<string, unknown>;
    tools?: Array<{
        name: string;
        description?: string;
        inputSchema?: Record<string, unknown>;
    }>;
    resources?: Array<{
        uri: string;
        name?: string;
        description?: string;
    }>;
    error?: string;
    duration_ms: number;
}

export interface ToolDescriptor {
    name: string;
    description?: string;
    inputSchema?: Record<string, unknown>;
}

export interface SidecarConfig {
    port: number;
    bindAddress: string;
    laravelBaseUrl: string;
    internalAuthToken: string;
    defaultTimeoutMs: number;
    maxConcurrentInvocations: number;
}
