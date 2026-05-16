import { test, expect } from '@playwright/test';

/*
 * v5.0 → v7.0/W6.3.C — MCP admin smoke over real backend.
 *
 * Runs under chromium-super-admin project. No route stubbing.
 *
 * v7.0/W6.3.B removed `/api/mcp/credentials` (decrypted-secret
 * callback that fed the Node sidecar). v7.0/W6.3.C drops the
 * surviving `/api/mcp/internal-auth` probe and the
 * `MCP_INTERNAL_AUTH_TOKEN` env var. The spec asserts both routes
 * are now 404 — regressions that bring either back without
 * explicit security review will fail this spec.
 */
test.describe('Admin MCP — super-admin', () => {
    test('create server, list, assert sidecar callbacks are removed, disable', async ({
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

        // v7.0/W6.3.C — both sidecar callbacks (`/internal-auth` and
        // `/credentials`) are now gone. Native MCP transports don't
        // need any host-side internal callbacks. Assert both 404 so
        // a regression bringing either back fails CI loudly.
        const verifyProbe = await request.post('/api/mcp/internal-auth');
        expect(verifyProbe.status()).toBe(404);

        const credentials = await request.post('/api/mcp/credentials', {
            data: { tenant_id: 'default', mcp_server_id: id },
        });
        expect(credentials.status()).toBe(404);

        const disable = await request.post(`/api/admin/mcp-servers/${id}/disable`);
        expect(disable.ok()).toBeTruthy();
    });
});
