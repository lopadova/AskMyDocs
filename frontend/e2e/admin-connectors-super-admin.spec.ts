import { test as baseTest, expect, type Page } from '@playwright/test';
import { resetDb, seedDb } from './setup-helpers';

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

// ── v8.20 — OAuth AccountMetaForm (label + project binding) ──────────────────
//
// Auth: resetDb/seedDb invalidates the storageState session, so each test
// re-logs-in inline (same pattern as connectors-imap-super-admin.spec.ts).
// R13: the ONLY page.route interception is the external OAuth redirect
// (accounts.google.com) — the application boundary. Internal routes are real.

const PASSWORD = 'password';

async function loginAs(page: Page, email: string): Promise<void> {
    await page.request.get('/sanctum/csrf-cookie');
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrf) throw new Error('XSRF-TOKEN cookie missing after /sanctum/csrf-cookie');
    const res = await page.request.post('/api/auth/login', {
        data: { email, password: PASSWORD },
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'application/json' },
    });
    if (!res.ok()) throw new Error(`Login failed for ${email}: ${res.status()} ${await res.text()}`);
}

baseTest.describe('Admin Connectors — OAuth AccountMetaForm (multi-account, v8.20)', () => {
    baseTest.describe.configure({ timeout: 120_000 });

    baseTest.beforeEach(async ({ page }) => {
        await resetDb(page);
        await seedDb(page);
        await loginAs(page, 'super@demo.local');
    });

    baseTest('happy — Add account opens AccountMetaForm with label + project fields; install API called with label', async ({
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

        // R18: project dropdown derives from the real project registry (not hard-coded).
        await expect(page.getByTestId('connector-google-drive-account-form-label')).toBeVisible();
        await expect(page.getByTestId('connector-google-drive-account-form-project')).toBeVisible();

        await page.getByTestId('connector-google-drive-account-form-label').fill('Support');

        // Capture the install API request before the external OAuth redirect fires.
        // Abort the external navigation so the test does not leave the application
        // boundary (R13: accounts.google.com is the external service boundary).
        const installRequestPromise = page.waitForRequest(
            (req) =>
                req.url().includes('/api/admin/connectors/google-drive/install') &&
                req.method() === 'GET',
            { timeout: 15_000 },
        );
        await page.route('https://accounts.google.com/**', (route) => route.abort());

        // noWaitAfter — the submit fires window.location.assign() to the external
        // OAuth URL (aborted above); without this the click auto-waits on the
        // navigation and can flake. We only need the outgoing install request.
        await page
            .getByTestId('connector-google-drive-account-form-submit')
            .click({ noWaitAfter: true });

        // The install API was called and the label param was forwarded.
        const installRequest = await installRequestPromise;
        expect(new URL(installRequest.url()).searchParams.get('label')).toBe('Support');
    });

    baseTest('failure — duplicate label shows inline label-error; modal stays open', async ({
        page,
    }) => {
        // Seed a PENDING google-drive installation with label "Support" via the real
        // install endpoint so the connector already has one account. The next install
        // attempt with the same label must be rejected with a 422 (duplicate label).
        const seedResp = await page.request.get(
            '/api/admin/connectors/google-drive/install?label=Support',
        );
        if (!seedResp.ok()) {
            throw new Error(`seed install failed: ${seedResp.status()} ${await seedResp.text()}`);
        }

        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // The card shows 1 pending account; "Add account" CTA is still accessible.
        const card = page.getByTestId('connector-list-card-google-drive');
        await expect(card).toHaveAttribute('data-account-count', '1', { timeout: 10_000 });

        await page.getByTestId('connector-google-drive-add-account').click();
        await expect(page.getByTestId('connector-google-drive-account-form')).toBeVisible();

        // Submit with the same label → BE returns 422 (unique violation on label).
        await page.getByTestId('connector-google-drive-account-form-label').fill('Support');
        await page.getByTestId('connector-google-drive-account-form-submit').click();

        // R14: 422 label error surfaces inline; modal stays open; account count unchanged.
        await expect(
            page.getByTestId('connector-google-drive-account-form-label-error'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('connector-google-drive-account-form')).toBeVisible();
        await expect(card).toHaveAttribute('data-account-count', '1');
    });
});

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
        // v8.20 — the per-connector CTA is now "Add account".
        await expect(page.getByTestId('connector-google-drive-add-account')).toBeVisible();
        await expect(page.getByTestId('connector-notion-add-account')).toBeVisible();
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
        // No install was started for `notion` in this run — `google-drive`
        // is touched by the previous BE-contract probe scenario which
        // writes a PENDING row, but `notion` stays untouched. The BE's
        // oauthCallback() endpoint must therefore return 404 for the
        // notion key, and the SPA must surface it as an inline error
        // (R14 — never silently render an empty page).
        await page.goto('/app/admin/connectors/notion/callback?code=fake&state=nope');

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
        await expect(page).toHaveURL(/\/admin\/connectors$/);
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

        await expect(page).toHaveURL(/\/admin\/connectors$/);
        await expect(page.getByTestId('admin-connectors')).toBeVisible({ timeout: 15_000 });
    });
});
