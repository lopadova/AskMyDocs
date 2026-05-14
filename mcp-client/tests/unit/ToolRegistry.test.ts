import { afterEach, beforeEach, describe, expect, it, jest } from '@jest/globals';

import { ToolRegistry } from '../../src/registry/ToolRegistry.js';

// Mock the client factory so tests don't spin up real subprocess transports.
const closeMocks = new Map<string, jest.Mock>();

jest.unstable_mockModule('../../src/clients/factory.js', () => ({
    createMcpClient: jest.fn((input: { serverName: string }) => {
        const close = jest.fn(() => Promise.resolve());
        closeMocks.set(input.serverName, close);
        return {
            handshake: jest.fn(() => Promise.resolve({ ok: true, duration_ms: 0 })),
            listTools: jest.fn(() => Promise.resolve([])),
            invokeTool: jest.fn(() =>
                Promise.resolve({ ok: true, status: 'ok' as const, duration_ms: 0 }),
            ),
            close,
        };
    }),
}));

describe('ToolRegistry', () => {
    let registry: ToolRegistry;

    beforeEach(() => {
        closeMocks.clear();
        registry = new ToolRegistry({ clientTtlMs: 100, defaultTimeoutMs: 1000 });
    });

    afterEach(async () => {
        await registry.closeAll();
    });

    it('caches the client for a tenant/server combo', async () => {
        const request = {
            tenant_id: 'tenant-1',
            server_id: 42,
            server_name: 'github',
            transport: 'stdio' as const,
            endpoint: 'cmd',
            tool_name: 'echo',
            tool_input: {},
        };
        const a = await registry.getClient(request);
        const b = await registry.getClient(request);
        expect(a).toBe(b);
    });

    it('returns separate clients for different tenants', async () => {
        const baseRequest = {
            server_id: 42,
            server_name: 'github',
            transport: 'stdio' as const,
            endpoint: 'cmd',
            tool_name: 'echo',
            tool_input: {},
        };
        const tenantA = await registry.getClient({ ...baseRequest, tenant_id: 'tenant-a' });
        const tenantB = await registry.getClient({ ...baseRequest, tenant_id: 'tenant-b' });
        expect(tenantA).not.toBe(tenantB);
    });

    it('invalidate() closes the cached client', async () => {
        const request = {
            tenant_id: 'tenant-1',
            server_id: 1,
            server_name: 'one',
            transport: 'stdio' as const,
            endpoint: 'cmd',
            tool_name: 'echo',
            tool_input: {},
        };
        await registry.getClient(request);
        await registry.invalidate('tenant-1', 1);
        const close = closeMocks.get('one');
        expect(close).toHaveBeenCalled();
    });

    it('cacheKey is stable for the same tenant/server', () => {
        expect(registry.cacheKey('tenant-1', 42)).toBe(registry.cacheKey('tenant-1', 42));
        expect(registry.cacheKey('tenant-1', 42)).not.toBe(registry.cacheKey('tenant-2', 42));
    });
});
