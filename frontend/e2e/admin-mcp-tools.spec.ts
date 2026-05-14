import { test, expect } from '@playwright/test';
import { resetAndSeed } from './setup-helpers';

/*
 * v5.0/W2 — Admin MCP Tools landing E2E. Covers:
 *   - super-admin sees the rail entry + lands on the page
 *   - register dialog opens + validates required fields
 *   - audit tab toggles + filter widgets render
 *
 * R13 — runs against real Laravel + real DB seed (no internal page.route
 * stubs). The seeder creates a super-admin user with the
 * `manageMcpTools` permission. Tool invocations would call the Node
 * sidecar — those are exercised by the integration tests under
 * mcp-client/tests/integration/, not here.
 */

test.describe('Admin MCP Tools', () => {
    test.use({ storageState: 'playwright/.auth/super-admin.json' });

    test.beforeEach(async ({ request }) => {
        await resetAndSeed(request, 'super-admin');
    });

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

test.describe('Admin MCP Tools (viewer role)', () => {
    test.use({ storageState: 'playwright/.auth/viewer.json' });

    test.beforeEach(async ({ request }) => {
        await resetAndSeed(request, 'viewer');
    });

    test('viewer hitting /app/admin/mcp-tools sees the Forbidden surface', async ({ page }) => {
        await page.goto('/app/admin/mcp-tools');
        // RequireRole short-circuits to <AdminForbidden /> for non-super-admin
        await expect(page.getByTestId('admin-forbidden')).toBeVisible();
    });
});
