import { describe, expect, it, beforeAll, afterAll } from '@jest/globals';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import request from 'supertest';

import { createSidecarServer } from '../../src/server.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const fixturePath = path.resolve(__dirname, '..', 'fixtures', 'fake-mcp-server.ts');
const stdioEndpoint = `npx tsx ${fixturePath}`;

describe('end-to-end stdio MCP integration', () => {
    let server: ReturnType<typeof createSidecarServer>;

    beforeAll(() => {
        server = createSidecarServer({
            config: {
                port: 0,
                bindAddress: '127.0.0.1',
                laravelBaseUrl: '',
                internalAuthToken: 'int-test',
                defaultTimeoutMs: 20_000,
                maxConcurrentInvocations: 4,
            },
        });
    });

    afterAll(async () => {
        await server.toolRegistry.closeAll();
    });

    it('handshake discovers tools from a real stdio MCP server', async () => {
        const response = await request(server.app)
            .post('/handshake')
            .set('Authorization', 'Bearer int-test')
            .send({
                tenant_id: 'tenant-1',
                server_id: 1,
                server_name: 'fake',
                transport: 'stdio',
                endpoint: stdioEndpoint,
            });

        expect(response.status).toBe(200);
        expect(response.body.ok).toBe(true);
        const tools = response.body.tools ?? [];
        const toolNames = tools.map((tool: { name: string }) => tool.name);
        expect(toolNames).toEqual(expect.arrayContaining(['echo', 'add']));
    }, 30_000);

    it('invoke-tool round-trips through a real stdio MCP server', async () => {
        const response = await request(server.app)
            .post('/invoke-tool')
            .set('Authorization', 'Bearer int-test')
            .send({
                tenant_id: 'tenant-1',
                server_id: 1,
                server_name: 'fake',
                transport: 'stdio',
                endpoint: stdioEndpoint,
                tool_name: 'add',
                tool_input: { a: 2, b: 3 },
            });

        expect(response.status).toBe(200);
        expect(response.body.status).toBe('ok');
        const content = response.body.result?.content ?? [];
        expect(content[0]?.text).toBe('5');
    }, 30_000);
});
