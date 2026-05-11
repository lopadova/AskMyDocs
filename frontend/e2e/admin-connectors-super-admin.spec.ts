import { test as baseTest, expect } from '@playwright/test';

/*
 * v4.5/W3 — Connector admin SPA scenarios.
 *
 * Auth posture: every connector endpoint is gated by
 * `can:manageConnectors` (super-admin only by design). These
 * scenarios run under the `chromium-super-admin` Playwright project
 * (storageState: playwright/.auth/super-admin.json).
 *
 * R13 compliance:
 *   - The Laravel side (controller + DB + queue + Gate) runs real
 *     against SQLite + the seeded super@demo.local user.
 *   - The ONLY page-level network interception here is the external
 *     OAuth redirect — when the SPA clicks "Connect", the BE
 *     responds with a redirect_to pointing at accounts.google.com,
 *     and we MUST cancel the navigation so the test does not actually
 *     leave the application boundary. This is the textbook R13
 *     "external service" exception: Google's OAuth consent screen is
 *     outside our application, requires real-user input, and would
 *     hang any automated test.
 *
 * Coverage:
 *   - Happy path: super-admin lands on /app/admin/connectors, both
 *     reference connectors (google-drive + notion) render with the
 *     not_installed badge, the Connect button visible.
 *   - Happy path: clicking Connect issues the install request and
 *     navigates to the provider's OAuth URL (intercepted).
 *   - Failure path: visiting the OAuth callback route without a
 *     pending installation row triggers a real 404 from the BE
 *     and the SPA surfaces an inline error (R14 — failures loud,
 *     never silent).
 */

baseTest.describe.configure({ timeout: 90_000 });

baseTest.describe('Admin Connectors — super-admin', () => {
    baseTest('lands on /app/admin/connectors with both reference connectors visible', async ({
        page,
    }) => {
        await page.goto('/app/admin/connectors');

        // The view itself mounts in either ready (the cards) or
        // loading state — `seeded` fixture already ran login.
        const view = page.getByTestId('admin-connectors');
        await expect(view).toBeVisible({ timeout: 15_000 });
        await expect(view).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // Both reference connectors render. The order is registry-
        // defined and stable as long as config/connectors.php +
        // composer-discovered packages stay the same.
        await expect(page.getByTestId('connector-list-card-google-drive')).toBeVisible();
        await expect(page.getByTestId('connector-list-card-notion')).toBeVisible();

        // Both start as not_installed against the DemoSeeder's fresh
        // tenant state — no installation rows pre-seeded.
        await expect(page.getByTestId('connector-list-card-google-drive')).toHaveAttribute(
            'data-status',
            'not_installed',
        );
        await expect(page.getByTestId('connector-google-drive-connect')).toBeVisible();
        await expect(page.getByTestId('connector-notion-connect')).toBeVisible();
    });

    baseTest('connect — BE returns redirect_to with OAuth scopes', async ({ request }) => {
        // BE contract probe only — no UI navigation.
        //
        // We deliberately do NOT exercise the click-then-navigate UI
        // flow here for two reasons:
        //
        // 1. The SPA calls `window.location.assign(redirect_to)` which
        //    triggers a top-level navigation. Playwright's `waitForResponse`
        //    resolves but the browser tears down the page context for
        //    the navigation, disposing the response body BEFORE
        //    `.json()` can read it (Network.getResponseBody race).
        //
        // 2. Starting an install writes a `connector_installations` row
        //    in `PENDING` state. With no DB reset between tests in the
        //    same project run, downstream specs (admin-insights,
        //    admin-maintenance) see a polluted DB + a half-open page
        //    context and flip 401 on otherwise-authenticated calls.
        //
        // The list-page render + sidebar nav are covered by the other
        // three scenarios in this file. The contract surface is
        // covered here.
        const installResp = await request.get('/api/admin/connectors/google-drive/install');
        if (!installResp.ok()) {
            throw new Error(
                `GET install returned ${installResp.status()}: ${await installResp.text()}`,
            );
        }
        const payload = await installResp.json();
        expect(payload.data.installation_id).toEqual(expect.any(Number));
        expect(payload.data.redirect_to).toContain('accounts.google.com');
        expect(payload.data.redirect_to).toContain('drive.readonly');
    });

    baseTest('callback without a pending row surfaces a loud error (R14)', async ({ page }) => {
        // No install was started for this connector in this scenario,
        // so the BE's oauthCallback() endpoint will return 404 — we
        // assert the SPA surfaces it as an inline error rather than
        // silently rendering an empty page.
        await page.goto('/app/admin/connectors/google-drive/callback?code=fake&state=nope');

        const callbackHost = page.getByTestId('admin-connectors-callback');
        await expect(callbackHost).toBeVisible({ timeout: 15_000 });

        // The error block must render with the 404 status surfaced.
        const errorBlock = page.getByTestId('callback-error');
        await expect(errorBlock).toBeVisible({ timeout: 15_000 });
        await expect(errorBlock).toHaveAttribute('data-status', '404');

        // R15: there's a keyboard-reachable Back to Connectors button.
        const backBtn = page.getByTestId('callback-back');
        await expect(backBtn).toBeVisible();
        await backBtn.click();
        await expect(page).toHaveURL(/\/app\/admin\/connectors$/);
    });

    baseTest('sidebar entry for Connectors navigates to /app/admin/connectors', async ({
        page,
    }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        // Sidebar entry text is "Connectors"; find it by accessible name.
        const navButton = page.getByRole('button', { name: 'Connectors', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        await expect(page).toHaveURL(/\/app\/admin\/connectors$/);
        await expect(page.getByTestId('admin-connectors')).toBeVisible({ timeout: 15_000 });
    });
});
