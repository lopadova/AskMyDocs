import { test, expect } from './fixtures';

/**
 * v8.15/W4.2 — the personal "My KB" dashboard (/app/me) and the admin
 * Engagement panel (/app/admin/engagement). Real backend (R13); the only stub
 * is a failure injection on an internal route, marked below.
 */
test.describe('Engagement dashboards', () => {
    test('personal "My KB" dashboard reaches ready with KPI tiles', async ({ page }) => {
        await page.goto('/app/me');

        const panel = page.getByTestId('me-dashboard');
        await expect(panel).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('kpi-card-me-score')).toBeVisible();
        await expect(page.getByTestId('kpi-card-me-rank')).toBeVisible();
    });

    test('admin engagement panel reaches ready with the leaderboard card', async ({ page }) => {
        await page.goto('/app/admin/engagement');

        const panel = page.getByTestId('admin-engagement');
        await expect(panel).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('admin-engagement-kpis')).toBeVisible();
        await expect(page.getByTestId('admin-engagement-leaderboard')).toBeVisible();
    });

    test('personal dashboard surfaces an error when the API fails (R13: failure injection)', async ({ page }) => {
        // R13: failure injection against an internal route — the happy-path test
        // above already covers the real-data flow.
        await page.route('**/api/me/dashboard*', (route) =>
            route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"boom"}' }),
        );

        await page.goto('/app/me');
        await expect(page.getByTestId('me-dashboard')).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        await expect(page.getByTestId('me-dashboard-error')).toBeVisible();
    });

    test('badges section renders when gamification is on (real-data, default-ON since v8.18, R43)', async ({ page }) => {
        // Gamification is ON by default since v8.18, so CI runs with it enabled:
        // /api/me/badges returns enabled:true and the badges section renders. Real
        // data, no stub. The OFF half of the R43 both-states contract (enabled:false
        // → the section does not render at all) is covered at the unit layer by the
        // MeBadges Vitest + GamificationTest (phpunit) — E2E can't flip the global
        // flag per-test without breaking the insights specs that need it on.
        await page.goto('/app/me');
        await expect(page.getByTestId('me-dashboard')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('me-badges')).toBeVisible();
    });

    test('badges section surfaces an error when its API fails (R13: failure injection)', async ({ page }) => {
        // R13: failure injection against an internal route — the OFF-state test
        // above already covers the real-data flow; the enabled grid is covered by
        // the MeBadges Vitest unit.
        await page.route('**/api/me/badges*', (route) =>
            route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"boom"}' }),
        );

        await page.goto('/app/me');
        await expect(page.getByTestId('me-badges')).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        await expect(page.getByTestId('me-badges-retry')).toBeVisible();
    });
});
