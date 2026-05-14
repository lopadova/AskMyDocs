import { describe, expect, it } from '@jest/globals';

import {
    HandshakeRequestSchema,
    InvokeToolRequestSchema,
    TransportSchema,
} from '../../src/types/mcp.js';

describe('TransportSchema', () => {
    it.each(['stdio', 'sse', 'http'])('accepts %s', (transport) => {
        expect(() => TransportSchema.parse(transport)).not.toThrow();
    });

    it('rejects unknown transports', () => {
        expect(() => TransportSchema.parse('websocket')).toThrow();
    });
});

describe('HandshakeRequestSchema', () => {
    it('parses a complete handshake request', () => {
        const parsed = HandshakeRequestSchema.parse({
            tenant_id: 'tenant-1',
            server_id: 42,
            server_name: 'github',
            transport: 'stdio',
            endpoint: 'npx -y @modelcontextprotocol/server-github',
            auth_config: { token: 'ghp_xxx' },
        });
        expect(parsed.server_id).toBe(42);
        expect(parsed.transport).toBe('stdio');
    });

    it('rejects missing required fields', () => {
        expect(() =>
            HandshakeRequestSchema.parse({
                tenant_id: 'tenant-1',
                server_id: 1,
                transport: 'sse',
            }),
        ).toThrow();
    });

    it('rejects non-positive server_id', () => {
        expect(() =>
            HandshakeRequestSchema.parse({
                tenant_id: 'tenant-1',
                server_id: 0,
                server_name: 'x',
                transport: 'http',
                endpoint: 'https://example.com',
            }),
        ).toThrow();
    });

    it('clamps timeout to allowed range', () => {
        expect(() =>
            HandshakeRequestSchema.parse({
                tenant_id: 'tenant-1',
                server_id: 1,
                server_name: 'x',
                transport: 'http',
                endpoint: 'https://example.com',
                timeout_ms: 9999999,
            }),
        ).toThrow();
    });
});

describe('InvokeToolRequestSchema', () => {
    it('defaults tool_input to empty object', () => {
        const parsed = InvokeToolRequestSchema.parse({
            tenant_id: 'tenant-1',
            server_id: 1,
            server_name: 'github',
            transport: 'stdio',
            endpoint: 'npx -y mcp-github',
            tool_name: 'list_repositories',
        });
        expect(parsed.tool_input).toEqual({});
    });

    it('rejects empty tool_name', () => {
        expect(() =>
            InvokeToolRequestSchema.parse({
                tenant_id: 'tenant-1',
                server_id: 1,
                server_name: 'github',
                transport: 'stdio',
                endpoint: 'cmd',
                tool_name: '',
            }),
        ).toThrow();
    });
});
