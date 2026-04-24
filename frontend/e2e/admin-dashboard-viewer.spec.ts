import { test, expect } from '@playwright/test';

/*
 * PR6 — Phase F1. Admin Dashboard RBAC denial scenarios.
 *
 * Runs under the `chromium-viewer` Playwright project (viewer.setup.ts
 * storage state). The `setup` project dependency is viewer-setup, NOT
 * auth-setup — we intentionally keep the admin + viewer sessions in
 * separate storage state files so one never leaks into the other.
 *
 * This spec does not inherit the `seeded` auto-fixture — the
 * viewer-setup call already ran DemoSeeder and we do not want a
 * second reset stomping the authenticated session cookie.
 */

test.describe('Admin Dashboard — viewer (RBAC denied)', () => {
    test('viewer hitting /app/admin sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin');

        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        // The dashboard must not have rendered.
        await expect(page.getByTestId('admin-dashboard')).toHaveCount(0);
        await expect(page.getByTestId('kpi-strip')).toHaveCount(0);
    });

    test('viewer API call to /api/admin/metrics/overview returns 403', async ({ request }) => {
        const response = await request.get('/api/admin/metrics/overview');
        expect(response.status()).toBe(403);
    });
});
