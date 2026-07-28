import { test, expect } from './fixtures';

/*
 * Global tenant control plane — real system-administration journey.
 * The fixture resets the DB after every scenario boundary, which is the
 * deterministic cleanup for the tenant created below.
 */
test.describe('Tenant control — system administrator', () => {
    test.describe.configure({ mode: 'serial' });

    test('provisions a tenant super-admin, rejects unsafe attachment, and confirms lifecycle changes', async ({ page }) => {
        test.setTimeout(60_000);
        await page.goto('/app');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        const entry = page.getByTestId('sidebar-nav-tenant-control');
        await expect(entry).toBeVisible();
        await entry.click();

        await expect(page).toHaveURL(/\/app\/system\/tenants$/);
        await expect(page.getByTestId('tenant-control-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('tenant-control-list-error')).toHaveCount(0);

        // Failure path first: attaching the seeded viewer as admin would
        // silently raise their role across every tenant, so the UI blocks it.
        await page.getByTestId('tenant-control-open-provision').click();
        await page.getByTestId('tenant-control-provision-tenant-name').fill('Unsafe Attach');
        await page.getByTestId('tenant-control-provision-user-email').fill('viewer@demo.local');
        await expect(page.getByTestId('tenant-control-availability-role-mismatch')).toBeVisible();
        await expect(page.getByTestId('tenant-control-provision-submit')).toBeDisabled();
        await page.getByTestId('tenant-control-provision-cancel').click();

        // Happy path: a new tenant starts with a tenant-scoped super-admin,
        // never another system administrator.
        const suffix = Date.now().toString(36);
        const slug = `e2e-system-${suffix}`;
        await page.getByTestId('tenant-control-open-provision').click();
        await page.getByTestId('tenant-control-provision-tenant-name').fill(`E2E System ${suffix}`);
        await page.getByTestId('tenant-control-provision-tenant-slug').fill(slug);
        await page.getByTestId('tenant-control-provision-user-email').fill(`owner-${suffix}@example.test`);
        await page.getByTestId('tenant-control-provision-user-name').fill('E2E Tenant Owner');
        await page.getByTestId('tenant-control-provision-role').selectOption('super-admin');
        await expect(page.getByTestId('tenant-control-provision-submit')).toBeEnabled();
        await page.getByTestId('tenant-control-provision-submit').click();
        await expect(page.getByTestId('tenant-control-provision-success')).toBeVisible();
        await page.getByTestId('tenant-control-provision-close').click();

        await expect(page.getByTestId(`tenant-control-row-${slug}`)).toBeVisible();
        await page.getByTestId(`tenant-control-row-${slug}-open`).click();
        await expect(page.getByTestId('tenant-control-detail')).toContainText('E2E Tenant Owner');

        // Suspend and reactivate through real DB-backed, single-use previews.
        await page.getByTestId('tenant-control-detail-status').selectOption('suspended');
        await page.getByTestId('tenant-control-detail-save').click();
        await expect(page.getByTestId('tenant-control-lifecycle-confirm')).toBeVisible();
        await page.getByTestId('tenant-control-lifecycle-confirm-submit').click();
        await expect(page.getByTestId('tenant-control-detail-current-status')).toHaveText('suspended');

        await page.getByTestId('tenant-control-detail-status').selectOption('active');
        await page.getByTestId('tenant-control-detail-save').click();
        await expect(page.getByTestId('tenant-control-lifecycle-confirm')).toBeVisible();
        await page.getByTestId('tenant-control-lifecycle-confirm-submit').click();
        await expect(page.getByTestId('tenant-control-detail-current-status')).toHaveText('active');
    });
});
