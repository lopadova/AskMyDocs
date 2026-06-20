import { test, expect } from './fixtures';

/*
 * v8.18/W4 — Admin AI gamification insights — super-admin scenarios.
 *
 * Routed via the playwright.config.ts `chromium-super-admin` project
 * (testMatch /.*-super-admin\.spec\.ts/) so the storageState
 * playwright/.auth/super-admin.json is materialised by
 * super-admin.setup.ts BEFORE this spec runs. The `seeded` auto-fixture
 * (from ./fixtures) re-seeds DemoSeeder + re-logins as super@demo.local
 * before each test, so each scenario starts from a known baseline.
 *
 * R13: real Laravel + real DB seed, NO internal page.route stubs against
 * /api/admin/* or /api/me/*. The AI provider is config-gated OFF in the
 * testing env (deterministic copy), so the regenerate pipeline writes
 * snapshot rows without any external call — no external stub needed.
 */
test.describe('Admin gamification insights — super-admin', () => {
    test('panel is empty on a fresh seed, then ready after Rigenera', async ({ page }) => {
        await page.goto('/app/admin/engagement');

        const panel = page.getByTestId('admin-gamification-insights');

        // Degraded/empty path: a fresh DemoSeeder corpus has no computed
        // gamification insight yet → the panel shows its empty state, not a
        // crash (R43 OFF/empty half).
        await expect(panel).toHaveAttribute('data-state', 'empty', { timeout: 15_000 });
        await expect(page.getByTestId('admin-gamification-insights-empty')).toBeVisible();

        // Super-admin sees the Rigenera button (plain admins do not — covered
        // by the Vitest unit).
        const regenerate = page.getByTestId('admin-gamification-regenerate');
        await expect(regenerate).toBeVisible();

        // Click it: the mutation POSTs /regenerate, the pipeline computes the
        // tenant insight against the real DB, and onSuccess refetches the
        // query → the panel flips to ready with the narrative.
        await regenerate.click();

        await expect(panel).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('admin-gamification-insights-headline')).toBeVisible();
    });
});
