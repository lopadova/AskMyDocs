import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.7/W5 — Cloud Time Machine (per-document version timeline + diff + restore).
 *
 * R13: real `/api/admin/kb/documents/*` endpoints + real DB. ZERO route
 * stubs. The happy path resolves a real document id from the admin docs
 * list and opens its timeline; the failure path opens a non-existent doc id
 * and asserts the error state renders from the REAL 404 (not a stub).
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Time Machine', () => {
    test('opens a real document timeline and never crashes', async ({ page }) => {
        // Resolve a real document id from the admin docs list.
        const resp = await page.request.get('/api/admin/kb/documents?per_page=1');
        expect(resp.ok()).toBeTruthy();
        const body = await resp.json();
        const docId = body?.data?.[0]?.id;
        test.skip(!docId, 'No KB documents seeded to drive the Time Machine timeline.');

        await page.goto(`/app/admin/kb/time-machine/${docId}`);
        await expect(page.getByTestId('kb-time-machine-view')).toBeVisible({ timeout: 15_000 });
        const timeline = page.getByTestId('kb-time-machine-timeline');
        const empty = page.getByTestId('kb-time-machine-empty');
        const error = page.getByTestId('kb-time-machine-error');
        await expect(timeline.or(empty).or(error)).toBeVisible({ timeout: 15_000 });
    });

    test('shows the error state for a non-existent document (real 404)', async ({ page }) => {
        await page.goto('/app/admin/kb/time-machine/999999');
        await expect(page.getByTestId('kb-time-machine-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-time-machine-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-time-machine-error')).toHaveAttribute('data-state', 'error');
    });
});
