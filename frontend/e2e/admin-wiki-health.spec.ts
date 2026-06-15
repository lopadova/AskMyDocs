import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * v8.11/P10 — Wiki Health admin screen (Auto-Wiki lint report + safe auto-fix).
 *
 * R13: the happy path runs against the REAL `/api/admin/kb/wiki-lint` endpoint
 * backed by the real DB (`WikiHealthSeeder` inserts a project with a dangling +
 * orphan node and no index). The fix flow exercises the real POST. The failure
 * path injects a 503 on the lint GET.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Wiki Health', () => {
    test('lints a project and auto-fixes dangling nodes (real data)', async ({ page, request }) => {
        await seedDb(request, 'WikiHealthSeeder');

        await page.goto('/app/admin/kb/wiki-health');
        await expect(page.getByTestId('admin-wiki-health-view')).toBeVisible({ timeout: 15_000 });

        // Pick the seeded project; the option loads from the real projects API.
        // Exact-match the option text — the DemoSeeder also creates `engineering`,
        // which a bare `hasText: 'eng'` substring would collide with.
        const projectSelect = page.getByTestId('admin-wiki-health-project');
        await expect(projectSelect.locator('option', { hasText: /^eng$/ })).toHaveCount(1, { timeout: 15_000 });
        await projectSelect.selectOption('eng');

        // Real lint report renders with the seeded dangling finding.
        await expect(page.getByTestId('admin-wiki-health-report')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('admin-wiki-health-dangling')).toContainText('ghost-leftover');
        await expect(page.getByTestId('admin-wiki-health-count-dangling')).toContainText('1');

        // Apply the safe auto-fix via the REAL POST endpoint.
        const fixResp = page.waitForResponse(
            (r) => r.url().includes('/api/admin/kb/wiki-lint/fix') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-wiki-health-fix').click();
        const resp = await fixResp;
        expect(resp.ok()).toBeTruthy();
        await expect(page.getByTestId('admin-wiki-health-fix-note')).toBeVisible({ timeout: 15_000 });
    });

    // R13: failure injection — stubs the lint GET to 503 so the error-state
    // branch renders deterministically. The happy path above exercises real data.
    test('shows error state when the lint endpoint returns 503', async ({ page, request }) => {
        await seedDb(request, 'WikiHealthSeeder');

        // R13: failure injection
        await page.route('**/api/admin/kb/wiki-lint**', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Service unavailable' }) });
            }
            return route.continue();
        });

        await page.goto('/app/admin/kb/wiki-health');
        await expect(page.getByTestId('admin-wiki-health-view')).toBeVisible({ timeout: 15_000 });
        await page.getByTestId('admin-wiki-health-project').selectOption('eng');
        await expect(page.getByTestId('admin-wiki-health-error')).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
    });
});
