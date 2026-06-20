import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.0/W1.4 — Notification bell + panel E2E.
 *
 * R12 — user-facing UI changes ship Playwright coverage in the same
 * PR. R13 — happy paths run against the real backend; the failure
 * scenario carries the marker comment + an explicit injection.
 *
 * Scenarios:
 *   1. bell renders with `data-state=ready` after the unread-count
 *      query settles (real /api/notifications/unread-count).
 *   2. "See all" link routes to the team-scoped
 *      /app/{teamHash}/admin/notifications and renders
 *      the empty panel (DemoSeeder ships no notifications, so the
 *      default unread tab is `data-state=empty`).
 *   3. R13: failure injection — force /api/notifications to 500 and
 *      assert the panel's error state + retry are surfaced (R14 —
 *      no silent fallback).
 */

test.describe('Notification bell + panel', () => {
    test('bell reaches data-state=ready against the real unread-count endpoint', async ({ page }) => {
        await page.goto('/app/admin');

        const bell = page.getByTestId('notif-bell');
        await expect(bell).toBeVisible({ timeout: 15_000 });
        await expect(bell).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // DemoSeeder ships zero notification_events rows, so the
        // unread badge must NOT be present (count = 0).
        await expect(page.getByTestId('notif-bell-badge')).toHaveCount(0);
    });

    test('see-all link navigates to /app/admin/notifications and renders empty panel', async ({ page }) => {
        await page.goto('/app/admin');

        await expect(page.getByTestId('notif-bell')).toBeVisible({ timeout: 15_000 });
        await page.getByTestId('notif-bell').click();

        const dropdown = page.getByTestId('notif-bell-dropdown');
        await expect(dropdown).toBeVisible();
        await expect(page.getByTestId('notif-bell-empty')).toBeVisible();

        await page.getByTestId('notif-bell-see-all').click();
        // v8.17 made every authed route team-scoped: /app/{teamHash}/admin/….
        // Match the page suffix so the assertion tolerates the team-hash
        // segment (same convention as the other admin specs' toHaveURL).
        await page.waitForURL(/\/admin\/notifications$/);

        const panel = page.getByTestId('notif-panel');
        await expect(panel).toBeVisible();
        await expect(panel).toHaveAttribute('data-state', 'empty', { timeout: 10_000 });
        await expect(page.getByTestId('notif-panel-empty')).toBeVisible();
    });

    // R13: failure injection — internal route intercept is permitted
    // because the happy paths above already exercise the real /api/
    // notifications/* stack. This scenario covers the R14 "surface
    // failures loudly" contract on the panel's error path.
    test('panel surfaces error state when /api/notifications returns 500', async ({ page }) => {
        await page.route('**/api/notifications?**', async (route) => {
            await route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'simulated' }),
            });
        });

        await page.goto('/app/admin/notifications');

        const panel = page.getByTestId('notif-panel');
        await expect(panel).toBeVisible({ timeout: 15_000 });
        await expect(panel).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        await expect(page.getByTestId('notif-panel-error')).toBeVisible();
        await expect(page.getByTestId('notif-panel-retry')).toBeVisible();
    });
});
