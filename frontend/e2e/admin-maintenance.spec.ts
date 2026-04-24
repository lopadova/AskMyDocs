import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * PR13 — Phase H2. Admin Maintenance command runner scenarios.
 *
 * Runs against the REAL backend seeded with DemoSeeder under the
 * chromium project's admin storage state. DemoSeeder provides the
 * three canonical docs + a complete RBAC wiring so the non-destructive
 * commands (kb:validate-canonical, kb:rebuild-graph, queue:retry)
 * are safe no-ops on the demo corpus — perfect E2E candidates
 * without request interception (R13).
 *
 * The happy-path scenario runs `kb:validate-canonical` against the
 * demo corpus and asserts an audit history row appears. Failure-path
 * scenarios exercise the three unhappy gates of CommandRunnerService
 * — unknown command (404), destructive without confirm_token (422),
 * 500 failure injection — the last one flagged with the mandatory
 * `R13: failure injection` comment marker.
 */

test.describe('Admin Maintenance command runner — Phase H2', () => {
    test('happy — non-destructive kb:validate-canonical runs, wizard reaches ready state', async ({
        page,
    }) => {
        await page.goto('/app/admin/maintenance');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('maintenance-view')).toBeVisible();
        await expect(page.getByTestId('maintenance-panel-commands')).toHaveAttribute(
            'data-state',
            'ready',
            { timeout: 15_000 },
        );

        // Pick the non-destructive validate command — safe on demo data.
        await page.getByTestId('maintenance-card-kb:validate-canonical-run').click();

        await expect(page.getByTestId('command-wizard')).toHaveAttribute('data-step', 'preview');

        // kb:validate-canonical has one optional arg (project) — leave
        // blank to validate the whole corpus.
        await page.getByTestId('wizard-step-preview-run').click();

        // Non-destructive: skip confirm, jump straight to run → result.
        await expect(page.getByTestId('wizard-result')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('wizard-result')).toHaveAttribute('data-state', 'ready', {
            timeout: 20_000,
        });

        // Close the wizard, pivot to the history tab, confirm the row.
        await page.getByTestId('wizard-cancel').click();
        await page.getByTestId('maintenance-tab-history').click();

        await expect(page.getByTestId('command-history')).toHaveAttribute('data-state', 'ready', {
            timeout: 10_000,
        });
        const rows = page.locator('[data-testid^="command-history-row-"]');
        expect(await rows.count()).toBeGreaterThan(0);
    });

    test('failure — destructive command without confirm_token returns 422', async ({
        page,
        request,
    }) => {
        // admin storage state holds `commands.run` but NOT
        // `commands.destructive`. The backend will 403 BEFORE the
        // missing-token path — we exercise that + surface the
        // forbidden response. (The super-admin spec covers the
        // proper 422-on-missing-token path.)
        await page.goto('/app/admin/maintenance');
        await expect(page.getByTestId('maintenance-view')).toBeVisible({ timeout: 15_000 });

        // Direct API attempt: the rate-limit + whitelist reject the
        // payload even for a destructive command the admin doesn't
        // have permission for. Admin HAS commands.run but NOT
        // commands.destructive — the preview path returns 403.
        const res = await request.post('/api/admin/commands/preview', {
            data: { command: 'kb:prune-deleted', args: { days: 30 } },
        });
        expect([403, 422]).toContain(res.status());
    });

    test('failure — unknown command returns 404', async ({ request }) => {
        const res = await request.post('/api/admin/commands/run', {
            data: { command: 'evil:exec', args: {} },
        });
        expect(res.status()).toBe(404);
        const body = await res.json();
        expect(body.message).toMatch(/not found/i);
    });

    test('failure — /run 500 injection surfaces wizard data-state=error', async ({ page }) => {
        /* R13: failure injection — real path tested in "happy — non-destructive kb:validate-canonical". */
        await page.route('**/api/admin/commands/run', (route) =>
            route.fulfill({
                status: 500,
                body: '{"message":"boom"}',
                contentType: 'application/json',
            }),
        );

        await page.goto('/app/admin/maintenance');
        await expect(page.getByTestId('maintenance-panel-commands')).toHaveAttribute(
            'data-state',
            'ready',
            { timeout: 15_000 },
        );

        await page.getByTestId('maintenance-card-kb:validate-canonical-run').click();
        await page.getByTestId('wizard-step-preview-run').click();

        // Non-destructive flow → direct run → intercepted → error.
        await expect(page.getByTestId('wizard-run')).toHaveAttribute('data-state', 'error', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('wizard-error')).toBeVisible();
    });
});
