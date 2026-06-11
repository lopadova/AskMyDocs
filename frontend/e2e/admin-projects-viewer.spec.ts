import { test, expect } from '@playwright/test';

/*
 * v8.9 — Admin Projects RBAC denial.
 *
 * Runs under the `chromium-viewer` Playwright project (viewer.setup.ts
 * storage state). Mirrors admin-kb-viewer: no inherited `seeded`
 * auto-fixture — viewer-setup already ran DemoSeeder and we do not want
 * a second reset stomping the session cookie.
 */

test.describe('Admin Projects — viewer (RBAC denied)', () => {
    test('viewer hitting /app/admin/projects sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/projects');

        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-projects-view')).toHaveCount(0);
    });

    test('viewer API call to /api/admin/projects returns 403', async ({ page }) => {
        const res = await page.request.get('/api/admin/projects');
        expect(res.status()).toBe(403);
    });
});
