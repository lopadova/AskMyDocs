import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * PR7 — Phase F2. Admin Users E2E scenarios.
 *
 * All happy-path scenarios run against the REAL backend seeded with
 * DemoSeeder. The viewer account (viewer@demo.local) is seeded on top
 * so the "assign a role" scenarios can target a non-admin user without
 * needing to create one mid-test.
 *
 * R13 compliance: no request interception against `/api/admin/**` on
 * happy paths. The single stubbed scenario carries the required
 * `R13: failure injection` marker comment on the preceding line.
 */

test.describe('Admin Users', () => {
    test('happy — create user, assign role + membership, row visible', async ({ page }) => {
        await page.goto('/app/admin/users');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-users')).toBeVisible();
        await expect(page.getByTestId('users-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Open the create drawer.
        await page.getByTestId('users-new').click();
        await expect(page.getByTestId('user-drawer')).toBeVisible();
        await expect(page.getByTestId('user-drawer')).toHaveAttribute('data-mode', 'create');

        // Fill the form — the real backend validates email uniqueness.
        await page.getByTestId('user-form-name').fill('Playwright User');
        await page.getByTestId('user-form-email').fill('playwright@demo.local');
        await page.getByTestId('user-form-password').fill('P@ssw0rd-Playwright-1');
        await page.getByTestId('user-form-role-viewer').click();
        await page.getByTestId('user-form-submit').click();

        await expect(page.getByTestId('toast-user-created')).toBeVisible({ timeout: 10_000 });

        // The new row lands in the table.
        await expect(page.getByTestId('users-table').locator('tbody tr', {
            hasText: 'playwright@demo.local',
        })).toBeVisible({ timeout: 10_000 });
    });

    test('happy — edit user — swap role viewer -> editor', async ({ page }) => {
        await page.goto('/app/admin/users');
        await expect(page.getByTestId('users-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Filter to the seeded viewer.
        await page.getByTestId('users-filter-q').fill('viewer@demo.local');
        await expect(page.locator('tbody tr', { hasText: 'viewer@demo.local' })).toBeVisible({
            timeout: 10_000,
        });

        // Open the drawer for that row.
        const row = page.locator('tbody tr', { hasText: 'viewer@demo.local' });
        await row.locator('[data-testid^="users-row-"][data-testid$="-edit"]').click();
        await expect(page.getByTestId('user-drawer')).toBeVisible();
        await expect(page.getByTestId('user-drawer')).toHaveAttribute('data-mode', 'edit');

        // Toggle viewer off, editor on, save.
        await page.getByTestId('user-form-role-viewer').click();
        await page.getByTestId('user-form-role-editor').click();
        await page.getByTestId('user-form-submit').click();

        await expect(page.getByTestId('toast-user-updated')).toBeVisible({ timeout: 10_000 });

        // Row shows the editor role chip.
        await expect(page.locator('tbody tr', { hasText: 'viewer@demo.local' }).getByText('editor', { exact: false })).toBeVisible({ timeout: 10_000 });
    });

    test('happy — soft delete + restore via with_trashed toggle', async ({ page, request }) => {
        // Create a throwaway user directly through the API so the test is
        // hermetic — the demo admin/viewer pair must not be modified here.
        const csrf = await request.get('/sanctum/csrf-cookie');
        expect(csrf.ok()).toBeTruthy();
        const create = await request.post('/api/admin/users', {
            data: {
                name: 'Throwaway',
                email: 'throwaway@demo.local',
                password: 'P@ssw0rd-Throwaway-9',
                roles: ['viewer'],
            },
        });
        expect(create.status()).toBe(201);

        await page.goto('/app/admin/users');
        await expect(page.getByTestId('users-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Delete the row.
        const row = page.locator('tbody tr', { hasText: 'throwaway@demo.local' });
        await row.locator('[data-testid^="users-row-"][data-testid$="-delete"]').click();
        await expect(page.getByTestId('toast-user-deleted')).toBeVisible({ timeout: 10_000 });

        // Default list hides the trashed row.
        await expect(page.locator('tbody tr', { hasText: 'throwaway@demo.local' })).toHaveCount(0, {
            timeout: 10_000,
        });

        // Toggle "Include deleted" ON — trashed row reappears with a
        // restore button.
        await page.getByTestId('users-filter-with-trashed').check();
        await expect(page.locator('tbody tr', { hasText: 'throwaway@demo.local' })).toBeVisible({
            timeout: 10_000,
        });

        // Click restore; row returns to the default (untrashed) list.
        const trashed = page.locator('tbody tr[data-trashed="true"]', {
            hasText: 'throwaway@demo.local',
        });
        await trashed.locator('[data-testid$="-restore"]').click();
        await expect(page.getByTestId('toast-user-restored')).toBeVisible({ timeout: 10_000 });

        await page.getByTestId('users-filter-with-trashed').uncheck();
        await expect(page.locator('tbody tr', { hasText: 'throwaway@demo.local' })).toBeVisible();
    });

    test('failure — duplicate email surfaces a 422 field error', async ({ page }) => {
        await page.goto('/app/admin/users');
        await expect(page.getByTestId('users-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // DemoSeeder already seeds viewer@demo.local — creating another
        // user with the same email must hit the real 422.
        await page.getByTestId('users-new').click();
        await page.getByTestId('user-form-name').fill('Duplicate');
        await page.getByTestId('user-form-email').fill('viewer@demo.local');
        await page.getByTestId('user-form-password').fill('P@ssw0rd-Duplicate-1');
        await page.getByTestId('user-form-submit').click();

        // Server error becomes a per-field error with the documented testid.
        await expect(page.getByTestId('user-form-email-error')).toBeVisible({ timeout: 10_000 });
    });

    test('failure — self delete blocked with 422 error toast', async ({ page }) => {
        await page.goto('/app/admin/users');
        await expect(page.getByTestId('users-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Filter to the admin account so we act on the currently-signed-in row.
        await page.getByTestId('users-filter-q').fill('admin@demo.local');
        const row = page.locator('tbody tr', { hasText: 'admin@demo.local' });
        await expect(row).toBeVisible({ timeout: 10_000 });

        await row.locator('[data-testid^="users-row-"][data-testid$="-delete"]').click();

        // The backend returns 422 "You cannot delete your own account."
        await expect(page.getByTestId('toast-user-error')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('toast-user-error')).toContainText(/cannot delete/i);

        // Admin row remains in the table.
        await expect(page.locator('tbody tr', { hasText: 'admin@demo.local' })).toBeVisible();
    });

    test('failure injection — /api/admin/users 500 surfaces users-error', async ({ page }) => {
        /* R13: failure injection — the happy path above already hits the real backend. */
        await page.route('**/api/admin/users*', (r) => r.fulfill({ status: 500, body: '{}' }));

        await page.goto('/app/admin/users');

        await expect(page.getByTestId('users-table')).toHaveAttribute('data-state', 'error', {
            timeout: 30_000,
        });
        await expect(page.getByTestId('users-error')).toBeVisible();
    });
});
