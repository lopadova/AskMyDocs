import { test, expect } from '@playwright/test';

/*
 * PR13 — Phase H2. Admin Maintenance destructive command flow under
 * the super-admin storage state. Only super-admin holds
 * `commands.destructive`, so kb:prune-deleted's full lifecycle
 * (Preview → Confirm → Run → Result) can only be exercised here.
 *
 * Runs against the REAL backend. The preview issues a confirm_token
 * (DB-backed, single-use, 5m TTL); typing the command name into the
 * confirm input enables the Run button; Run consumes the token;
 * Artisan invokes kb:prune-deleted which is idempotent against
 * the seeded DemoSeeder state (no soft-deleted rows → no-op).
 */

test.describe('Admin Maintenance — super-admin destructive flow', () => {
    test('happy — destructive kb:prune-deleted round-trips confirm_token end-to-end', async ({
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

        // Pick a destructive card. kb:prune-deleted is safe on the
        // DemoSeeder corpus (no soft-deleted rows) so it finishes
        // cleanly even under a real worker — no collateral damage.
        await expect(
            page.getByTestId('maintenance-card-kb:prune-deleted'),
        ).toHaveAttribute('data-destructive', 'true');
        await page.getByTestId('maintenance-card-kb:prune-deleted-run').click();

        await expect(page.getByTestId('command-wizard')).toBeVisible();
        await expect(page.getByTestId('command-wizard')).toHaveAttribute('data-step', 'preview');

        // Optional `days` arg → leave blank (defaults server-side).
        await page.getByTestId('wizard-step-preview-run').click();

        // Destructive path: preview issues a confirm_token and the
        // wizard advances to step=confirm.
        await expect(page.getByTestId('wizard-step-confirm')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('command-wizard')).toHaveAttribute('data-step', 'confirm');

        // Type-to-confirm UX — input must match the command name.
        await page.getByTestId('wizard-confirm-input').fill('kb:prune-deleted');
        await expect(page.getByTestId('wizard-confirm-continue')).not.toBeDisabled();

        await page.getByTestId('wizard-confirm-continue').click();

        // Run step → Result. kb:prune-deleted is a no-op on seeded
        // data → completes successfully and row shows completed.
        await expect(page.getByTestId('wizard-result')).toBeVisible({ timeout: 25_000 });
        await expect(page.getByTestId('wizard-result')).toHaveAttribute('data-state', 'ready', {
            timeout: 25_000,
        });
    });
});
