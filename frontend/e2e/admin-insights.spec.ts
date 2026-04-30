import { expect } from '@playwright/test';
import { test } from './fixtures';
import { loginAsProjectUser, resetDb, seedDb } from './setup-helpers';

/*
 * PR14 — Phase I. Admin AI insights scenarios.
 *
 * Runs against the REAL backend seeded with DemoSeeder + AdminInsightsSeeder.
 * The insights snapshot is pre-computed in the DB via the seeder —
 * the SPA reads the snapshot row directly; zero LLM calls happen on
 * the E2E path on the happy scenarios (R13).
 *
 * Failure scenarios that need a 5xx use request interception and
 * flag the stub with the `R13: failure injection` marker comment.
 */

test.describe('Admin AI Insights — Phase I', () => {
    test('happy — insights view renders 6 cards from the seeded snapshot', async ({
        page,
        request,
    }) => {
        // Seed one snapshot in the DB so the view has deterministic
        // data — DemoSeeder doesn't include an insights row by default.
        await seedDb(request, 'AdminInsightsSeeder');

        await page.goto('/app/admin/insights');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('insights-view')).toBeVisible();
        await expect(page.getByTestId('insights-view')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Every one of the 6 widget cards renders.
        await expect(page.getByTestId('insight-card-promotions')).toBeVisible();
        await expect(page.getByTestId('insight-card-orphans')).toBeVisible();
        await expect(page.getByTestId('insight-card-suggested-tags')).toBeVisible();
        await expect(page.getByTestId('insight-card-coverage-gaps')).toBeVisible();
        await expect(page.getByTestId('insight-card-stale-docs')).toBeVisible();
        await expect(page.getByTestId('insight-card-quality')).toBeVisible();

        // Highlight strip reflects the seeded counts.
        await expect(page.getByTestId('insights-highlights')).toBeVisible();
    });

    test('failure — no snapshot yet surfaces the empty state', async ({ page, context, request }, testInfo) => {
        // Reset to baseline DemoSeeder (no snapshot row) and navigate.
        // /testing/reset wipes the DB before DemoSeeder re-runs (via
        // the fixtures autorun), so by the time we land here there is
        // NO admin_insights_snapshots row. The mid-test migrate:fresh
        // also invalidates the session set up by the auto-fixture
        // (DemoSeeder re-creates admin@demo.local with a fresh bcrypt
        // hash), so re-login before navigating or RequireAuth bounces
        // the SPA to /login.
        await resetDb(request);
        await seedDb(request, 'DemoSeeder');
        await loginAsProjectUser(page, context, request, testInfo.project.name);

        await page.goto('/app/admin/insights');
        await expect(page.getByTestId('insights-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('insights-view')).toHaveAttribute('data-state', 'empty', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('insights-no-snapshot')).toBeVisible();
    });

    test('failure — 500 on /latest surfaces the error state', async ({ page }) => {
        /* R13: failure injection — real path tested in "happy — insights view renders". */
        await page.route('**/api/admin/insights/latest', (route) =>
            route.fulfill({
                status: 500,
                body: '{"message":"boom"}',
                contentType: 'application/json',
            }),
        );

        await page.goto('/app/admin/insights');
        await expect(page.getByTestId('insights-view')).toHaveAttribute('data-state', 'error', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('insights-error')).toBeVisible();
    });
});
