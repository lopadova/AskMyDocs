import { test as baseTest, expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/*
 * v4.2/W4 sub-PR 6 — Admin Flows cockpit mount
 * (padosoft/laravel-flow-admin v1.0.0).
 *
 * Two separate `test` instances on purpose (mirrors
 * admin-pii-redactor.spec.ts):
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
 * The package's pre-built console (Overview / Runs / Run detail /
 * Approvals / Webhook outbox / Definitions / Settings / ⌘K palette)
 * is embedded via an iframe (mount strategy: iframe — see FlowsView.tsx
 * for the Blade+Alpine vs React rationale). These E2E scenarios cover
 * BOTH the AskMyDocs shell (sidebar entry, route, iframe element) AND
 * the BE master-switch + Gate matrix:
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

seededTest.describe('Admin Flows — admin (mount + nav)', () => {
    seededTest('happy — sidebar entry navigates to admin/flows and renders the iframe host', async ({
        page,
    }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        // Sidebar entry exposes the new section. The button label is
        // 'Flows' — find it via the accessible name (R11) and click;
        // it routes to /app/admin/flows.
        const navButton = page.getByRole('button', { name: 'Flows', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        // Route lands on the host component. The host's
        // data-testid="admin-flows-host" is unconditional —
        // independent of the iframe load state — so the assertion
        // doesn't race the iframe network roundtrip.
        await expect(page).toHaveURL(/\/app\/admin\/flows$/);
        await expect(page.getByTestId('admin-flows-host')).toBeVisible({ timeout: 15_000 });

        // Phase 2: the cockpit is no longer iframe-embedded (it brought its
        // own full Blade chrome). The native host landing always exposes the
        // _blank launcher to the standalone cockpit — independent of
        // FLOW_ADMIN_ENABLED (which defaults to false in CI/dev), so this
        // assertion doesn't race the package master-switch.
        const openCockpit = page.getByTestId('admin-flows-open-cockpit');
        await expect(openCockpit).toBeVisible();
        await expect(openCockpit).toHaveAttribute('href', '/admin/flows');
        await expect(openCockpit).toHaveAttribute('target', '_blank');
        // No nested iframe any more.
        await expect(page.locator('iframe')).toHaveCount(0);
    });
});

baseTest.describe('Admin Flows — viewer (RBAC denied)', () => {
    baseTest.use({ storageState: 'playwright/.auth/viewer.json' });

    baseTest('viewer hitting /app/admin/flows sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/flows');

        // Same RequireRole pattern as the rest of the admin SPA — the
        // route gate filters before the iframe ever mounts, so the
        // viewer never even sees the package URL.
        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-flows-host')).toHaveCount(0);
    });

    /*
     * R13: failure injection — the BE-level master switch + Gate are
     * the load-bearing defence (the FE RequireRole is a UX
     * convenience). This call exercises the real BE route, not a
     * stub, so it covers both the env=false default
     * (404 — flow-admin.enabled middleware aborts) and the
     * env=true + non-allowed-role case (403 from `can:viewFlowAdmin`)
     * without branching the test on env.
     */
    baseTest('viewer GET /admin/flows rejects with 4xx (R13: failure injection on package mount URL)', async ({
        request,
    }) => {
        const response = await request.get('/admin/flows');

        // 404 when env=false (default in CI/dev — flow-admin.enabled
        // middleware aborts), 403 when env=true + viewer role. Either
        // status proves the package console is NOT exposed to a viewer.
        expect([403, 404]).toContain(response.status());
    });
});
