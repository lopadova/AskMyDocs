import { test as baseTest, expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/*
 * v8.x — Native Invitations admin.
 *
 * The invitations admin is now an in-app tabbed surface over the core
 * `padosoft/laravel-invitations` API (Overview · Codes · Referrals · Rewards ·
 * Waitlist · Anti-abuse) — it no longer bounces to the standalone package panel
 * by default. The self-contained panel is only offered as an "Advanced" link
 * when INVITATIONS_ADMIN_ENABLED is true (false in CI → the link is absent, so
 * it never points at the unregistered /admin/invitations 404; R14/R43).
 *
 * Two `test` instances on purpose (mirrors admin-flows.spec.ts):
 *   - `seededTest` runs the `seeded` auto-fixture (resetDb + DemoSeeder +
 *     per-test admin re-login) before every admin test.
 *   - `baseTest` reuses the viewer storage state for the RBAC-denied scenario.
 *
 * R13: real backend, real seeders, real Sanctum cookies. The codes happy path
 * GENERATES codes live against the real core API (a real write), then reads
 * them back — no stub on any internal route. The single failure test is an
 * explicit injection against the metrics probe (R13: failure injection).
 */

seededTest.describe('Admin Invitations — native admin', () => {
    seededTest('happy — sidebar opens the native tabbed page; overview settles ready; no dead Advanced link', async ({
        page,
    }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        const navButton = page.getByRole('button', { name: 'Invitations', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        await expect(page).toHaveURL(/\/admin\/invitations/);
        await expect(page.getByTestId('admin-invitations')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-invitations-tab-overview')).toHaveAttribute('aria-selected', 'true');

        // REAL-DATA assertion (R13): the live metrics probe hits the real core
        // API; a freshly-seeded tenant has all-zero counts — a valid funnel, not
        // an error. Overview must settle to 'ready', never 'error'.
        await expect(page.getByTestId('admin-invitations-overview')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('kpi-card-codes-issued')).toBeVisible();
        await expect(page.getByTestId('admin-invitations-funnel')).toBeVisible();

        // R43 OFF / R14: with INVITATIONS_ADMIN_ENABLED=false (the CI default) the
        // package panel is unmounted, so the Advanced launcher must NOT render.
        await expect(page.getByTestId('admin-invitations-open-panel')).toHaveCount(0);
    });

    seededTest('codes — live generate writes real codes and offers CSV export (R13 real write)', async ({ page }) => {
        await page.goto('/app/admin/invitations');
        await expect(page.getByTestId('admin-invitations')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('admin-invitations-tab-codes').click();
        await expect(page.getByTestId('admin-invitations-panel-codes')).toBeVisible();

        await page.getByTestId('admin-invitations-codes-generate-open').click();
        await page.getByTestId('admin-invitations-codes-generate-count').fill('2');
        await page.getByTestId('admin-invitations-codes-generate-submit').click();

        // Real POST /api/admin/invitations/codes → the generated batch renders.
        await expect(page.getByTestId('admin-invitations-codes-generate-result')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-invitations-codes-generate-csv')).toBeVisible();

        await page.getByTestId('admin-invitations-codes-generate-done').click();
        // Inventory now reads the freshly-written codes back from the DB.
        await expect(page.getByTestId('admin-invitations-codes-table')).toBeVisible({ timeout: 15_000 });
    });

    seededTest('read tabs render a settled (non-error) state for a fresh tenant', async ({ page }) => {
        await page.goto('/app/admin/invitations');
        await expect(page.getByTestId('admin-invitations')).toBeVisible({ timeout: 15_000 });

        for (const tab of ['referrals', 'rewards', 'waitlist', 'abuse'] as const) {
            await page.getByTestId(`admin-invitations-tab-${tab}`).click();
            const container = page.getByTestId(`admin-invitations-${tab}`);
            await expect(container).toBeVisible({ timeout: 15_000 });
            // Fresh tenant → empty; must never be the error branch.
            await expect(container).toHaveAttribute('data-state', /empty|ready/, { timeout: 15_000 });
        }
    });

    seededTest('campaigns — live create writes a real campaign (R13 real write)', async ({ page }) => {
        await page.goto('/app/admin/invitations');
        await expect(page.getByTestId('admin-invitations')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('admin-invitations-tab-campaigns').click();
        await page.getByTestId('admin-invitations-campaigns-new').click();
        await expect(page.getByTestId('admin-invitations-campaign-drawer')).toBeVisible();

        await page.getByTestId('admin-invitations-campaign-key').fill('e2e-launch');
        await page.getByTestId('admin-invitations-campaign-name').fill('E2E Launch');
        await page.getByTestId('admin-invitations-campaign-submit').click();

        // Real POST /campaigns → drawer closes, the list refetches, the row shows.
        await expect(page.getByTestId('admin-invitations-campaigns-table')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText('E2E Launch')).toBeVisible();
    });

    seededTest('invite — live send records a session row (R13 real write)', async ({ page }) => {
        await page.goto('/app/admin/invitations');
        await expect(page.getByTestId('admin-invitations')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('admin-invitations-tab-invite').click();
        await page.getByTestId('admin-invitations-invite-open').click();
        await page.getByTestId('admin-invitations-invite-recipient').fill('e2e-invitee@example.com');
        await page.getByTestId('admin-invitations-invite-submit').click();

        // Real POST /invitations → the session list shows the sent invitation.
        await expect(page.getByTestId('admin-invitations-invites-table')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByText('e2e-invitee@example.com')).toBeVisible();
    });

    seededTest(
        // R13: failure injection — the happy path above covers the real-data flow.
        'failure — metrics probe 503 surfaces the overview error banner (R13: failure injection)',
        async ({ page }) => {
            await page.route('**/api/admin/invitations/metrics', (route) =>
                route.fulfill({ status: 503, body: 'Service Unavailable' }),
            );

            await page.goto('/app/admin/invitations');
            await expect(page.getByTestId('admin-invitations-overview')).toBeVisible({ timeout: 15_000 });
            await expect(page.getByTestId('admin-invitations-overview')).toHaveAttribute('data-state', 'error', {
                timeout: 15_000,
            });
            await expect(page.getByTestId('admin-invitations-overview-error')).toBeVisible();
            // No KPI grid leaks a NaN/0 in the error branch — the funnel is gone.
            await expect(page.getByTestId('admin-invitations-funnel')).toHaveCount(0);
        },
    );
});

baseTest.describe('Admin Invitations — viewer (RBAC denied)', () => {
    baseTest.use({ storageState: 'playwright/.auth/viewer.json' });

    baseTest('viewer hitting /app/admin/invitations sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/invitations');
        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-invitations')).toHaveCount(0);
    });

    /*
     * R13: failure injection — the BE master switch + manageInvitations gate are
     * the load-bearing defence (the FE RequireRole is a UX convenience). This
     * hits the real package mount URL, covering BOTH env=false (404 — the mount
     * stays unregistered) and env=true + non-allowed-role (403) without
     * branching on env.
     */
    baseTest('viewer GET /admin/invitations rejects with 4xx (R13: failure injection on package mount URL)', async ({
        request,
    }) => {
        const response = await request.get('/admin/invitations');
        expect([403, 404]).toContain(response.status());
    });
});
