import type { HandshakeResult, InvokeToolResult, ToolDescriptor } from '../types/mcp.js';

export interface McpClient {
    handshake(): Promise<HandshakeResult>;
    listTools(): Promise<ToolDescriptor[]>;
    invokeTool(name: string, input: Record<string, unknown>): Promise<InvokeToolResult>;
    close(): Promise<void>;
}

export interface McpClientFactoryInput {
    endpoint: string;
    authConfig?: Record<string, unknown>;
    timeoutMs: number;
    serverName: string;
}

export abstract class McpClientBase implements McpClient {
    protected readonly endpoint: string;
    protected readonly authConfig: Record<string, unknown>;
    protected readonly timeoutMs: number;
    protected readonly serverName: string;

    constructor(input: McpClientFactoryInput) {
        this.endpoint = input.endpoint;
        this.authConfig = input.authConfig ?? {};
        this.timeoutMs = input.timeoutMs;
        this.serverName = input.serverName;
    }

    abstract handshake(): Promise<HandshakeResult>;
    abstract listTools(): Promise<ToolDescriptor[]>;
    abstract invokeTool(name: string, input: Record<string, unknown>): Promise<InvokeToolResult>;
    abstract close(): Promise<void>;

    protected async withDeadline<T>(promise: Promise<T>): Promise<T> {
        let timer: NodeJS.Timeout | undefined;
        const timeoutPromise = new Promise<never>((_, reject) => {
            timer = setTimeout(() => {
                reject(new TimeoutError(`Operation timed out after ${this.timeoutMs}ms`));
            }, this.timeoutMs);
        });

        try {
            const result = await Promise.race([promise, timeoutPromise]);
            return result;
        } finally {
            if (timer) {
                clearTimeout(timer);
            }
        }
    }
}

export class TimeoutError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'TimeoutError';
    }
}
