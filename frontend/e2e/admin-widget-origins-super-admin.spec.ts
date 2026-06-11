import type { Page } from '@playwright/test';
import { test as baseTest, expect } from './fixtures';

/*
 * Widget allowed-origins editor — super-admin SPA scenarios.
 *
 * Auth posture: the widget admin screen is gated by `manageWidgetKeys`
 * (super-admin only). Runs under the `chromium-super-admin` Playwright
 * project (storageState: playwright/.auth/super-admin.json).
 *
 * R13 compliance: ZERO route stubs. Every surface is INTERNAL and runs
 * real against SQLite + the seeded super@demo.local user — key creation
 * (POST /api/admin/widget-keys) and the origins save (PATCH /…/{id}) both
 * hit the real controller + DB. The failure path provokes a REAL 422 by
 * submitting an over-long origin (no page.route injection).
 *
 * Coverage:
 *   - Happy path: create a key → open Origins → enter an allow-list →
 *     Save → real PATCH succeeds with the new origins → dialog closes.
 *   - Failure path (R14): an over-long origin yields a real 422 and the
 *     error surfaces inline; the dialog stays open.
 */

baseTest.describe.configure({ timeout: 90_000 });

/** Create a widget key via the real UI + API, returning its id. */
async function createKey(page: Page, label: string): Promise<number> {
    await page.goto('/app/admin/widget');
    await expect(page.getByTestId('admin-widget-keys-view')).toBeVisible({ timeout: 15_000 });

    await page.getByTestId('admin-widget-keys-create-btn').click();
    await page.getByTestId('admin-widget-keys-label').fill(label);
    await page.getByTestId('admin-widget-keys-project').fill('e2e-origins');

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

baseTest.describe('Widget allowed-origins editor — super-admin', () => {
    baseTest('edits the allow-list and saves it against the real backend', async ({ page }) => {
        const id = await createKey(page, `Origins OK ${Date.now()}`);

        await page.getByTestId(`admin-widget-keys-origins-${id}`).click();
        const dialog = page.getByTestId('admin-widget-origins-dialog');
        await expect(dialog).toBeVisible();

        await page
            .getByTestId('admin-widget-origins-input')
            .fill('https://shop.example.test\nhttps://www.shop.example.test');

        const patch = page.waitForResponse(
            (r) =>
                r.url().includes(`/api/admin/widget-keys/${id}`) && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-widget-origins-save').click();
        const resp = await patch;
        if (!resp.ok()) {
            throw new Error(`PATCH origins failed: ${resp.status()} ${await resp.text()}`);
        }
        await expect(resp.json()).resolves.toMatchObject({
            data: {
                allowed_origins: ['https://shop.example.test', 'https://www.shop.example.test'],
            },
        });

        // onSuccess closes the dialog; the row reflects the new origins.
        await expect(dialog).toBeHidden({ timeout: 10_000 });
        await expect(page.getByTestId(`admin-widget-keys-row-${id}`)).toContainText(
            'https://shop.example.test',
        );
    });

    baseTest('surfaces a real 422 when an origin is too long (R14)', async ({ page }) => {
        const id = await createKey(page, `Origins bad ${Date.now()}`);

        await page.getByTestId(`admin-widget-keys-origins-${id}`).click();
        await expect(page.getByTestId('admin-widget-origins-dialog')).toBeVisible();

        // > 255 chars — the controller's `allowed_origins.*` max:255 rule rejects it.
        const tooLong = `https://${'a'.repeat(300)}.test`;
        await page.getByTestId('admin-widget-origins-input').fill(tooLong);

        const patch = page.waitForResponse(
            (r) =>
                r.url().includes(`/api/admin/widget-keys/${id}`) && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-widget-origins-save').click();
        const resp = await patch;
        expect(resp.status()).toBe(422);

        await expect(page.getByTestId('admin-widget-origins-error')).toBeVisible({
            timeout: 10_000,
        });
        // Dialog stays open so the operator can fix the value.
        await expect(page.getByTestId('admin-widget-origins-dialog')).toBeVisible();
    });
});
