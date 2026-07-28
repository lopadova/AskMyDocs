import { test, expect } from './fixtures';

/*
 * Global tenant control plane — regular-admin denial.
 *
 * The standard chromium project carries the seeded `admin` session. The shared
 * fixture refreshes that session after its isolated reset so this denial check
 * remains stable when viewer/super-admin setup projects run in the same suite.
 * The rail must not advertise the platform surface and direct navigation must
 * render the SPA's forbidden boundary; the API role gate is covered
 * independently by role-access.spec.ts and PHPUnit.
 */
test.describe('Tenant control — regular admin', () => {
    test('entry is hidden and a direct route is forbidden', async ({ page }) => {
        await page.goto('/app');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('sidebar-nav-tenant-control')).toHaveCount(0);

        await page.goto('/app/system/tenants');
        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
    });
});
