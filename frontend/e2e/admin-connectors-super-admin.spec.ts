import { test as baseTest, expect } from '@playwright/test';

/*
 * v4.5/W3 — Connector admin SPA scenarios. v8.20 — multi-account UI.
 *
 * Auth posture: every connector endpoint is gated by `can:manageConnectors`
 * (super-admin only). These scenarios run under the `chromium-super-admin`
 * Playwright project (storageState: playwright/.auth/super-admin.json).
 *
 * IMPORTANT (R38): this file deliberately does NOT call resetDb()/migrate:fresh.
 * It relies on the global seeded state + serial test ordering, exactly like the
 * pre-v8.20 version — a per-test migrate:fresh here races the other super-admin
 * specs (admin-insights / maintenance / mcp) that authenticate via storageState
 * and flips them to 401 mid-run. The multi-account WRITE flows (create / edit /
 * duplicate-rejection / project binding) are covered by the self-contained
 * `connectors-imap-super-admin.spec.ts` (credential connector, inline login).
 *
 * R13: the only external boundary touched is the OAuth redirect
 * (accounts.google.com) — aborted via page.route so the test never leaves the
 * application. Internal routes are real.
 *
 * Test order matters: the not_installed assertions run before the scenarios that
 * create a PENDING google-drive row (the contract probe + the OAuth-add happy
 * path), so they see the clean seeded state.
 */

baseTest.describe.configure({ timeout: 90_000 });

baseTest.describe('Admin Connectors — super-admin', () => {
    baseTest('lands on /app/admin/connectors with both reference connectors visible', async ({
        page,
    }) => {
        await page.goto('/app/admin/connectors');

        const view = page.getByTestId('admin-connectors');
        await expect(view).toBeVisible({ timeout: 15_000 });
        await expect(view).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        await expect(page.getByTestId('connector-list-card-google-drive')).toBeVisible();
        await expect(page.getByTestId('connector-list-card-notion')).toBeVisible();

        // Both start with no accounts against the fresh seeded tenant state.
        await expect(page.getByTestId('connector-list-card-google-drive')).toHaveAttribute(
            'data-status',
            'not_installed',
        );
        // v8.20 — the per-connector CTA is now "Add account".
        await expect(page.getByTestId('connector-google-drive-add-account')).toBeVisible();
        await expect(page.getByTestId('connector-notion-add-account')).toBeVisible();
    });

    baseTest('connect — BE returns redirect_to with OAuth scopes', async ({ page }) => {
        // BE contract probe only — page.request keeps the call in the
        // authenticated page context (storageState cookies). v8.20: pass an
        // explicit label (the BE defaults to 'default' when omitted).
        const installResp = await page.request.get(
            '/api/admin/connectors/google-drive/install?label=probe',
        );
        if (!installResp.ok()) {
            throw new Error(`GET install returned ${installResp.status()}: ${await installResp.text()}`);
        }
        const payload = await installResp.json();
        expect(payload.data.installation_id).toEqual(expect.any(Number));
        expect(payload.data.redirect_to).toContain('accounts.google.com');
        expect(payload.data.redirect_to).toContain('drive.readonly');
    });

    baseTest('callback without a pending row surfaces a loud error (R14)', async ({ page }) => {
        // `notion` is never installed in this file, so its oauthCallback() 404s
        // and the SPA must surface it as an inline error (never a blank page).
        await page.goto('/app/admin/connectors/notion/callback?code=fake&state=nope');

        const callbackHost = page.getByTestId('admin-connectors-callback');
        await expect(callbackHost).toBeVisible({ timeout: 15_000 });

        const errorBlock = page.getByTestId('callback-error');
        await expect(errorBlock).toBeVisible({ timeout: 15_000 });
        await expect(errorBlock).toHaveAttribute('data-status', '404');

        // R15: a keyboard-reachable Back to Connectors button.
        const backBtn = page.getByTestId('callback-back');
        await expect(backBtn).toBeVisible();
        await backBtn.click();
        await expect(page).toHaveURL(/\/admin\/connectors$/);
    });

    baseTest('sidebar entry for Connectors navigates to /app/admin/connectors', async ({ page }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        const navButton = page.getByRole('button', { name: 'Connectors', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        await expect(page).toHaveURL(/\/admin\/connectors$/);
        await expect(page.getByTestId('admin-connectors')).toBeVisible({ timeout: 15_000 });
    });

    // v8.20 — OAuth "Add account" opens AccountMetaForm and the install request
    // carries the chosen label. Runs LAST: it creates a PENDING google-drive row
    // (startOAuthInstall persists before returning the redirect), which must not
    // perturb the not_installed assertions above. R13: the external OAuth
    // navigation is aborted, so the test never leaves the application.
    baseTest('OAuth Add account opens AccountMetaForm; install request carries the label', async ({
        page,
    }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByTestId('connector-google-drive-add-account').click();

        const form = page.getByTestId('connector-google-drive-account-form');
        await expect(form).toBeVisible();
        await expect(form).toHaveAttribute('data-state', 'idle');
        // R18: label + project-binding fields (the dropdown derives from the
        // real registry).
        await expect(page.getByTestId('connector-google-drive-account-form-label')).toBeVisible();
        await expect(page.getByTestId('connector-google-drive-account-form-project')).toBeVisible();

        await page.getByTestId('connector-google-drive-account-form-label').fill('CI-OAuth');

        const installRequestPromise = page.waitForRequest(
            (req) =>
                req.url().includes('/api/admin/connectors/google-drive/install') &&
                req.method() === 'GET',
            { timeout: 15_000 },
        );
        // Abort the external OAuth navigation (R13 application boundary).
        await page.route('https://accounts.google.com/**', (route) => route.abort());

        await page
            .getByTestId('connector-google-drive-account-form-submit')
            .click({ noWaitAfter: true });

        const installRequest = await installRequestPromise;
        expect(new URL(installRequest.url()).searchParams.get('label')).toBe('CI-OAuth');
    });
});
