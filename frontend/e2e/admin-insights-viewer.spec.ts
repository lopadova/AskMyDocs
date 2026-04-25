import { test, expect } from '@playwright/test';

/*
 * PR14 — Phase I. Admin Insights RBAC denial under viewer.
 *
 * Same shape as admin-logs-viewer / admin-maintenance-viewer — the
 * viewer role is NOT in the `admin|super-admin` allowlist so both
 * the SPA and the backend reject the request:
 *
 *   - SPA route: /app/admin/insights → <AdminForbidden />.
 *   - Backend: /api/admin/insights/latest → HTTP 403.
 */

test.describe('Admin Insights — viewer (RBAC denied)', () => {
    test('viewer hitting /app/admin/insights sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/insights');

        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('insights-view')).toHaveCount(0);
    });

    test('viewer API call to /api/admin/insights/latest returns 403', async ({ request }) => {
        const response = await request.get('/api/admin/insights/latest');
        expect(response.status()).toBe(403);
    });
});
