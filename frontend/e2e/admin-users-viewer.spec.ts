import { test, expect } from '@playwright/test';

/*
 * PR7 — Phase F2. Admin Users RBAC denial scenarios.
 *
 * Runs under the `chromium-viewer` Playwright project (viewer.setup.ts
 * storage state). Mirrors admin-dashboard-viewer.spec.ts: no inherited
 * `seeded` auto-fixture — the viewer-setup already ran DemoSeeder and
 * we do not want a second reset stomping the session cookie.
 */

test.describe('Admin Users — viewer (RBAC denied)', () => {
    test('viewer hitting /app/admin/users sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/users');

        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        // The Users view must not have rendered.
        await expect(page.getByTestId('admin-users')).toHaveCount(0);
        await expect(page.getByTestId('users-table')).toHaveCount(0);
    });

    test('viewer API call to /api/admin/users returns 403', async ({ request }) => {
        const response = await request.get('/api/admin/users');
        expect(response.status()).toBe(403);
    });

    test('viewer hitting /app/admin/roles sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/roles');

        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-roles')).toHaveCount(0);
    });
});
