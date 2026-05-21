import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.0/W2.4 — Scheduler status widget E2E.
 *
 * R12 — the W2.4 refactor moved scheduler cron literals from
 * `bootstrap/app.php` into `config('askmydocs.schedule')`, and
 * `MaintenanceCommandController::schedulerStatus()` now derives its
 * response from that config + the `TierOneSchedulerRegistrar` slot
 * list. Both ends of the contract feed the React
 * `SchedulerStatusCard` widget, so the user-visible surface ships
 * Playwright coverage in the same PR.
 *
 * R13 — both scenarios run against the real Laravel back-end + SQLite
 * database; no Playwright `route` interception of internal routes
 * (parentheses elided so the verify-e2e-real-data script doesn't
 * false-match this docblock). The happy path verifies the widget
 * renders rows in `ready` data-state with HH:MM cron times; a
 * single API-level check confirms the new `cron_expression` field
 * is present so the contract extension is pinned end-to-end.
 */

test.describe('Admin Maintenance — scheduler status widget', () => {
    test('renders the daily schedule rows in HH:MM cron_time format', async ({ page }) => {
        await page.goto('/app/admin/maintenance');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('scheduler-status')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('scheduler-status')).toHaveAttribute(
            'data-state',
            'ready',
            { timeout: 15_000 },
        );

        // `kb-prune-deleted` ships in the default Tier-1 slot list and
        // defaults to `30 3 * * *` → `03:30` HH:MM. The 60px column
        // in `SchedulerStatusCard` is sized for exactly this format.
        const row = page.getByTestId('scheduler-row-kb:prune-deleted');
        await expect(row).toBeVisible();
        await expect(row).toContainText('03:30');
    });

    test('GET /scheduler-status includes the new cron_expression field per row', async ({ request }) => {
        const resp = await request.get('/api/admin/commands/scheduler-status');
        expect(resp.ok()).toBeTruthy();

        const body = await resp.json();
        expect(Array.isArray(body.data)).toBeTruthy();
        expect(body.data.length).toBeGreaterThan(0);

        for (const row of body.data) {
            expect(row).toHaveProperty('command');
            expect(row).toHaveProperty('cron_time');
            expect(row).toHaveProperty('cron_expression');
            expect(row).toHaveProperty('description');
            // `cron_time` is `HH:MM` for daily-at-fixed-time slots.
            // For non-daily schedules, the API falls back to the raw
            // 5-field cron expression.
            expect(row.cron_time).toMatch(/^(\d{2}:\d{2}|\S+(\s+\S+){4})$/);
            expect(row.cron_expression).toMatch(/^\S+(\s+\S+){4}$/);
        }
    });

    // R13: failure injection — the happy path above already exercises
    // the real backend round-trip; this scenario forces a 500 on the
    // scheduler-status endpoint and asserts the widget surfaces
    // `data-state="error"` (R14 — no silent fallback).
    test('scheduler-status widget surfaces error state when /scheduler-status returns 500', async ({ page }) => {
        await page.route('**/api/admin/commands/scheduler-status', async (route) => {
            await route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'simulated server error' }),
            });
        });

        await page.goto('/app/admin/maintenance');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        const widget = page.getByTestId('scheduler-status');
        await expect(widget).toBeVisible({ timeout: 15_000 });
        await expect(widget).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        await expect(widget).toContainText(/Unable to load schedule/i);
    });
});
