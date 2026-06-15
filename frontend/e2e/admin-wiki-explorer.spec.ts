import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * v8.11/P10 — Wiki Explorer admin screen (browse typed wiki pages by tier +
 * promote/discard auto pages).
 *
 * R13: the happy path runs against the REAL `/api/admin/kb/wiki-pages` +
 * `/wiki-promote` endpoints backed by the real DB (`WikiExplorerSeeder` inserts
 * one auto + one human page in project `eng`). Promoting the auto page flips it
 * to human, and the row becomes read-only after the real refetch. The failure
 * path injects a 503 on the list GET.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Wiki Explorer', () => {
    test('lists pages by tier and promotes an auto page (real data)', async ({ page, request }) => {
        await seedDb(request, 'WikiExplorerSeeder');

        await page.goto('/app/admin/kb/wiki-explorer');
        await expect(page.getByTestId('admin-wiki-explorer-view')).toBeVisible({ timeout: 15_000 });

        // Pick the seeded project (exact match — DemoSeeder also has `engineering`).
        const projectSelect = page.getByTestId('admin-wiki-explorer-project');
        await expect(projectSelect.locator('option', { hasText: /^eng$/ })).toHaveCount(1, { timeout: 15_000 });
        await projectSelect.selectOption('eng');

        // The real list renders; the seeded auto page carries an auto tier badge.
        await expect(page.getByTestId('admin-wiki-explorer-table')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        const autoRow = page.locator('[data-testid^="admin-wiki-explorer-row-"][data-tier="auto"]');
        await expect(autoRow).toHaveCount(1);
        await expect(autoRow).toContainText('auto-cache');

        // Promote the auto page via the REAL POST endpoint.
        const promoteResp = page.waitForResponse(
            (r) => /\/api\/admin\/kb\/documents\/\d+\/wiki-promote$/.test(r.url()) && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.locator('[data-testid$="-promote"]').first().click();
        const resp = await promoteResp;
        expect(resp.ok()).toBeTruthy();

        await expect(page.getByTestId('admin-wiki-explorer-note')).toContainText('Promoted auto-cache', { timeout: 15_000 });
        // After the real refetch the page is human → no more auto-tier rows.
        await expect(page.locator('[data-testid^="admin-wiki-explorer-row-"][data-tier="auto"]')).toHaveCount(0, { timeout: 15_000 });
    });

    // R13: failure injection — stubs the list GET to 503 so the error-state
    // branch renders deterministically. The happy path above exercises real data.
    test('shows error state when the wiki-pages endpoint returns 503', async ({ page, request }) => {
        await seedDb(request, 'WikiExplorerSeeder');

        // R13: failure injection
        await page.route('**/api/admin/kb/wiki-pages**', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Service unavailable' }) });
            }
            return route.continue();
        });

        await page.goto('/app/admin/kb/wiki-explorer');
        await expect(page.getByTestId('admin-wiki-explorer-view')).toBeVisible({ timeout: 15_000 });
        await page.getByTestId('admin-wiki-explorer-project').selectOption('eng');
        await expect(page.getByTestId('admin-wiki-explorer-error')).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
    });
});
