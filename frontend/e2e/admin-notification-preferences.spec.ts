import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.0/W2.2 — Notification preferences grid E2E.
 *
 * R12 — user-facing UI changes ship Playwright coverage in the same
 * PR. R13 — the happy path runs against the real Laravel back-end +
 * SQLite database (the seeded user starts with zero preferences); the
 * failure scenario carries the marker comment + an explicit
 * `page.route()` injection.
 *
 * Scenarios:
 *   1. happy: grid loads, every cell visible, flipping a cell + saving
 *      persists across reload (verified by re-reading from BE).
 *   2. R13 failure injection: force PUT 500 and assert the save-error
 *      banner surfaces (R14 — no silent fallback).
 */

test.describe('Notification preferences grid', () => {
    test('flips a cell + saves; the value persists after reload', async ({ page }) => {
        await page.goto('/app/admin/notifications/preferences');

        const grid = page.getByTestId('notif-pref');
        await expect(grid).toBeVisible({ timeout: 15_000 });
        await expect(grid).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // Pick a deterministic registered-channel cell. `in_app` is
        // always registered (W1.3 adapter binds unconditionally),
        // `kb_doc_created` is the first event type returned by the BE.
        const cell = page.getByTestId('notif-pref-cell-kb_doc_created-in_app-toggle');
        await expect(cell).toBeVisible();

        // Default seed is `in_app=true`; flip it off.
        await expect(cell).toBeChecked();
        await cell.click();
        await expect(cell).not.toBeChecked();
        await expect(page.getByTestId('notif-pref-dirty-indicator')).toBeVisible();

        // Save → server round-trip → success banner.
        await page.getByTestId('notif-pref-save').click();
        await expect(page.getByTestId('notif-pref-save-success')).toBeVisible({ timeout: 10_000 });

        // Reload the page → BE must return the persisted preference,
        // the cell stays unchecked.
        await page.reload();
        await expect(grid).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('notif-pref-cell-kb_doc_created-in_app-toggle')).not.toBeChecked();
    });

    // R13: failure injection — the happy path above already exercises
    // the real PUT round-trip; this scenario covers the R14
    // "surface failures loudly" contract on the save-error path.
    test('panel surfaces save-error when /api/notifications/preferences PUT returns 500', async ({ page }) => {
        await page.route('**/api/notifications/preferences', async (route) => {
            if (route.request().method() === 'PUT') {
                await route.fulfill({
                    status: 500,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'simulated server error' }),
                });
                return;
            }
            await route.continue();
        });

        await page.goto('/app/admin/notifications/preferences');

        const grid = page.getByTestId('notif-pref');
        await expect(grid).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // Flip any cell to mark the grid dirty.
        await page.getByTestId('notif-pref-cell-kb_doc_created-in_app-toggle').click();
        await page.getByTestId('notif-pref-save').click();

        await expect(page.getByTestId('notif-pref-save-error')).toBeVisible({ timeout: 10_000 });
    });
});
