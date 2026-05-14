import { test, expect } from '@playwright/test';

/*
 * v5.0/W2 — Admin MCP Tools — viewer-role denial scenario.
 *
 * Routed via the playwright.config.ts `chromium-viewer` project
 * (testMatch /.*-viewer\.spec\.ts/) so playwright/.auth/viewer.json is
 * materialised by viewer.setup.ts BEFORE this spec runs. The seeder
 * baseline (DemoSeeder) is established by viewer.setup.ts; no
 * per-test resetAndSeed is needed.
 */
test.describe('Admin MCP Tools — viewer role', () => {

    test('viewer hitting /app/admin/mcp-tools sees the Forbidden surface', async ({ page }) => {
        await page.goto('/app/admin/mcp-tools');
        // RequireRole short-circuits to <AdminForbidden /> for non-super-admin
        await expect(page.getByTestId('admin-forbidden')).toBeVisible();
    });
});
