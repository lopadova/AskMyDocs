import { expect } from '@playwright/test';
import { test } from './fixtures';
import { loginAsProjectUser, resetDb, seedDb } from './setup-helpers';

/*
 * PR6 — Phase F1. Admin Dashboard E2E scenarios.
 *
 * These run under the `chromium` project (admin storage state + the
 * auto-`seeded` fixture that runs DemoSeeder before each test).
 *
 * R13 compliance: every happy-path scenario hits the REAL backend
 * and real DemoSeeder data — NO request interception against internal
 * endpoints on the primary path. The ONLY stubbed scenario carries
 * the `R13: failure injection` marker comment on one of the five
 * preceding lines (see scripts/verify-e2e-real-data.sh).
 *
 * Scenarios:
 *   1. renders KPIs + health + charts from real seeded data
 *   2. metrics 500 — injected failure — KPI tiles surface data-state=error
 *   3. empty state — EmptyAdminSeeder — every chart shows <chart>-empty
 *   4. health probe content matches the backend response shape
 */

test.describe('Admin Dashboard', () => {
    test('renders KPIs + health + charts from real seeded data', async ({ page }) => {
        await page.goto('/app/admin');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-dashboard')).toBeVisible();

        // KPI tiles reach `ready` once the TanStack Query settles.
        const kpiStrip = page.getByTestId('kpi-strip');
        await expect(kpiStrip).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        for (const slug of ['docs', 'chunks', 'chats', 'latency', 'cache', 'coverage']) {
            await expect(page.getByTestId(`kpi-card-${slug}`)).toHaveAttribute('data-state', 'ready');
        }

        // Health rolls up to `ok` on a clean demo stack. sqlite in tests
        // short-circuits pgvector_ok to `ok`, failed_jobs = 0 so queue_ok
        // is `ok`, and provider checks pass because config/ai.php has
        // both default_provider + embeddings_provider populated in
        // .env.example (which APP_ENV=testing inherits).
        const health = page.getByTestId('dashboard-health');
        await expect(health).toHaveAttribute('data-state', 'ok', { timeout: 10_000 });
        for (const concern of ['db', 'pgvector', 'queue', 'kb-disk', 'embeddings', 'chat']) {
            await expect(page.getByTestId(`health-${concern}`)).toBeVisible();
        }

        // Charts render populated — ChatVolume area + TokenBurn bar +
        // RatingDonut pie. Each card carries its own `data-state`.
        for (const chart of ['chat-volume', 'token-burn', 'rating']) {
            const card = page.getByTestId(`chart-card-${chart}`);
            await expect(card).toBeVisible();
            // State is either `ready` (has seeded data) or `empty` (e.g.
            // rating donut with no rated messages yet). Never `loading`
            // or `error` on the happy path.
            const state = await card.getAttribute('data-state');
            expect(['ready', 'empty']).toContain(state);
        }

        // Recharts rendered an SVG into the ready cards — lazy-loaded
        // chunk resolved. Assert on at least one visible <svg> under
        // a ready card so this test fails if lazy-loading is broken.
        const chatVolume = page.getByTestId('chart-card-chat-volume');
        if ((await chatVolume.getAttribute('data-state')) === 'ready') {
            await expect(chatVolume.locator('svg').first()).toBeVisible({ timeout: 10_000 });
        }

        // Top projects + activity feed are static (non-recharts) cards.
        await expect(page.getByTestId('top-projects-card')).toBeVisible();
        await expect(page.getByTestId('activity-feed-card')).toBeVisible();
    });

    test('metrics 500 injection — KPI tiles surface data-state=error', async ({ page }) => {
        /* R13: failure injection — real path tested in "renders KPIs + health + charts from real seeded data". */
        await page.route('**/api/admin/metrics/**', (r) => r.fulfill({ status: 500 }));

        await page.goto('/app/admin');

        // React Query is configured with `retry: 3` by default but the
        // TanStack Query client in this project disables retries on
        // error for admin queries (see query-client.ts). The tile
        // surfaces `error` promptly.
        const kpiStrip = page.getByTestId('kpi-strip');
        await expect(kpiStrip).toHaveAttribute('data-state', 'error', { timeout: 30_000 });
        const chatVolume = page.getByTestId('chart-card-chat-volume');
        await expect(chatVolume).toHaveAttribute('data-state', 'error', { timeout: 30_000 });
    });

    test('empty state — every chart shows <chart>-empty', async ({ page, context, request }, testInfo) => {
        // Override the default seeded fixture: start from scratch and
        // apply the Empty seeder so no chat logs / canonical docs / chunks
        // exist. TanStack Query keys are independent from the network
        // interception — this is pure real-data testing.
        // The mid-test migrate:fresh invalidates the session set up by
        // the auto-fixture (EmptyAdminSeeder re-creates admin@demo.local
        // with a fresh bcrypt hash), so re-login before navigating or
        // RequireAuth bounces the SPA to /login.
        await resetDb(request);
        await seedDb(request, 'EmptyAdminSeeder');
        await loginAsProjectUser(page, context, request, testInfo.project.name);

        await page.goto('/app/admin');

        await expect(page.getByTestId('kpi-strip')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // With zero data, every chart card switches to the `empty`
        // branch. The EmptyChart svg carries `<chart>-empty` testid.
        await expect(page.getByTestId('chart-card-chat-volume')).toHaveAttribute('data-state', 'empty', { timeout: 15_000 });
        await expect(page.getByTestId('chat-volume-empty')).toBeVisible();
        await expect(page.getByTestId('chart-card-token-burn')).toHaveAttribute('data-state', 'empty');
        await expect(page.getByTestId('token-burn-empty')).toBeVisible();
        await expect(page.getByTestId('chart-card-rating')).toHaveAttribute('data-state', 'empty');
        await expect(page.getByTestId('rating-empty')).toBeVisible();

        await expect(page.getByTestId('top-projects-empty')).toBeVisible();
        await expect(page.getByTestId('activity-feed-empty')).toBeVisible();
    });

    test('health degraded — failed_jobs threshold flips queue chip', async ({ page, context, request }, testInfo) => {
        // Seed a degraded stack: DemoSeeder + 15 failed_jobs rows.
        // The mid-test migrate:fresh invalidates the session set up by
        // the auto-fixture (AdminDegradedSeeder calls DemoSeeder which
        // re-creates admin@demo.local with a fresh bcrypt hash), so
        // re-login before navigating or RequireAuth bounces the SPA to
        // /login.
        await resetDb(request);
        await seedDb(request, 'AdminDegradedSeeder');
        await loginAsProjectUser(page, context, request, testInfo.project.name);

        await page.goto('/app/admin');

        const health = page.getByTestId('dashboard-health');
        // `down` beats `degraded`, but a clean stack + too many
        // failed_jobs only flips queue to degraded, nothing else.
        await expect(health).toHaveAttribute('data-state', 'degraded', { timeout: 15_000 });
        await expect(page.getByTestId('health-queue')).toHaveAttribute('data-state', 'degraded');
        await expect(page.getByTestId('health-db')).toHaveAttribute('data-state', 'ok');
    });
});
