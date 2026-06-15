import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * v8.11/P10 — Auto-Wiki Settings admin screen (per-(tenant,project) auto-build
 * gate).
 *
 * R13: the happy path runs against the REAL `/api/admin/kb/autowiki-settings`
 * endpoint backed by the real DB (`WikiExplorerSeeder` gives project `eng` real
 * docs so it appears in the list). Toggling Auto-build OFF exercises the real PUT
 * and asserts the effective value flips. The failure path injects a 503 on GET.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Auto-Wiki Settings', () => {
    test('toggles a project Auto-build off against real data', async ({ page, request }) => {
        await seedDb(request, 'WikiExplorerSeeder');

        await page.goto('/app/admin/kb/autowiki-settings');
        await expect(page.getByTestId('admin-autowiki-settings-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-autowiki-settings-list')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // The seeded `eng` project appears; its Auto-build starts effective-on (config default).
        const engEnabled = page.getByTestId('admin-autowiki-setting-eng-enabled');
        await expect(engEnabled).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-autowiki-setting-eng-enabled-effective')).toContainText('on');

        // Turn Auto-build OFF via the REAL PUT endpoint.
        const putResp = page.waitForResponse(
            (r) => r.url().includes('/api/admin/kb/autowiki-settings') && r.request().method() === 'PUT',
            { timeout: 15_000 },
        );
        await engEnabled.selectOption('off');
        const resp = await putResp;
        expect(resp.ok()).toBeTruthy();

        // After the real refetch the effective value flips to off.
        await expect(page.getByTestId('admin-autowiki-setting-eng-enabled-effective')).toContainText('off', { timeout: 15_000 });
    });

    // R13: failure injection — stubs the settings GET to 503 so the error-state
    // branch renders deterministically. The happy path above exercises real data.
    test('shows error state when the settings endpoint returns 503', async ({ page, request }) => {
        await seedDb(request, 'WikiExplorerSeeder');

        // R13: failure injection
        await page.route('**/api/admin/kb/autowiki-settings**', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Service unavailable' }) });
            }
            return route.continue();
        });

        await page.goto('/app/admin/kb/autowiki-settings');
        await expect(page.getByTestId('admin-autowiki-settings-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-autowiki-settings-error')).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
    });
});
