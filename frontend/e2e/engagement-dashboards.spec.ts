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
});
