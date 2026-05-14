import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

import { createLogger } from '../logging/logger.js';
import type { HandshakeResult, InvokeToolResult, ToolDescriptor } from '../types/mcp.js';

import { McpClientBase, McpClientFactoryInput, TimeoutError } from './McpClientBase.js';

const logger = createLogger('StdioMcpClient');

interface StdioParsedEndpoint {
    command: string;
    args: string[];
    env: Record<string, string>;
}

export class StdioMcpClient extends McpClientBase {
    private client?: Client;
    private transport?: StdioClientTransport;
    private connected = false;

    constructor(input: McpClientFactoryInput) {
        super(input);
    }

    async handshake(): Promise<HandshakeResult> {
        const start = Date.now();
        try {
            await this.connect();
            const result = await this.discoverCapabilities();
            return {
                ...result,
                ok: true,
                duration_ms: Date.now() - start,
            };
        } catch (error) {
            return this.handshakeError(error, start);
        }
    }

    async listTools(): Promise<ToolDescriptor[]> {
        await this.connect();
        if (!this.client) {
            return [];
        }
        const response = await this.withDeadline(this.client.listTools());
        const tools: ToolDescriptor[] = [];
        for (const tool of response.tools ?? []) {
            tools.push({
                name: tool.name,
                description: tool.description,
                inputSchema: tool.inputSchema as Record<string, unknown> | undefined,
            });
        }
        return tools;
    }

    async invokeTool(name: string, input: Record<string, unknown>): Promise<InvokeToolResult> {
        const start = Date.now();
        try {
            await this.connect();
            if (!this.client) {
                throw new Error('Stdio MCP client not connected');
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
            return this.invokeError(error, start);
        }
    }

    async close(): Promise<void> {
        if (!this.connected) {
            return;
        }
        try {
            await this.client?.close();
        } catch (error) {
            logger.warn('Error closing stdio client', { error: this.errorMessage(error) });
        }
        this.connected = false;
        this.client = undefined;
        this.transport = undefined;
    }

    private async connect(): Promise<void> {
        if (this.connected && this.client) {
            return;
        }
        const parsed = this.parseEndpoint();
        const transport = new StdioClientTransport({
            command: parsed.command,
            args: parsed.args,
            env: this.buildEnv(parsed.env),
        });
        const client = new Client(
            {
                name: `askmydocs-sidecar-${this.serverName}`,
                version: '1.0.0',
            },
            {
                capabilities: {
                    tools: {},
                    resources: {},
                },
            },
        );
        await this.withDeadline(client.connect(transport));
        this.client = client;
        this.transport = transport;
        this.connected = true;
        logger.info('Stdio MCP server connected', {
            server: this.serverName,
            command: parsed.command,
        });
    }

    private parseEndpoint(): StdioParsedEndpoint {
        // Endpoint shape: "command arg1 arg2 ..." OR JSON {command, args, env}
        const trimmed = this.endpoint.trim();
        if (trimmed.startsWith('{')) {
            const parsed = JSON.parse(trimmed) as {
                command?: string;
                args?: string[];
                env?: Record<string, string>;
            };
            if (!parsed.command) {
                throw new Error('Stdio endpoint JSON must include "command"');
            }
            return {
                command: parsed.command,
                args: parsed.args ?? [],
                env: parsed.env ?? {},
            };
        }
        const tokens = trimmed.split(/\s+/);
        if (tokens.length === 0 || !tokens[0]) {
            throw new Error('Stdio endpoint is empty');
        }
        return {
            command: tokens[0],
            args: tokens.slice(1),
            env: {},
        };
    }

    private buildEnv(extra: Record<string, string>): Record<string, string> {
        const env: Record<string, string> = {};
        for (const [key, value] of Object.entries(process.env)) {
            if (typeof value === 'string') {
                env[key] = value;
            }
        }
        for (const [key, value] of Object.entries(extra)) {
            env[key] = value;
        }
        if (this.authConfig.env && typeof this.authConfig.env === 'object') {
            for (const [key, value] of Object.entries(this.authConfig.env as Record<string, string>)) {
                env[key] = value;
            }
        }
        return env;
    }

    private async discoverCapabilities(): Promise<Omit<HandshakeResult, 'ok' | 'duration_ms'>> {
        if (!this.client) {
            throw new Error('Stdio MCP client not connected');
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
            logger.warn('listTools failed during handshake', { error: this.errorMessage(error) });
        }

        const resources: HandshakeResult['resources'] = [];
        try {
            const response = await this.withDeadline(this.client.listResources());
            for (const resource of response.resources ?? []) {
                resources.push({
                    uri: resource.uri,
                    name: resource.name,
                    description: resource.description,
                });
            }
        } catch (error) {
            logger.debug('listResources failed during handshake (non-fatal)', {
                error: this.errorMessage(error),
            });
        }

        return {
            protocol_version: this.client.getServerVersion()?.version,
            server_info: {
                name: this.client.getServerVersion()?.name,
                version: this.client.getServerVersion()?.version,
            },
            capabilities: (this.client.getServerCapabilities() ?? {}) as Record<string, unknown>,
            tools,
            resources,
        };
    }

    private handshakeError(error: unknown, start: number): HandshakeResult {
        return {
            ok: false,
            error: this.errorMessage(error),
            duration_ms: Date.now() - start,
        };
    }

    private invokeError(error: unknown, start: number): InvokeToolResult {
        const isTimeout = error instanceof TimeoutError;
        return {
            ok: false,
            status: isTimeout ? 'timeout' : 'error',
            error: this.errorMessage(error),
            duration_ms: Date.now() - start,
        };
    }

    private errorMessage(error: unknown): string {
        if (error instanceof Error) {
            return error.message;
        }
        return String(error);
    }
}
