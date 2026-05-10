import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v4.2/W4 sub-PR 5 — Admin PII Redactor SPA mount.
 *
 * The package's pre-built console (Dashboard, Playground, Token map,
 * Detokenise, Audit logs, Detectors, Custom rules) is embedded via an
 * iframe (mount strategy: iframe — see PiiRedactorView.tsx for the
 * React 19 + Tailwind v4 incompatibility rationale). These E2E
 * scenarios cover BOTH the AskMyDocs shell (sidebar entry, route,
 * iframe element) AND the BE Gate matrix:
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

test.describe('Admin PII Redactor — admin (mount + nav)', () => {
    test('happy — sidebar entry navigates to admin/pii-redactor and renders the iframe host', async ({
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
        // data-testid="admin-pii-redactor-host" is unconditional —
        // independent of the iframe load state — so the assertion
        // doesn't race the iframe network roundtrip.
        await expect(page).toHaveURL(/\/app\/admin\/pii-redactor$/);
        await expect(page.getByTestId('admin-pii-redactor-host')).toBeVisible({ timeout: 15_000 });

        // The iframe element itself is mounted unconditionally. The
        // visibility of its CONTENTS depends on
        // PII_REDACTOR_ADMIN_ENABLED — which defaults to false in
        // CI/dev — so we only assert the wrapper element exists, not
        // that it loads. (When the operator flips the env var on, a
        // follow-up smoke test under the trusted-env setup would
        // assert iframe content too.)
        await expect(page.getByTestId('admin-pii-redactor-iframe')).toBeAttached();
    });
});

test.describe('Admin PII Redactor — viewer (RBAC denied)', () => {
    test.use({ storageState: 'playwright/.auth/viewer.json' });

    test('viewer hitting /app/admin/pii-redactor sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/pii-redactor');

        // Same RequireRole pattern as the rest of the admin SPA — the
        // route gate filters before the iframe ever mounts, so the
        // viewer never even sees the package URL.
        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-pii-redactor-host')).toHaveCount(0);
    });

    /*
     * R13: failure injection — the BE-level Gate is the load-bearing
     * defence (the FE RequireRole is a UX convenience). This call
     * exercises the real BE route, not a stub, so it covers both the
     * env=false default (404 — no routes registered) and the
     * env=true + non-allowed-role case (403 from `can:`) without
     * branching the test on env.
     */
    test('viewer GET /admin/pii-redactor rejects with 4xx (R13: failure injection on package mount URL)', async ({
        request,
    }) => {
        const response = await request.get('/admin/pii-redactor');

        // 404 when env=false (default in CI/dev — no routes registered),
        // 403 when env=true + viewer role. Either status proves the
        // package console is NOT exposed to a viewer.
        expect([403, 404]).toContain(response.status());
    });
});
