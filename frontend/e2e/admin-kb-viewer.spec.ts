import { test, expect } from '@playwright/test';

/*
 * PR8 — Phase G1. Admin KB Explorer RBAC denial.
 *
 * Runs under the `chromium-viewer` Playwright project (viewer.setup.ts
 * storage state). Mirrors admin-dashboard-viewer + admin-users-viewer:
 * no inherited `seeded` auto-fixture — the viewer-setup already ran
 * DemoSeeder, and we do not want a second reset stomping the session
 * cookie.
 */

test.describe('Admin KB Tree — viewer (RBAC denied)', () => {
    test('viewer hitting /app/admin/kb sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/kb');

        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-view')).toHaveCount(0);
        await expect(page.getByTestId('kb-tree')).toHaveCount(0);
    });
});
