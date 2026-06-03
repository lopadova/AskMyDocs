import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.8/W3 — per-(tenant, project) AI deep-analysis gate admin screen.
 *
 * R13: the happy path runs against the REAL `/api/admin/kb/analysis-settings`
 * endpoint backed by the real DB (GET to load + PUT to persist). The tenant-
 * wide wildcard row ('*') is always present regardless of seeded projects, so
 * the toggle assertion is deterministic. The failure path injects a 503 on the
 * GET to exercise the error state.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Deep-Analysis Gate', () => {
    test('admin can toggle the tenant-wide gate and it persists (real data)', async ({ page }) => {
        await page.goto('/app/admin/kb/analysis-settings');
        await expect(page.getByTestId('admin-analysis-settings-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-analysis-settings-list')).toBeVisible({ timeout: 15_000 });

        // The tenant-wide '*' row is always present.
        const wildcardEnabled = page.getByTestId('admin-analysis-setting-*-enabled');
        await expect(wildcardEnabled).toBeVisible();

        // Flip the tenant-wide gate OFF and assert the real PUT succeeds.
        const putResp = page.waitForResponse(
            (r) => r.url().includes('/api/admin/kb/analysis-settings') && r.request().method() === 'PUT',
            { timeout: 15_000 },
        );
        await wildcardEnabled.selectOption('off');
        const resp = await putResp;
        expect(resp.ok()).toBeTruthy();

        // After the refetch the select reflects the persisted 'off'.
        await expect(page.getByTestId('admin-analysis-setting-*-enabled')).toHaveValue('off', { timeout: 15_000 });

        // Restore to inherit so the scenario leaves no cross-test residue.
        const restoreResp = page.waitForResponse(
            (r) => r.url().includes('/api/admin/kb/analysis-settings') && r.request().method() === 'PUT',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-analysis-setting-*-enabled').selectOption('inherit');
        await restoreResp;
    });

    // R13: failure injection — stubs the GET to return 503 so the error-state
    // branch renders deterministically. The happy path above exercises real data.
    test('shows error state when the settings endpoint returns 503', async ({ page }) => {
        // R13: failure injection
        await page.route('**/api/admin/kb/analysis-settings', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Service unavailable' }) });
            }
            return route.continue();
        });

        await page.goto('/app/admin/kb/analysis-settings');
        await expect(page.getByTestId('admin-analysis-settings-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-analysis-settings-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-analysis-settings-error')).toHaveAttribute('data-state', 'error');
    });
});
