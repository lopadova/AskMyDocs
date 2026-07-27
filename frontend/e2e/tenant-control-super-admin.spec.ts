import { test, expect } from '@playwright/test';

/*
 * Global tenant control plane — super-admin UI boundary.
 *
 * Runs in the chromium-super-admin project via the filename suffix and uses
 * the real Laravel registry/API. It is intentionally read-only: provisioning
 * mutations are covered atomically in PHPUnit and must not leak tenant rows
 * between parallel E2E specs.
 */
test.describe('Tenant control — super-admin', () => {
    test('sidebar entry opens the global registry and access panel', async ({ page }) => {
        await page.goto('/app');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        const entry = page.getByTestId('sidebar-nav-tenant-control');
        await expect(entry).toBeVisible();
        await entry.click();

        await expect(page).toHaveURL(/\/super-admin\/tenants$/);
        await expect(page.getByTestId('tenant-control-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('tenant-control-open-provision')).toBeVisible();
        await expect(page.getByTestId('tenant-control-list-error')).toHaveCount(0);
    });
});
