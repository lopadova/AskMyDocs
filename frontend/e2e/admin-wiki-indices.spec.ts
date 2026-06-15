import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * v8.11/P10 — Wiki Indices admin screen (Auto-Wiki hub + per-project roll-ups +
 * operation log).
 *
 * R13: the happy path runs against the REAL `/api/admin/kb/wiki-index` +
 * `/wiki-operations` endpoints backed by the real DB (`WikiIndicesSeeder` inserts
 * a project with two slugged docs and NO pre-built index). The Rebuild flow
 * exercises the real POST and asserts the recomputed hub + a logged operation.
 * The failure path injects a 503 on the index GET.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Wiki Indices', () => {
    test('rebuilds the wiki index from real data and shows the hub roll-up', async ({ page, request }) => {
        await seedDb(request, 'WikiIndicesSeeder');

        await page.goto('/app/admin/kb/wiki-indices');
        await expect(page.getByTestId('admin-wiki-indices-view')).toBeVisible({ timeout: 15_000 });

        // No index has been built yet — the screen starts empty.
        await expect(page.getByTestId('admin-wiki-indices-empty')).toHaveAttribute('data-state', 'empty', { timeout: 15_000 });

        // Rebuild via the REAL POST endpoint, then the hub roll-up renders.
        const rebuildResp = page.waitForResponse(
            (r) => r.url().includes('/api/admin/kb/wiki-index') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-wiki-indices-rebuild').click();
        const resp = await rebuildResp;
        expect(resp.ok()).toBeTruthy();

        await expect(page.getByTestId('admin-wiki-indices-hub')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        // The `seeded` auto-fixture (DemoSeeder) already populates other projects
        // in this tenant, so the global project count is not deterministic — assert
        // the seeded `eng` row specifically instead.
        const engRow = page.getByTestId('admin-wiki-indices-project-row-eng');
        await expect(engRow).toBeVisible();
        await expect(engRow).toContainText('eng');
        // 2 pages, 1 concept, 1 auto, 1 human (see WikiIndicesSeeder).
        await expect(engRow).toContainText('2');
        // The rebuild logged a graph_rebuild operation.
        await expect(page.getByTestId('admin-wiki-indices-operations')).toContainText('graph_rebuild', { timeout: 15_000 });
    });

    // R13: failure injection — stubs the index GET to 503 so the error-state
    // branch renders deterministically. The happy path above exercises real data.
    test('shows error state when the index endpoint returns 503', async ({ page, request }) => {
        await seedDb(request, 'WikiIndicesSeeder');

        // R13: failure injection
        await page.route('**/api/admin/kb/wiki-index**', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Service unavailable' }) });
            }
            return route.continue();
        });

        await page.goto('/app/admin/kb/wiki-indices');
        await expect(page.getByTestId('admin-wiki-indices-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-wiki-indices-error')).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
    });
});
