#!/usr/bin/env node
/**
 * Minimal stdio MCP server for integration tests. Responds to MCP JSON-RPC
 * over stdio with a fixed tool surface: echo + add.
 *
 * Protocol (subset implemented):
 *   - initialize → { protocolVersion, capabilities, serverInfo }
 *   - tools/list → { tools: [...] }
 *   - tools/call { name, arguments } → { content: [...] }
 */
import readline from 'node:readline';

const STDIN_LINE = readline.createInterface({ input: process.stdin });

function send(message: object): void {
    process.stdout.write(JSON.stringify(message) + '\n');
}

interface JsonRpcRequest {
    jsonrpc: '2.0';
    id?: string | number;
    method: string;
    params?: Record<string, unknown>;
}

function handle(request: JsonRpcRequest): void {
    switch (request.method) {
        case 'initialize':
            send({
                jsonrpc: '2.0',
                id: request.id,
                result: {
                    protocolVersion: '2024-11-05',
                    capabilities: { tools: {}, resources: {} },
                    serverInfo: { name: 'fake-mcp-server', version: '1.0.0' },
                },
            });
            return;
        case 'notifications/initialized':
            // No response needed for notifications
            return;
        case 'tools/list':
            send({
                jsonrpc: '2.0',
                id: request.id,
                result: {
                    tools: [
                        {
                            name: 'echo',
                            description: 'Returns the input verbatim',
                            inputSchema: {
                                type: 'object',
                                properties: {
                                    message: { type: 'string' },
                                },
                                required: ['message'],
                            },
                        },
                        {
                            name: 'add',
                            description: 'Adds two numbers',
                            inputSchema: {
                                type: 'object',
                                properties: {
                                    a: { type: 'number' },
                                    b: { type: 'number' },
                                },
                                required: ['a', 'b'],
                            },
                        },
                    ],
                },
            });
            return;
        case 'tools/call': {
            const params = request.params ?? {};
            const name = String(params.name ?? '');
            const args = (params.arguments ?? {}) as Record<string, unknown>;
            if (name === 'echo') {
                send({
                    jsonrpc: '2.0',
                    id: request.id,
                    result: {
                        content: [{ type: 'text', text: String(args.message ?? '') }],
                    },
                });
                return;
            }
            if (name === 'add') {
                const a = Number(args.a ?? 0);
                const b = Number(args.b ?? 0);
                send({
                    jsonrpc: '2.0',
                    id: request.id,
                    result: {
                        content: [{ type: 'text', text: String(a + b) }],
                    },
                });
                return;
            }
            send({
                jsonrpc: '2.0',
                id: request.id,
                error: { code: -32601, message: `Unknown tool: ${name}` },
            });
            return;
        }
        case 'resources/list':
            send({
                jsonrpc: '2.0',
                id: request.id,
                result: { resources: [] },
            });
            return;
        default:
            send({
                jsonrpc: '2.0',
                id: request.id,
                error: { code: -32601, message: `Method not found: ${request.method}` },
            });
    }
}

STDIN_LINE.on('line', (line) => {
    const trimmed = line.trim();
    if (!trimmed) {
        return;
    }
    try {
        const request = JSON.parse(trimmed) as JsonRpcRequest;
        handle(request);
    } catch (error) {
        process.stderr.write(
            `Failed to parse request: ${error instanceof Error ? error.message : String(error)}\n`,
        );
    }
});
