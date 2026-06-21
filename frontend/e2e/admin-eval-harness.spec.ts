import { test as baseTest, expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/*
 * v4.4/W3 — Admin Eval Harness UI dashboard mount
 * (padosoft/eval-harness-ui v0.1.0).
 *
 * Switched from iframe (v4.2/W4 sub-PR 7) to cross-mount: the
 * package's React tree renders directly inside the host TanStack
 * shell (see EvalHarnessView.tsx + ADR 0005). The host wrapper now
 * carries `data-mount="cross-mount"` instead of an `<iframe>` and
 * scenarios drive the cross-mounted SPA via page-level testids
 * rather than `frameLocator(...)`.
 *
 * Two separate `test` instances on purpose (mirrors
 * admin-pii-redactor.spec.ts and admin-flows.spec.ts):
 *
 *   - `seededTest` (from './fixtures') runs the `seeded` auto-fixture
 *     before every admin test → resetDb + DemoSeeder + per-test
 *     re-login. Required for the admin happy path because earlier
 *     specs in the chromium project may have left the DB in a state
 *     where admin's session cookie is invalidated (Laravel's
 *     password_<id> hash check).
 *   - `baseTest` (from '@playwright/test') skips the auto-fixture for
 *     the viewer-RBAC scenarios. The viewer.json storage state is
 *     created once by viewer.setup.ts and would be invalidated if a
 *     spec in this file ran resetDb under a viewer body.
 *
 * Three fail-closed fences (PRESERVED across the cross-mount):
 *   - env=false default → package controller 404
 *   - APP_ENV=production → host non-prod middleware 404
 *   - viewer / anonymous → Gate 403
 *
 * R13 compliance: real backend, real seeders, real Sanctum cookies.
 * No `page.route` interception — the BE responses ARE the assertion.
 */

seededTest.describe('Admin Eval Harness — admin (cross-mount + nav)', () => {
    seededTest('happy — sidebar entry routes to admin/eval-harness and degrades cleanly when the data API is unwired', async ({
        page,
    }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        // Sidebar entry exposes the section. Label is 'Eval Harness' (R11).
        const navButton = page.getByRole('button', { name: 'Eval Harness', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        await expect(page).toHaveURL(/\/admin\/eval-harness$/);
        const host = page.getByTestId('admin-eval-harness-host');
        await expect(host).toBeVisible({ timeout: 15_000 });
        await expect(host).toHaveAttribute('data-mount', 'cross-mount');

        // EVAL_HARNESS_UI_ENABLED is off by default in CI/dev, so the package's
        // data API is unwired — the host probes it and shows a single clean
        // "unavailable" landing instead of mounting the cross-mount and letting
        // it render a storm of error panels / a raw 500 (the hardening that
        // makes the feature safe whether the flag is on OR off). data-state
        // settles to 'unavailable', the SPA does NOT mount.
        await expect(host).toHaveAttribute('data-state', 'unavailable');
        await expect(page.getByTestId('admin-eval-harness-unavailable')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-eval-harness-app')).toHaveCount(0);
    });
});

baseTest.describe('Admin Eval Harness — viewer (RBAC denied)', () => {
    baseTest.use({ storageState: 'playwright/.auth/viewer.json' });

    baseTest('viewer hitting /app/admin/eval-harness sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/eval-harness');

        // Same RequireRole pattern as the rest of the admin SPA — the
        // route gate filters before the cross-mount ever mounts, so
        // the viewer never even sees the package SPA.
        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-eval-harness-host')).toHaveCount(0);
    });

    /*
     * R13: failure injection — the BE-level three fences are the
     * load-bearing defence (the FE RequireRole is a UX convenience).
     * This call exercises the real BE route, not a stub, so it covers
     * the three independent fences:
     *   - env=false default → package controller 404
     *   - APP_ENV=production → host non-prod middleware 404
     *   - viewer role + env=true + non-prod → Gate 403
     * without branching the test on env.
     */
    baseTest('viewer GET /admin/eval-harness rejects with 4xx (R13: failure injection on package mount URL)', async ({
        request,
    }) => {
        const response = await request.get('/admin/eval-harness');

        // 404 when env=false (default in CI/dev — package controller
        // aborts), 404 in production (non-prod middleware aborts), 403
        // when env=true + viewer role. Either status proves the
        // package dashboard is NOT exposed to a viewer.
        expect([403, 404]).toContain(response.status());
    });
});
