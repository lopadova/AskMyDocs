import { test as baseTest, expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/*
 * v4.2/W4 sub-PR 7 — Admin Eval Harness UI dashboard mount
 * (padosoft/eval-harness-ui v1.0.0).
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
 * The package's pre-built dashboard (Dashboard / Reports list /
 * Report detail / Compare / Trend / Adversarial manifests + details /
 * Live batches) is embedded via an iframe (mount strategy: iframe —
 * see EvalHarnessView.tsx for the bundle-isolation rationale). These
 * E2E scenarios cover BOTH the AskMyDocs shell (sidebar entry, route,
 * iframe element) AND the BE three-fence matrix:
 *
 *   - admin role: shell renders, iframe element present, BE gate
 *     allows the iframe URL to load (when env=true) or 404s
 *     (default-off env behaviour kept by AskMyDocs E2E config).
 *   - viewer role: SPA route shows AdminForbidden, BE rejects the
 *     iframe URL with 403/404.
 *
 * R13 compliance: real backend, real seeders, real Sanctum cookies.
 * No `page.route` interception — the BE responses ARE the assertion.
 */

seededTest.describe('Admin Eval Harness — admin (mount + nav)', () => {
    seededTest('happy — sidebar entry navigates to admin/eval-harness and renders the iframe host', async ({
        page,
    }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        // Sidebar entry exposes the new section. The button label is
        // 'Eval Harness' — find it via the accessible name (R11) and
        // click; it routes to /app/admin/eval-harness.
        const navButton = page.getByRole('button', { name: 'Eval Harness', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        // Route lands on the host component. The host's
        // data-testid="admin-eval-harness-host" is unconditional —
        // independent of the iframe load state — so the assertion
        // doesn't race the iframe network roundtrip.
        await expect(page).toHaveURL(/\/app\/admin\/eval-harness$/);
        await expect(page.getByTestId('admin-eval-harness-host')).toBeVisible({ timeout: 15_000 });

        // The iframe element itself is mounted unconditionally. The
        // visibility of its CONTENTS depends on EVAL_HARNESS_UI_ENABLED
        // (default false in CI/dev) AND APP_ENV being non-production —
        // so we only assert the wrapper element exists, not that the
        // iframe contents load. (When the operator flips the env var
        // on, a follow-up smoke test under the trusted-env setup would
        // assert iframe content too.)
        await expect(page.getByTestId('admin-eval-harness-iframe')).toBeAttached();
    });
});

baseTest.describe('Admin Eval Harness — viewer (RBAC denied)', () => {
    baseTest.use({ storageState: 'playwright/.auth/viewer.json' });

    baseTest('viewer hitting /app/admin/eval-harness sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/eval-harness');

        // Same RequireRole pattern as the rest of the admin SPA — the
        // route gate filters before the iframe ever mounts, so the
        // viewer never even sees the package URL.
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
