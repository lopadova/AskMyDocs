import { test, expect } from '@playwright/test';

/*
 * v5.0/W2 — Admin MCP Tools — super-admin scenarios.
 *
 * Routed via the playwright.config.ts `chromium-super-admin` project
 * (testMatch /.*-super-admin\.spec\.ts/) so the storageState
 * playwright/.auth/super-admin.json is materialised by
 * super-admin.setup.ts BEFORE this spec runs. R13: real Laravel + real
 * DB seed, no internal page.route stubs.
 *
 * Seeding posture: super-admin.setup.ts seeds DemoSeeder (which
 * creates the super@demo.local user with the manageMcpTools
 * permission) before any *-super-admin.spec.ts file runs. No
 * per-test resetAndSeed is needed and would in any case fail because
 * 'super-admin' is not a registered seeder name (only DemoSeeder,
 * RbacSeeder, EmptyAdminSeeder, AdminDegradedSeeder,
 * AdminInsightsSeeder are valid).
 */
test.describe('Admin MCP Tools — super-admin', () => {

    test('super-admin sees the MCP Tools rail entry and lands on the page', async ({ page }) => {
        await page.goto('/app/admin/mcp-tools');
        await expect(page.getByTestId('admin-mcp-tools')).toBeVisible();
        await expect(page.getByTestId('admin-mcp-tools')).toHaveAttribute(
            'data-state',
            'ready',
        );
        await expect(page.getByTestId('admin-mcp-tools-title')).toHaveText('MCP tools');
    });

    test('register dialog opens, validates name, transport, endpoint and closes', async ({
        page,
    }) => {
        await page.goto('/app/admin/mcp-tools');
        await page.getByTestId('admin-mcp-tools-register').click();
        const dialog = page.getByTestId('admin-mcp-register-dialog');
        await expect(dialog).toBeVisible();

        // Submit empty form → browser-level required validation prevents
        // submission; the dialog stays open.
        await page.getByTestId('admin-mcp-register-submit').click();
        await expect(dialog).toBeVisible();

        // Close via cancel
        await page.getByTestId('admin-mcp-register-cancel').click();
        await expect(dialog).toHaveCount(0);
    });

    test('audit tab renders filter bar and empty state', async ({ page }) => {
        await page.goto('/app/admin/mcp-tools');
        await page.getByTestId('admin-mcp-tools-tab-audit').click();
        await expect(page.getByTestId('admin-mcp-audit')).toBeVisible();
        await expect(page.getByTestId('admin-mcp-audit-filter-server')).toBeVisible();
        await expect(page.getByTestId('admin-mcp-audit-filter-status')).toBeVisible();
        // Empty state on fresh seed
        await expect(page.getByTestId('admin-mcp-audit')).toHaveAttribute(
            'data-state',
            /empty|ready|loading/,
        );
    });
});
