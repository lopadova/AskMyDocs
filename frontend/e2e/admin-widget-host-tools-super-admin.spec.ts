import type { Page } from '@playwright/test';
import { test as baseTest, expect } from './fixtures';

/*
 * Widget host-tools switch — super-admin SPA scenarios.
 *
 * Auth posture: the widget admin screen is gated by `manageWidgetKeys`
 * (super-admin only). Runs under the `chromium-super-admin` Playwright
 * project (storageState: playwright/.auth/super-admin.json).
 *
 * R13 compliance: ZERO route stubs. Every surface is INTERNAL and runs
 * real against SQLite + the seeded super@demo.local user — key creation
 * (POST /api/admin/widget-keys, with host_tools_enabled) and the list
 * toggle (PATCH /…/{id}) both hit the real controller + DB.
 *
 * Coverage (happy path):
 *   - Create a key with the "Enable host tools" checkbox ON → the create
 *     payload persists host_tools_enabled=true → the row toggle reflects ON.
 *   - Flip the row toggle OFF → real PATCH persists false → toggle reflects
 *     OFF after the refetch.
 *
 * This is the operator side of the orchestrator double-gate (skill AND key):
 * the switch here is what gates host tools per-customer at runtime.
 */

baseTest.describe.configure({ timeout: 90_000 });

/** Create a widget key via the real UI + API with host tools enabled; returns its id. */
async function createKeyWithHostTools(page: Page, label: string): Promise<number> {
    await page.goto('/app/admin/widget');
    await expect(page.getByTestId('admin-widget-keys-view')).toBeVisible({ timeout: 15_000 });

    await page.getByTestId('admin-widget-keys-create-btn').click();
    await page.getByTestId('admin-widget-keys-label').fill(label);
    await page.getByTestId('admin-widget-keys-project').fill('e2e-host-tools');

    // Drive the actual transition: the checkbox starts off, we turn it on.
    const toggle = page.getByTestId('admin-widget-keys-host-tools-toggle');
    await expect(toggle).not.toBeChecked();
    await toggle.check();
    await expect(toggle).toBeChecked();

    const createPost = page.waitForResponse(
        (r) => r.url().endsWith('/api/admin/widget-keys') && r.request().method() === 'POST',
        { timeout: 15_000 },
    );
    await page.getByTestId('admin-widget-keys-create-submit').click();
    const resp = await createPost;
    if (!resp.ok()) {
        throw new Error(`POST /api/admin/widget-keys failed: ${resp.status()} ${await resp.text()}`);
    }
    const body = await resp.json();
    // F1.2 — the backend persists the operational switch.
    expect(body.data.host_tools_enabled).toBe(true);

    return body.data.id as number;
}

baseTest.describe('Widget host-tools switch — super-admin', () => {
    baseTest('creates a key with host tools on, then toggles it off from the list', async ({
        page,
    }) => {
        const id = await createKeyWithHostTools(page, `HostTools ${Date.now()}`);

        // The row toggle reflects the persisted ON state.
        const rowToggle = page.getByTestId(`admin-widget-keys-${id}-host-tools-toggle`);
        await expect(rowToggle).toBeVisible({ timeout: 15_000 });
        await expect(rowToggle).toBeChecked();

        // Edit path: turn the switch OFF → real PATCH persists false.
        const patch = page.waitForResponse(
            (r) =>
                r.url().includes(`/api/admin/widget-keys/${id}`) && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        // .click() (non .uncheck()): il toggle della LISTA è controllato dallo
        // stato server (nessun optimistic update), quindi `checked` non si
        // ribalta finché il refetch non completa — .uncheck() pretende il cambio
        // di stato immediato e fallisce. Il click innesca la mutation; lo stato
        // OFF persistito è verificato dopo il PATCH (not.toBeChecked sotto).
        await rowToggle.click();
        const resp = await patch;
        if (!resp.ok()) {
            throw new Error(`PATCH host_tools failed: ${resp.status()} ${await resp.text()}`);
        }

        // After the invalidate + refetch, the row toggle reflects OFF.
        await expect(
            page.getByTestId(`admin-widget-keys-${id}-host-tools-toggle`),
        ).not.toBeChecked();
    });
});
