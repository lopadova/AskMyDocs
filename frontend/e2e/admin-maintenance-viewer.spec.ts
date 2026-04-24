import { test, expect } from '@playwright/test';

/*
 * PR13 — Phase H2. Admin Maintenance RBAC denial under viewer.
 *
 * Same shape as admin-logs-viewer.spec.ts / admin-kb-viewer.spec.ts —
 * the viewer role is not in the `admin|super-admin` allowlist so
 * both the SPA and the backend reject the request. Two assertions:
 *
 *   - SPA route: /app/admin/maintenance → <AdminForbidden />.
 *   - Backend: /api/admin/commands/catalogue → HTTP 403.
 */

test.describe('Admin Maintenance — viewer (RBAC denied)', () => {
    test('viewer hitting /app/admin/maintenance sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/maintenance');

        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('maintenance-view')).toHaveCount(0);
        await expect(page.getByTestId('maintenance-panel-commands')).toHaveCount(0);
    });

    test('viewer API call to /api/admin/commands/catalogue returns 403', async ({ request }) => {
        const response = await request.get('/api/admin/commands/catalogue');
        expect(response.status()).toBe(403);
    });
});
