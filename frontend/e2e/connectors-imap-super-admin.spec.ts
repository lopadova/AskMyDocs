import { test as baseTest, expect, type Page } from '@playwright/test';
import { resetDb, seedDb } from './setup-helpers';

/*
 * v8.17 — the first CREDENTIAL-BASED connector (IMAP) in the admin panel.
 *
 * Auth posture: `can:manageConnectors` is super-admin only → this spec runs
 * under the `chromium-super-admin` project (storageState super-admin.json).
 *
 * R13: real backend, real DB, real Sanctum cookies, real Gate. The ONLY
 * external boundary — the IMAP server — is reached by the BACKEND over TCP, so
 * Playwright cannot stub it with page.route. Instead the server runs with
 * CONNECTOR_IMAP_FAKE_PING=true, which swaps in a deterministic, INPUT-DRIVEN
 * fake IMAP client (host containing `invalid`/`fail` → login failure; otherwise
 * success) — the same seam philosophy as AI_PROVIDER=fake. No internal route is
 * intercepted.
 *
 * Each test resets + seeds + re-logs-in (the happy path activates the IMAP
 * installation, so tests must not depend on each other; migrate:fresh in
 * resetDb invalidates the storageState session, hence the inline login).
 */

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

baseTest.describe.configure({ timeout: 90_000 });

baseTest.describe('Connectors — IMAP credential flow (super-admin)', () => {
    baseTest.beforeEach(async ({ page }) => {
        await resetDb(page);
        await seedDb(page);
        await loginAs(page, 'super@demo.local');
    });

    baseTest('IMAP card opens a credential form (not an OAuth redirect)', async ({ page }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const card = page.getByTestId('connector-list-card-imap');
        await expect(card).toBeVisible();
        await expect(card).toHaveAttribute('data-status', 'not_installed');

        // Clicking Connect on a credential connector opens the schema-driven
        // modal in-place (no navigation away to a provider).
        await page.getByTestId('connector-imap-connect').click();
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();
        // basic is the default → server/credentials fields are shown; the
        // xoauth2-only provider field is hidden.
        await expect(page.getByTestId('connector-imap-form-host')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-password')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-xoauth2_provider')).toHaveCount(0);
    });

    baseTest('happy — fill credentials → ping succeeds → connector becomes Active', async ({
        page,
    }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByTestId('connector-imap-connect').click();
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();

        await page.getByTestId('connector-imap-form-host').fill('imap.example.com');
        await page.getByTestId('connector-imap-form-username').fill('alice@example.com');
        await page.getByTestId('connector-imap-form-password').fill('app-password');
        await page.getByTestId('connector-imap-form-submit').click();

        // The fake ping succeeds → BE vaults the secret + flips the row to ACTIVE;
        // the mutation invalidates the list → the card re-renders Active and the
        // modal closes.
        await expect(page.getByTestId('connector-list-card-imap')).toHaveAttribute(
            'data-status',
            'active',
            { timeout: 15_000 },
        );
        await expect(page.getByTestId('connector-imap-form')).toHaveCount(0);
    });

    baseTest('failure — bad host → ping fails → 422 error shown, connector NOT active', async ({
        page,
    }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByTestId('connector-imap-connect').click();
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();

        // `invalid` in the host drives the fake ping to fail → BE returns 422.
        await page.getByTestId('connector-imap-form-host').fill('invalid.example.com');
        await page.getByTestId('connector-imap-form-username').fill('alice@example.com');
        await page.getByTestId('connector-imap-form-password').fill('wrong-password');
        await page.getByTestId('connector-imap-form-submit').click();

        // R14 — the failure is surfaced loudly in the form, the modal stays open,
        // and the card never reaches active.
        await expect(page.getByTestId('connector-imap-form-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();
        await expect(page.getByTestId('connector-list-card-imap')).not.toHaveAttribute(
            'data-status',
            'active',
        );
    });
});
