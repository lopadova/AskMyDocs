import type { Page } from '@playwright/test';
import { test as baseTest, expect } from '@playwright/test';
import { resetAndLoginWidgetSuperAdmin } from './helpers/widget-super-admin';

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
 *   - Happy path: create a key from the tenant project select → open
 *     Appearance → edit advanced Launcher/Chat/Composer/Sources tokens →
 *     inspect the real Sources preview → Save → real PATCH succeeds.
 *   - Failure path (R14): an invalid colour yields a real 422 and the
 *     error surfaces inline; the dialog stays open.
 */

baseTest.describe.configure({ mode: 'serial', timeout: 90_000 });

baseTest.beforeEach(async ({ page, context }) => {
    await resetAndLoginWidgetSuperAdmin(page, context);
});

/** Create a widget key via the real UI + API, returning its id. */
async function createKey(page: Page, label: string): Promise<number> {
    await page.goto('/app/admin/widget');
    await expect(page.getByTestId('admin-widget-keys-view')).toBeVisible({ timeout: 15_000 });

    await page.getByTestId('admin-widget-keys-create-btn').click();
    await page.getByTestId('admin-widget-keys-label').fill(label);
    const project = page.getByTestId('admin-widget-keys-project');
    await expect(project).toBeEnabled({ timeout: 15_000 });
    await expect(project).toHaveJSProperty('tagName', 'SELECT');
    expect(await project.locator('option').allTextContents()).toEqual(
        expect.arrayContaining(['hr-portal', 'engineering']),
    );
    await project.selectOption('hr-portal');
    await expect(project).toHaveValue('hr-portal');

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
    baseTest('creates from the project select and saves advanced appearance tokens', async ({ page }) => {
        const id = await createKey(page, `Appearance OK ${Date.now()}`);

        await page.getByTestId(`admin-widget-keys-appearance-${id}`).click();
        const dialog = page.getByTestId('admin-widget-appearance-dialog');
        await expect(dialog).toBeVisible();

        // Live preview renders the real widget chrome.
        await expect(page.getByTestId('admin-widget-appearance-preview')).toBeVisible();

        await page.getByTestId('admin-widget-appearance-tab-branding').click();
        await page.getByTestId('admin-widget-appearance-hex-accent').fill('#10b981');
        await page.getByTestId('admin-widget-appearance-hex-accentForeground').fill('#052e16');

        await page.getByTestId('admin-widget-appearance-tab-launcher').click();
        await page.getByTestId('admin-widget-appearance-field-launcherSize').fill('72');
        await page.getByTestId('admin-widget-appearance-field-launcherShadow').selectOption('strong');

        await page.getByTestId('admin-widget-appearance-tab-chat').click();
        await page.getByTestId('admin-widget-appearance-field-panelWidth').fill('680');
        await page.getByTestId('admin-widget-appearance-field-bubbleRadius').fill('22');

        await page.getByTestId('admin-widget-appearance-tab-composer').click();
        await page.getByTestId('admin-widget-appearance-hex-focusRing').fill('#0ea5e9');
        await page.getByTestId('admin-widget-appearance-field-inputRadius').fill('18');

        await page.getByTestId('admin-widget-appearance-tab-sources').click();
        await page
            .getByTestId('admin-widget-appearance-hex-sourceSidebarBackground')
            .fill('#111827');
        await page
            .getByTestId('admin-widget-appearance-hex-sourceSidebarForeground')
            .fill('#f9fafb');
        await page.getByTestId('admin-widget-appearance-field-sourceViewerWidth').fill('1120');
        await page.getByTestId('admin-widget-appearance-field-sourceViewerRadius').fill('28');

        // The preview uses the same Shadow-DOM variables as the embed bundle.
        await page.getByTestId('admin-widget-appearance-preview-sources').click();
        const preview = page.getByTestId('admin-widget-appearance-preview');
        await expect(preview.locator('.amd-preview-source-dialog')).toBeVisible();
        await expect(preview.locator('.amd-preview-source-sidebar')).toHaveCSS(
            'background-color',
            'rgb(17, 24, 39)',
        );

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
        await expect(resp.json()).resolves.toMatchObject({
            data: {
                project_key: 'hr-portal',
                theme: {
                    accent: '#10b981',
                    accentForeground: '#052e16',
                    launcherSize: 72,
                    launcherShadow: 'strong',
                    panelWidth: 680,
                    bubbleRadius: 22,
                    focusRing: '#0ea5e9',
                    inputRadius: 18,
                    sourceSidebarBackground: '#111827',
                    sourceSidebarForeground: '#f9fafb',
                    sourceViewerWidth: 1120,
                    sourceViewerRadius: 28,
                },
            },
        });

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
