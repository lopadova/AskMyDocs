import { test as baseTest, expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/*
 * v8.x — Admin Invitations panel cross-mount
 * (padosoft/laravel-invitations-admin v1.0.0).
 *
 * Two separate `test` instances on purpose (mirrors admin-flows.spec.ts):
 *
 *   - `seededTest` (from './fixtures') runs the `seeded` auto-fixture before
 *     every admin test → resetDb + DemoSeeder + per-test re-login. Required
 *     for the admin happy path because earlier specs in the chromium project
 *     may have invalidated admin's session cookie.
 *   - `baseTest` (from '@playwright/test') skips the auto-fixture for the
 *     viewer-RBAC scenario, which reuses the viewer.json storage state.
 *
 * The package's self-contained React panel (campaigns / codes / invitations /
 * referrals / rewards / waitlist / anti-abuse / settings) is served over a
 * gated Blade route at /admin/invitations and opens STANDALONE in a new tab
 * (its own chrome — same mount strategy as the Flow cockpit). The host page is
 * a native, center-only landing: an "Open Invitations admin" launcher + live
 * funnel KPIs read from the SAME core API (/api/admin/invitations/metrics, the
 * route PR #363 mounted).
 *
 * R13 compliance: real backend, real seeders, real Sanctum cookies — the happy
 * path asserts the REAL core metrics response renders. The one failure test is
 * an explicit injection (R13: failure injection) against the probe so the error
 * banner is exercised without depending on the metrics being non-empty.
 */

seededTest.describe('Admin Invitations — admin (mount + nav + real funnel)', () => {
    seededTest('happy — sidebar entry navigates to admin/invitations and renders the host + live funnel', async ({
        page,
    }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        // Sidebar entry → routes to /app/$teamHash/admin/invitations. Find by
        // accessible name (R11).
        const navButton = page.getByRole('button', { name: 'Invitations', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        // The host's data-testid="admin-invitations-host" is unconditional —
        // independent of the metrics roundtrip — so the assertion doesn't race
        // the fetch.
        await expect(page).toHaveURL(/\/admin\/invitations$/);
        await expect(page.getByTestId('admin-invitations-host')).toBeVisible({ timeout: 15_000 });

        // The _blank launcher to the standalone panel is always present
        // (independent of INVITATIONS_ADMIN_ENABLED, which defaults false in CI).
        const openPanel = page.getByTestId('admin-invitations-open-panel');
        await expect(openPanel).toBeVisible();
        await expect(openPanel).toHaveAttribute('href', '/admin/invitations');
        await expect(openPanel).toHaveAttribute('target', '_blank');

        // REAL-DATA assertion (R13): the live funnel probe hits the real core
        // API and the host resolves to the ready state with the KPI grid
        // rendered (a freshly-seeded tenant has 0 codes — a valid funnel, not an
        // error). data-state must settle to 'ready', never 'error'.
        await expect(page.getByTestId('admin-invitations-host')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('admin-invitations-kpi-codes')).toBeVisible();
        await expect(page.getByTestId('admin-invitations-error')).toHaveCount(0);
    });

    seededTest(
        // R13: failure injection — the happy path above covers the real-data
        // flow. This injects a 503 from the metrics probe so the error banner is
        // exercised deterministically; the open-panel launcher must remain.
        'failure — metrics probe returns 503 → error banner visible, open-panel still reachable (R13: failure injection)',
        async ({ page }) => {
            // R13: failure injection — intercept before navigating so the fetch
            // racing the component mount is caught deterministically.
            await page.route('**/api/admin/invitations/metrics', (route) =>
                route.fulfill({ status: 503, body: 'Service Unavailable' }),
            );

            await page.goto('/app/admin/invitations');
            await expect(page.getByTestId('admin-invitations-host')).toBeVisible({ timeout: 15_000 });

            await expect(page.getByTestId('admin-invitations-error')).toBeVisible();
            await expect(page.getByTestId('admin-invitations-host')).toHaveAttribute('data-state', 'error');

            // The launcher MUST still be reachable when the probe is down.
            await expect(page.getByTestId('admin-invitations-open-panel')).toBeVisible();
            // KPI grid must NOT render in the error state (no NaN/0 leak).
            await expect(page.getByTestId('admin-invitations-kpi-codes')).toHaveCount(0);
        },
    );
});

baseTest.describe('Admin Invitations — viewer (RBAC denied)', () => {
    baseTest.use({ storageState: 'playwright/.auth/viewer.json' });

    baseTest('viewer hitting /app/admin/invitations sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/invitations');

        // RequireRole filters before the host landing mounts — the viewer never
        // sees the panel URL.
        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-invitations-host')).toHaveCount(0);
    });

    /*
     * R13: failure injection — the BE master switch + manageInvitations gate are
     * the load-bearing defence (the FE RequireRole is a UX convenience). This
     * hits the real package mount URL, covering BOTH the env=false default
     * (404 — invitations-admin.enabled gate keeps routes unregistered) and the
     * env=true + non-allowed-role case (403 from can:manageInvitations) without
     * branching on env.
     */
    baseTest('viewer GET /admin/invitations rejects with 4xx (R13: failure injection on package mount URL)', async ({
        request,
    }) => {
        const response = await request.get('/admin/invitations');
        expect([403, 404]).toContain(response.status());
    });
});
