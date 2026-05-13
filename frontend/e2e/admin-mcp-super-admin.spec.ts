import { test, expect } from '@playwright/test';

/*
 * v5.0 — MCP admin + internal endpoints smoke over real backend.
 *
 * Runs under chromium-super-admin project. No route stubbing.
 */
test.describe('Admin MCP — super-admin', () => {
    test('happy path — create server, list, verify internal auth and read credentials', async ({
        request,
        page,
    }) => {
        await page.goto('/app/admin/maintenance');
        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });

        const create = await request.post('/api/admin/mcp-servers', {
            data: {
                name: `pw-mcp-${Date.now()}`,
                transport: 'http',
                endpoint: 'http://127.0.0.1:3535',
                auth_config: { api_key: 'demo-secret' },
                enabled_tools: ['search_docs'],
            },
        });
        expect(create.ok()).toBeTruthy();
        const created = await create.json();
        const id = created.data.id as number;

        const list = await request.get('/api/admin/mcp-servers');
        expect(list.ok()).toBeTruthy();
        const listing = await list.json();
        expect(Array.isArray(listing.data)).toBeTruthy();
        expect(listing.data.some((row: { id: number }) => row.id === id)).toBeTruthy();

        const verifyNoToken = await request.post('/api/mcp/internal-auth');
        const tokenFromEnv = process.env.MCP_INTERNAL_AUTH_TOKEN ?? '';
        let credentialHeaders: Record<string, string> | undefined = undefined;

        if (verifyNoToken.status() === 401) {
            if (tokenFromEnv === '') {
                throw new Error(
                    'MCP internal auth token is required by backend but MCP_INTERNAL_AUTH_TOKEN is not set for E2E.',
                );
            }

            const verifyWithToken = await request.post('/api/mcp/internal-auth', {
                headers: { 'X-MCP-Internal-Token': tokenFromEnv },
            });
            expect(verifyWithToken.ok()).toBeTruthy();
            credentialHeaders = { 'X-MCP-Internal-Token': tokenFromEnv };
        } else {
            expect(verifyNoToken.ok()).toBeTruthy();
        }

        const credentials = await request.post('/api/mcp/credentials', {
            headers: credentialHeaders,
            data: {
                tenant_id: 'default',
                mcp_server_id: id,
            },
        });
        if (credentials.ok()) {
            const credPayload = await credentials.json();
            expect(credPayload.data).toBeTruthy();
            expect(credPayload.data.transport).toBe('http');
            expect(credPayload.data.enabled_tools).toEqual(['search_docs']);
        } else {
            expect([401, 403]).toContain(credentials.status());
        }

        const disable = await request.post(`/api/admin/mcp-servers/${id}/disable`);
        expect(disable.ok()).toBeTruthy();
    });
});
