import { test, expect } from './fixtures';

/**
 * v8.15/W3.2 — per-user digest page (/app/digest): the in-app feed card +
 * preferences panel. Runs against the real backend (R13); the only stub is a
 * failure-injection on the internal PUT, marked below.
 */
test.describe('Digest page', () => {
    test('preferences persist after reload (happy path)', async ({ page }) => {
        await page.goto('/app/digest');

        const panel = page.getByTestId('digest-pref');
        await expect(panel).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // Default cadence is weekly; switch to monthly and save.
        await expect(page.getByTestId('digest-pref-frequency-weekly')).toBeChecked();
        await page.getByTestId('digest-pref-frequency-monthly').click();
        await expect(page.getByTestId('digest-pref-dirty')).toBeVisible();
        await page.getByTestId('digest-pref-save').click();
        await expect(page.getByTestId('digest-pref-save-success')).toBeVisible({ timeout: 10_000 });

        // Reload → the backend persisted the choice.
        await page.reload();
        await expect(panel).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('digest-pref-frequency-monthly')).toBeChecked();
    });

    test('feed card reaches a terminal state', async ({ page }) => {
        await page.goto('/app/digest');

        const card = page.getByTestId('digest-feed-card');
        // A fresh tenant has no generated digest yet → empty; once one exists → ready.
        await expect(card).not.toHaveAttribute('data-state', 'loading', { timeout: 15_000 });
        const state = await card.getAttribute('data-state');
        expect(['ready', 'empty']).toContain(state);
    });

    test('surfaces a save error when the PUT fails (R13: failure injection)', async ({ page }) => {
        // R13: failure injection against an internal route — the happy-path test
        // above already exercises the real-data flow.
        await page.route('**/api/me/digest-preferences', async (route) => {
            if (route.request().method() === 'PUT') {
                await route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"boom"}' });
                return;
            }
            await route.continue();
        });

        await page.goto('/app/digest');
        const panel = page.getByTestId('digest-pref');
        await expect(panel).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        await page.getByTestId('digest-pref-frequency-monthly').click();
        await page.getByTestId('digest-pref-save').click();
        await expect(page.getByTestId('digest-pref-save-error')).toBeVisible({ timeout: 10_000 });
    });
});
