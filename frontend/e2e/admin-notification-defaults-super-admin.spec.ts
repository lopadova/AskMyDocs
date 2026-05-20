import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.0/W2.3 — Tenant notification defaults grid E2E.
 *
 * The PUT path is super-admin-only (route group ACL admits both
 * admin and super-admin to the read, but the controller rejects
 * non-super-admin on update with 403). This spec runs under the
 * `chromium-super-admin` project (storage state seeded by
 * `super-admin.setup.ts`) so the round-trip exercises the real
 * 200 path end-to-end.
 *
 * R12 — user-facing UI change ships Playwright coverage in the same
 * PR. R13 — happy path runs against the real Laravel back-end + DB;
 * the failure scenario carries the marker comment + Playwright
 * `route` interception (parentheses elided so the verify-e2e-real-data
 * script doesn't false-match this docblock).
 *
 * Scenarios:
 *   1. happy: super-admin loads the grid, flips a cell, saves; on
 *      reload the cell stays at its new state (verified by re-reading
 *      from the real BE).
 *   2. R13 failure injection: force PUT 500 and assert the
 *      save-error banner surfaces (R14 — no silent fallback).
 */

test.describe('Tenant notification defaults grid — super-admin', () => {
    test('flips a default cell + saves; persists across reload', async ({ page }) => {
        await page.goto('/app/admin/notifications/defaults');

        const grid = page.getByTestId('notif-defaults');
        await expect(grid).toBeVisible({ timeout: 15_000 });
        await expect(grid).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // Pick a deterministic registered-channel cell. `in_app` is
        // always registered; `kb_doc_created` is the first event type
        // the BE returns.
        const cell = page.getByTestId('notif-defaults-cell-kb_doc_created-in_app-toggle');
        await expect(cell).toBeVisible();

        // Platform default seeds in_app=true; flip it off so the
        // tenant override now overrides the platform fallback.
        await expect(cell).toBeChecked();
        await cell.click();
        await expect(cell).not.toBeChecked();
        await expect(page.getByTestId('notif-defaults-dirty-indicator')).toBeVisible();

        await page.getByTestId('notif-defaults-save').click();
        await expect(page.getByTestId('notif-defaults-save-success')).toBeVisible({ timeout: 10_000 });

        await page.reload();
        await expect(grid).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(
            page.getByTestId('notif-defaults-cell-kb_doc_created-in_app-toggle'),
        ).not.toBeChecked();
    });

    // R13: failure injection — happy path above already runs against
    // the real BE; this scenario forces a 500 and asserts R14
    // "surface failures loudly".
    test('defaults grid surfaces save-error when PUT /api/admin/notifications/defaults returns 500', async ({ page }) => {
        await page.route('**/api/admin/notifications/defaults', async (route) => {
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

        await page.goto('/app/admin/notifications/defaults');

        const grid = page.getByTestId('notif-defaults');
        await expect(grid).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        await page.getByTestId('notif-defaults-cell-kb_doc_created-in_app-toggle').click();
        await page.getByTestId('notif-defaults-save').click();

        await expect(page.getByTestId('notif-defaults-save-error')).toBeVisible({ timeout: 10_000 });
    });
});
