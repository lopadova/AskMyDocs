import type { Page } from '@playwright/test';
import { test as baseTest, expect } from './fixtures';

/*
 * Widget appearance editor — super-admin SPA scenarios.
 *
 * Auth posture: the widget admin screen is gated by `manageWidgetKeys`
 * (super-admin only). Runs under the `chromium-super-admin` Playwright
 * project (storageState: playwright/.auth/super-admin.json).
 *
 * R13 compliance: ZERO route stubs. Every surface is INTERNAL and runs
 * real against SQLite + the seeded super@demo.local user — key creation
 * (POST /api/admin/widget-keys) and the theme save (PATCH /…/{id}) both
 * hit the real controller + DB. The failure path provokes a REAL 422 by
 * submitting an invalid hex colour (no page.route injection).
 *
 * Coverage:
 *   - Happy path: create a key → open Appearance → edit the accent on the
 *     Colors tab → Save → real PATCH succeeds → dialog closes.
 *   - Failure path (R14): an invalid colour yields a real 422 and the
 *     error surfaces inline; the dialog stays open.
 */

baseTest.describe.configure({ timeout: 90_000 });

/** Create a widget key via the real UI + API, returning its id. */
async function createKey(page: Page, label: string): Promise<number> {
    await page.goto('/app/admin/widget');
    await expect(page.getByTestId('admin-widget-keys-view')).toBeVisible({ timeout: 15_000 });

    await page.getByTestId('admin-widget-keys-create-btn').click();
    await page.getByTestId('admin-widget-keys-label').fill(label);
    await page.getByTestId('admin-widget-keys-project').fill('e2e-appearance');

    const createPost = page.waitForResponse(
        (r) => r.url().endsWith('/api/admin/widget-keys') && r.request().method() === 'POST',
        { timeout: 15_000 },
    );
    await page.getByTestId('admin-widget-keys-create-submit').click();
    const resp = await createPost;
    if (!resp.ok()) {
        throw new Error(`POST /api/admin/widget-keys failed: ${resp.status()} ${await resp.text()}`);
    }
    return (await resp.json()).data.id as number;
}

baseTest.describe('Widget appearance editor — super-admin', () => {
    baseTest('edits the accent colour and saves it against the real backend', async ({ page }) => {
        const id = await createKey(page, `Appearance OK ${Date.now()}`);

        await page.getByTestId(`admin-widget-keys-appearance-${id}`).click();
        const dialog = page.getByTestId('admin-widget-appearance-dialog');
        await expect(dialog).toBeVisible();

        // Live preview renders the real widget chrome.
        await expect(page.getByTestId('admin-widget-appearance-preview')).toBeVisible();

        await page.getByTestId('admin-widget-appearance-tab-colors').click();
        const hex = page.getByTestId('admin-widget-appearance-hex-accent');
        await hex.fill('#10b981');

        const patch = page.waitForResponse(
            (r) =>
                r.url().includes(`/api/admin/widget-keys/${id}`) && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-widget-appearance-save').click();
        const resp = await patch;
        if (!resp.ok()) {
            throw new Error(`PATCH theme failed: ${resp.status()} ${await resp.text()}`);
        }
        expect(resp.json()).resolves.toMatchObject({ data: { theme: { accent: '#10b981' } } });

        // onSuccess closes the dialog.
        await expect(dialog).toBeHidden({ timeout: 10_000 });
    });

    baseTest('surfaces a real 422 when the colour is invalid (R14)', async ({ page }) => {
        const id = await createKey(page, `Appearance bad ${Date.now()}`);

        await page.getByTestId(`admin-widget-keys-appearance-${id}`).click();
        await expect(page.getByTestId('admin-widget-appearance-dialog')).toBeVisible();

        await page.getByTestId('admin-widget-appearance-tab-colors').click();
        await page.getByTestId('admin-widget-appearance-hex-accent').fill('not-a-color');

        const patch = page.waitForResponse(
            (r) =>
                r.url().includes(`/api/admin/widget-keys/${id}`) && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-widget-appearance-save').click();
        const resp = await patch;
        expect(resp.status()).toBe(422);

        await expect(page.getByTestId('admin-widget-appearance-error')).toBeVisible({
            timeout: 10_000,
        });
        // Dialog stays open so the operator can fix the value.
        await expect(page.getByTestId('admin-widget-appearance-dialog')).toBeVisible();
    });
});
