import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

import { createLogger } from '../logging/logger.js';
import type { HandshakeResult, InvokeToolResult, ToolDescriptor } from '../types/mcp.js';

import { McpClientBase, McpClientFactoryInput, TimeoutError } from './McpClientBase.js';

const logger = createLogger('StreamableHttpMcpClient');

export class StreamableHttpMcpClient extends McpClientBase {
    private client?: Client;
    private transport?: StreamableHTTPClientTransport;
    private connected = false;

    constructor(input: McpClientFactoryInput) {
        super(input);
    }

    async handshake(): Promise<HandshakeResult> {
        const start = Date.now();
        try {
            await this.connect();
            if (!this.client) {
                throw new Error('Streamable HTTP MCP client not connected');
            }
            const tools: ToolDescriptor[] = [];
            try {
                const response = await this.withDeadline(this.client.listTools());
                for (const tool of response.tools ?? []) {
                    tools.push({
                        name: tool.name,
                        description: tool.description,
                        inputSchema: tool.inputSchema as Record<string, unknown> | undefined,
                    });
                }
            } catch (error) {
                logger.warn('Streamable HTTP listTools failed during handshake', {
                    error: this.errorMessage(error),
                });
            }
            return {
                ok: true,
                duration_ms: Date.now() - start,
                protocol_version: this.client.getServerVersion()?.version,
                server_info: {
                    name: this.client.getServerVersion()?.name,
                    version: this.client.getServerVersion()?.version,
                },
                capabilities: (this.client.getServerCapabilities() ?? {}) as Record<string, unknown>,
                tools,
            };
        } catch (error) {
            return {
                ok: false,
                error: this.errorMessage(error),
                duration_ms: Date.now() - start,
            };
        }
    }

    async listTools(): Promise<ToolDescriptor[]> {
        await this.connect();
        if (!this.client) {
            return [];
        }
        const response = await this.withDeadline(this.client.listTools());
        return (response.tools ?? []).map((tool) => ({
            name: tool.name,
            description: tool.description,
            inputSchema: tool.inputSchema as Record<string, unknown> | undefined,
        }));
    }

    async invokeTool(name: string, input: Record<string, unknown>): Promise<InvokeToolResult> {
        const start = Date.now();
        try {
            await this.connect();
            if (!this.client) {
                throw new Error('Streamable HTTP MCP client not connected');
            }
            const response = await this.withDeadline(
                this.client.callTool({
                    name,
                    arguments: input,
                }),
            );
            return {
                ok: true,
                status: 'ok',
                result: response,
                duration_ms: Date.now() - start,
            };
        } catch (error) {
            const isTimeout = error instanceof TimeoutError;
            return {
                ok: false,
                status: isTimeout ? 'timeout' : 'error',
                error: this.errorMessage(error),
                duration_ms: Date.now() - start,
            };
        }
    }

    async close(): Promise<void> {
        if (!this.connected) {
            return;
        }
        try {
            await this.client?.close();
        } catch (error) {
            logger.warn('Streamable HTTP close failed', { error: this.errorMessage(error) });
        }
        this.connected = false;
        this.client = undefined;
        this.transport = undefined;
    }

    private async connect(): Promise<void> {
        if (this.connected && this.client) {
            return;
        }
        const url = new URL(this.endpoint);
        const transport = new StreamableHTTPClientTransport(url, {
            requestInit: {
                headers: this.buildHeaders(),
            },
        });
        const client = new Client(
            {
                name: `askmydocs-sidecar-${this.serverName}`,
                version: '1.0.0',
            },
            {
                capabilities: { tools: {}, resources: {} },
            },
        );
        await this.withDeadline(client.connect(transport));
        this.client = client;
        this.transport = transport;
        this.connected = true;
        logger.info('Streamable HTTP MCP server connected', {
            server: this.serverName,
            url: url.toString(),
        });
    }

    private buildHeaders(): Record<string, string> {
        const headers: Record<string, string> = {};
        const token = this.authConfig.bearer_token ?? this.authConfig.token;
        if (typeof token === 'string' && token.length > 0) {
            headers['Authorization'] = `Bearer ${token}`;
        }
        const extraHeaders = this.authConfig.headers;
        if (extraHeaders && typeof extraHeaders === 'object') {
            for (const [key, value] of Object.entries(extraHeaders as Record<string, unknown>)) {
                if (typeof value === 'string') {
                    headers[key] = value;
                }
            }
        }
        return headers;
    }

    private errorMessage(error: unknown): string {
        if (error instanceof Error) {
            return error.message;
        }
        return String(error);
    }
}
