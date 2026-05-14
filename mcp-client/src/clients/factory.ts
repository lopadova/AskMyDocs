import type { McpClient } from './McpClientBase.js';
import { SseMcpClient } from './SseMcpClient.js';
import { StdioMcpClient } from './StdioMcpClient.js';
import { StreamableHttpMcpClient } from './StreamableHttpMcpClient.js';
import type { Transport } from '../types/mcp.js';

export interface CreateClientInput {
    transport: Transport;
    endpoint: string;
    serverName: string;
    timeoutMs: number;
    authConfig?: Record<string, unknown>;
}

export function createMcpClient(input: CreateClientInput): McpClient {
    switch (input.transport) {
        case 'stdio':
            return new StdioMcpClient({
                endpoint: input.endpoint,
                serverName: input.serverName,
                timeoutMs: input.timeoutMs,
                authConfig: input.authConfig,
            });
        case 'sse':
            return new SseMcpClient({
                endpoint: input.endpoint,
                serverName: input.serverName,
                timeoutMs: input.timeoutMs,
                authConfig: input.authConfig,
            });
        case 'http':
            return new StreamableHttpMcpClient({
                endpoint: input.endpoint,
                serverName: input.serverName,
                timeoutMs: input.timeoutMs,
                authConfig: input.authConfig,
            });
        default: {
            const exhaustiveCheck: never = input.transport;
            throw new Error(`Unknown MCP transport: ${String(exhaustiveCheck)}`);
        }
    }
}
