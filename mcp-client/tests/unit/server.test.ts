import { describe, expect, it, jest } from '@jest/globals';
import request from 'supertest';

import { createSidecarServer } from '../../src/server.js';
import type { SidecarConfig } from '../../src/types/mcp.js';

const baseConfig: SidecarConfig = {
    port: 3535,
    bindAddress: '127.0.0.1',
    laravelBaseUrl: '',
    internalAuthToken: 'test-token-xyz',
    defaultTimeoutMs: 5_000,
    maxConcurrentInvocations: 8,
};

function buildServer() {
    const fakeClient = {
        handshake: jest.fn(() =>
            Promise.resolve({
                ok: true,
                duration_ms: 1,
                tools: [{ name: 'echo' }],
            }),
        ),
        listTools: jest.fn(() => Promise.resolve([])),
        invokeTool: jest.fn((name: string, input: Record<string, unknown>) =>
            Promise.resolve({
                ok: true,
                status: 'ok' as const,
                result: { tool: name, input },
                duration_ms: 1,
            }),
        ),
        close: jest.fn(() => Promise.resolve()),
    };

    const toolRegistry = {
        cacheKey: (a: string, b: number) => `${a}::${b}`,
        getClient: jest.fn(() => Promise.resolve(fakeClient)),
        invalidate: jest.fn(() => Promise.resolve()),
        closeAll: jest.fn(() => Promise.resolve()),
    } as unknown as Parameters<typeof createSidecarServer>[0]['toolRegistry'];

    return { server: createSidecarServer({ config: baseConfig, toolRegistry }), fakeClient };
}

describe('sidecar HTTP server', () => {
    it('GET /healthz responds 200 without auth', async () => {
        const { server } = buildServer();
        const response = await request(server.app).get('/healthz');
        expect(response.status).toBe(200);
        expect(response.body.status).toBe('ok');
    });

    it('POST /handshake requires auth', async () => {
        const { server } = buildServer();
        const response = await request(server.app)
            .post('/handshake')
            .send({});
        expect(response.status).toBe(401);
    });

    it('POST /handshake validates payload', async () => {
        const { server } = buildServer();
        const response = await request(server.app)
            .post('/handshake')
            .set('Authorization', 'Bearer test-token-xyz')
            .send({ tenant_id: 'tenant-1' });
        expect(response.status).toBe(400);
        expect(response.body.error).toBe('Validation failed');
    });

    it('POST /handshake returns the handshake result', async () => {
        const { server, fakeClient } = buildServer();
        const response = await request(server.app)
            .post('/handshake')
            .set('Authorization', 'Bearer test-token-xyz')
            .send({
                tenant_id: 'tenant-1',
                server_id: 7,
                server_name: 'gh',
                transport: 'stdio',
                endpoint: 'cmd',
            });
        expect(response.status).toBe(200);
        expect(response.body.ok).toBe(true);
        expect(fakeClient.handshake).toHaveBeenCalled();
    });

    it('POST /invoke-tool returns tool result', async () => {
        const { server, fakeClient } = buildServer();
        const response = await request(server.app)
            .post('/invoke-tool')
            .set('Authorization', 'Bearer test-token-xyz')
            .send({
                tenant_id: 'tenant-1',
                server_id: 7,
                server_name: 'gh',
                transport: 'stdio',
                endpoint: 'cmd',
                tool_name: 'echo',
                tool_input: { message: 'hi' },
            });
        expect(response.status).toBe(200);
        expect(response.body.status).toBe('ok');
        expect(response.body.result).toEqual({ tool: 'echo', input: { message: 'hi' } });
        expect(fakeClient.invokeTool).toHaveBeenCalledWith('echo', { message: 'hi' });
    });

    it('POST /invalidate validates required fields', async () => {
        const { server } = buildServer();
        const response = await request(server.app)
            .post('/invalidate')
            .set('Authorization', 'Bearer test-token-xyz')
            .send({});
        expect(response.status).toBe(400);
    });
});
