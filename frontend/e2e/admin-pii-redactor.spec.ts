import { test as baseTest, expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/*
 * v4.2/W4 sub-PR 5 — Admin PII Redactor SPA mount (initial iframe).
 * v4.4/W2 — switched from iframe to cross-mount of the package's
 * React tree (see PiiRedactorView.tsx + ADR 0005). The package's
 * Sun/Moon theme toggle and `dark` local state were dropped because
 * the host already drives `data-theme` on `<html>`.
 *
 * Two separate `test` instances on purpose:
 *   - `seededTest` (from './fixtures') runs the `seeded` auto-fixture
 *     before every admin test → resetDb + DemoSeeder + per-test
 *     re-login. Required for the admin happy path because earlier
 *     specs in the chromium project may have left the DB in a state
 *     where admin's session cookie is invalidated (Laravel's
 *     password_<id> hash check). Same pattern as
 *     admin-dashboard.spec.ts.
 *   - `baseTest` (from '@playwright/test') skips the auto-fixture for
 *     the viewer-RBAC scenarios. The viewer.json storage state is
 *     created once by viewer.setup.ts and would be invalidated if a
 *     spec in this file ran resetDb under a viewer body. Same
 *     pattern as the other admin-*-viewer.spec.ts files.
 *
 * The cross-mounted SPA renders the same 8 sections the iframe
 * predecessor exposed (Overview, Playground, Audit log, Token map,
 * Detokenise, Detectors, Custom rules, Settings) but inside the host
 * React tree — sharing one Sanctum cookie, one axios instance, one
 * theme contract.
 *
 * R13 compliance: real backend, real seeders, real Sanctum cookies.
 * No `page.route` interception — the BE responses ARE the assertion.
 * The package API routes (/admin/pii-redactor/api/*) are NOT
 * registered when `PII_REDACTOR_ADMIN_ENABLED=false` (the CI/dev
 * default), so the cross-mounted Overview surface stays in its
 * loading-defaults shape. The shell + nav still render — that's what
 * we assert here.
 */

seededTest.describe('Admin PII Redactor — admin (cross-mount + nav)', () => {
    seededTest('happy — sidebar entry navigates to admin/pii-redactor and renders the cross-mounted SPA shell', async ({
        page,
    }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        // Sidebar entry exposes the new section. The button label is
        // 'PII Redactor' — find it via the accessible name (R11) and
        // click; it routes to /app/admin/pii-redactor.
        const navButton = page.getByRole('button', { name: 'PII Redactor', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        // Route lands on the host component. The host's
        // data-testid="admin-pii-redactor-host" wraps the cross-mounted
        // tree; data-mount="cross-mount" proves we're on the v4.4/W2
        // path and not the legacy iframe shape (defensive against a
        // partial revert during a rebase).
        await expect(page).toHaveURL(/\/admin\/pii-redactor$/);
        await expect(page.getByTestId('admin-pii-redactor-host')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-pii-redactor-host')).toHaveAttribute(
            'data-mount',
            'cross-mount',
        );

        // The cross-mounted SPA root is unconditional — independent of
        // the package API status. Overview is the default landing
        // page; both the section heading and the sidebar nav live in
        // the host's React tree (no iframe boundary) so we can
        // interrogate them with normal page-level locators.
        await expect(page.getByTestId('admin-pii-redactor-app')).toBeVisible();
        await expect(page.getByTestId('admin-pii-redactor-app')).toHaveAttribute(
            'data-page',
            'overview',
        );
        // Phase 2 (unified-admin): the app is always mounted in embedded
        // mode — the host shell provides the primary rail, so the
        // package's own sidebar is hidden and sections render as a tab
        // strip above the content area.
        await expect(page.getByTestId('admin-pii-redactor-app')).toHaveAttribute(
            'data-embedded',
            'true',
        );
        await expect(page.getByTestId('admin-pii-redactor-tabs')).toBeVisible();
        await expect(page.getByTestId('admin-pii-redactor-overview')).toBeVisible();

        // Sidebar nav buttons render with stable testids per
        // feature-resource-{id} convention (R29). Clicking one flips
        // data-page and reveals the matching panel — proving the
        // hooks-driven navigation survives the cross-mount port.
        const playgroundNav = page.getByTestId('admin-pii-redactor-nav-playground');
        await expect(playgroundNav).toBeVisible();
        await playgroundNav.click();
        await expect(page.getByTestId('admin-pii-redactor-app')).toHaveAttribute(
            'data-page',
            'playground',
        );
        await expect(page.getByTestId('admin-pii-redactor-playground')).toBeVisible();

        // Top-bar shortcut is a second affordance for the same
        // navigation target. The Sun/Moon toggle from the iframe era
        // is intentionally absent — host theme switcher takes over.
        await expect(page.getByTestId('admin-pii-redactor-shortcut-playground')).toBeVisible();
    });

    seededTest(
        // R13: failure injection — the happy path above covers the real-data
        // flow. This test injects a 503 from the package status probe so the
        // error banner is exercised without requiring PII_REDACTOR_ADMIN_ENABLED=true
        // in CI, and verifies the embedded tab strip still renders (R14: error
        // state is surfaced, not silently hidden).
        'failure — status probe returns 503 → error banner visible, embedded tab strip still rendered (R13: failure injection)',
        async ({ page }) => {
            // Intercept the status probe before mounting so it is caught
            // on the very first fetch.
            // R13: failure injection
            await page.route('**/admin/pii-redactor/api/status', (route) =>
                route.fulfill({ status: 503, body: 'Service Unavailable' }),
            );

            await page.goto('/app/admin/pii-redactor');
            await expect(page.getByTestId('admin-pii-redactor-host')).toBeVisible({ timeout: 15_000 });

            // Error banner must surface — not silently hidden.
            await expect(page.getByTestId('admin-pii-redactor-status-error')).toBeVisible();
            await expect(page.getByTestId('admin-pii-redactor-app')).toHaveAttribute(
                'data-status-state',
                'error',
            );

            // Even in error state the embedded tab strip must render so
            // the operator can pivot to another section (e.g. Settings).
            await expect(page.getByTestId('admin-pii-redactor-tabs')).toBeVisible();
        },
    );
});

baseTest.describe('Admin PII Redactor — viewer (RBAC denied)', () => {
    baseTest.use({ storageState: 'playwright/.auth/viewer.json' });

    baseTest('viewer hitting /app/admin/pii-redactor sees admin-forbidden and no cross-mounted shell', async ({
        page,
    }) => {
        await page.goto('/app/admin/pii-redactor');

        // Same RequireRole pattern as the rest of the admin SPA — the
        // route gate filters before the cross-mounted SPA tree ever
        // mounts, so the viewer never even sees the package shell.
        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-pii-redactor-host')).toHaveCount(0);
        await expect(page.getByTestId('admin-pii-redactor-app')).toHaveCount(0);
    });

    /*
     * R13: failure injection — the BE-level Gate is the load-bearing
     * defence (the FE RequireRole is a UX convenience). This call
     * exercises the real BE route, not a stub, so it covers both the
     * env=false default (404 — no routes registered) and the
     * env=true + non-allowed-role case (403 from `can:`) without
     * branching the test on env.
     */
    baseTest('viewer GET /admin/pii-redactor rejects with 4xx (R13: failure injection on package mount URL)', async ({
        request,
    }) => {
        const response = await request.get('/admin/pii-redactor');

        // 404 when env=false (default in CI/dev — no routes registered),
        // 403 when env=true + viewer role. Either status proves the
        // package console is NOT exposed to a viewer.
        expect([403, 404]).toContain(response.status());
    });
});
