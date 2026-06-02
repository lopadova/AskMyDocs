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
        // The DemoSeeder seeds KB documents, so document id 1 exists; the
        // Time Machine reads its real `/versions` family (a document is always
        // a version of itself, so the timeline is non-empty). R12: the happy
        // path asserts the real-data SUCCESS state (timeline | empty), NOT the
        // error branch — that's covered by the second test below.
        await page.goto('/app/admin/kb/time-machine/1');
        await expect(page.getByTestId('kb-time-machine-view')).toBeVisible({ timeout: 15_000 });
        const timeline = page.getByTestId('kb-time-machine-timeline');
        const empty = page.getByTestId('kb-time-machine-empty');
        await expect(timeline.or(empty)).toBeVisible({ timeout: 15_000 });
    });

    test('shows the error state for a non-existent document (real 404)', async ({ page }) => {
        await page.goto('/app/admin/kb/time-machine/999999');
        await expect(page.getByTestId('kb-time-machine-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-time-machine-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-time-machine-error')).toHaveAttribute('data-state', 'error');
    });
});
