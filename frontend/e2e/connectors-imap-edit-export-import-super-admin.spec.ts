import { test as baseTest, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resetDb, seedDb } from './setup-helpers';

/*
 * v8.29 — the three new IMAP capabilities in the admin panel:
 *   1. Edit → Connection tab: reconfigure host/port/username (+ optional new
 *      password) in place, verified before save.
 *   2. Export: download an account's connection params (SECRET-FREE).
 *   3. Import: prefill a new account from an exported file, then Connect.
 *
 * R13: real backend, real DB, real Sanctum cookies, real Gate. The ONLY external
 * boundary — the IMAP server — is reached by the BACKEND over TCP; the server runs
 * with CONNECTOR_IMAP_FAKE_PING=true, an INPUT-DRIVEN fake (host containing
 * `invalid`/`fail` → login failure; otherwise success). No internal route is
 * intercepted. `can:manageConnectors` is super-admin → chromium-super-admin project.
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

/** Add one active IMAP account via the credential form (passing fake ping). */
async function addImapAccount(page: Page, opts: { label: string; host?: string }): Promise<void> {
    await page.getByTestId('connector-imap-add-account').click();
    await expect(page.getByTestId('connector-imap-form')).toBeVisible();
    await page.getByTestId('connector-imap-form-label').fill(opts.label);
    await page.getByTestId('connector-imap-form-host').fill(opts.host ?? 'imap.example.com');
    await page.getByTestId('connector-imap-form-username').fill('alice@example.com');
    await page.getByTestId('connector-imap-form-password').fill('app-password');
    await page.getByTestId('connector-imap-form-test').click();
    await expect(page.getByTestId('connector-imap-form-test-result')).toHaveAttribute('data-status', 'ok', {
        timeout: 15_000,
    });
    await page.getByTestId('connector-imap-form-submit').click();
}

baseTest.describe.configure({ timeout: 120_000 });

baseTest.describe('Connectors — IMAP edit/export/import (super-admin)', () => {
    baseTest.beforeEach(async ({ page }) => {
        await resetDb(page);
        await seedDb(page);
        await loginAs(page, 'super@demo.local');
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
    });

    baseTest('edit → the Connection tab reconfigures the host (keeping the password) and persists', async ({
        page,
    }) => {
        await addImapAccount(page, { label: 'Support' });
        const card = page.getByTestId('connector-list-card-imap');
        await expect(card).toHaveAttribute('data-account-count', '1', { timeout: 15_000 });

        // Open the tabbed Edit modal (opens on Details); switch to Connection.
        await card.locator('[data-testid$="-edit"]').first().click();
        await expect(page.locator('[data-testid$="-edit-modal"]')).toBeVisible();
        await page.locator('[data-testid$="-edit-tab-connection"]').click();

        // The Connection tab prefills from the secret-free export.
        await expect(page.getByTestId('connector-imap-form-host')).toHaveValue('imap.example.com', {
            timeout: 15_000,
        });
        // The password is optional here (blank = keep current) — Save is enabled.
        await expect(page.getByTestId('connector-imap-form-submit')).toBeEnabled();

        // Change the host (still a reachable fake host) and save, keeping the password.
        await page.getByTestId('connector-imap-form-host').fill('imap.moved.example.com');
        await page.getByTestId('connector-imap-form-submit').click();

        await expect(page.getByTestId('toast-connector-reconfigured')).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-testid$="-edit-modal"]')).toHaveCount(0);

        // Re-open Edit → Connection: the new host round-tripped through /export.
        await card.locator('[data-testid$="-edit"]').first().click();
        await page.locator('[data-testid$="-edit-tab-connection"]').click();
        await expect(page.getByTestId('connector-imap-form-host')).toHaveValue('imap.moved.example.com', {
            timeout: 15_000,
        });
    });

    baseTest('edit → the Connection tab rejects an unreachable host and keeps the modal open', async ({
        page,
    }) => {
        await addImapAccount(page, { label: 'Support' });
        const card = page.getByTestId('connector-list-card-imap');
        await expect(card).toHaveAttribute('data-account-count', '1', { timeout: 15_000 });

        await card.locator('[data-testid$="-edit"]').first().click();
        await page.locator('[data-testid$="-edit-tab-connection"]').click();
        await expect(page.getByTestId('connector-imap-form-host')).toHaveValue('imap.example.com', {
            timeout: 15_000,
        });

        // `invalid` in the host drives the fake ping (health check) to fail.
        await page.getByTestId('connector-imap-form-host').fill('invalid.example.com');
        await page.getByTestId('connector-imap-form-submit').click();

        // R14 — the failure surfaces inline and the modal stays open (rolled back).
        await expect(page.getByTestId('connector-imap-form-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-testid$="-edit-modal"]')).toBeVisible();
    });

    baseTest('export downloads a secret-free config file', async ({ page }) => {
        await addImapAccount(page, { label: 'Support' });
        const card = page.getByTestId('connector-list-card-imap');
        await expect(card).toHaveAttribute('data-account-count', '1', { timeout: 15_000 });

        const [download] = await Promise.all([
            page.waitForEvent('download'),
            card.locator('[data-testid$="-export"]').first().click(),
        ]);

        expect(download.suggestedFilename()).toContain('imap');
        expect(download.suggestedFilename()).toContain('.askmydocs-connector.json');

        const path = await download.path();
        const body = JSON.parse(readFileSync(path, 'utf-8'));
        expect(body._meta.format).toBe('askmydocs.connector-config');
        expect(body.params.host).toBe('imap.example.com');
        expect(body.params.username).toBe('alice@example.com');
        // SECRET-FREE — the password appears nowhere and is flagged as omitted.
        expect(JSON.stringify(body)).not.toContain('app-password');
        expect(body.params.password).toBeUndefined();
        expect(body.secret_fields_omitted).toContain('password');

        await expect(page.getByTestId('toast-connector-exported')).toBeVisible({ timeout: 10_000 });
    });

    baseTest('import prefills a new account from a file, then Connect creates it', async ({ page }) => {
        const card = page.getByTestId('connector-list-card-imap');

        // A valid exported config (no secret) — imported to prefill a NEW account.
        const file = {
            name: 'imap-Imported.askmydocs-connector.json',
            mimeType: 'application/json',
            buffer: Buffer.from(
                JSON.stringify({
                    _meta: { format: 'askmydocs.connector-config', version: 1, connector: 'imap' },
                    connector: 'imap',
                    label: 'Imported',
                    project_key: null,
                    params: {
                        auth_mode: 'basic',
                        host: 'imap.imported.example.com',
                        port: 993,
                        encryption: 'ssl',
                        validate_cert: true,
                        username: 'imported@example.com',
                    },
                    settings: {},
                    secret_fields_omitted: ['password'],
                }),
            ),
        };

        // The hidden file input drives the import (setInputFiles works on it).
        await card.getByTestId('connector-imap-import-file').setInputFiles(file);

        // The create form opens prefilled from the file (secret still blank).
        await expect(page.getByTestId('connector-imap-form')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('connector-imap-form-label')).toHaveValue('Imported');
        await expect(page.getByTestId('connector-imap-form-host')).toHaveValue('imap.imported.example.com');
        await expect(page.getByTestId('connector-imap-form-username')).toHaveValue('imported@example.com');
        await expect(page.getByTestId('connector-imap-form-password')).toHaveValue('');
        await expect(page.getByTestId('toast-connector-imported')).toBeVisible({ timeout: 10_000 });

        // Enter the secret, test, Connect → the account is created.
        await page.getByTestId('connector-imap-form-password').fill('typed-in-pw');
        await page.getByTestId('connector-imap-form-test').click();
        await expect(page.getByTestId('connector-imap-form-test-result')).toHaveAttribute('data-status', 'ok', {
            timeout: 15_000,
        });
        await page.getByTestId('connector-imap-form-submit').click();

        await expect(card).toHaveAttribute('data-account-count', '1', { timeout: 15_000 });
        await expect(card.getByText('Imported', { exact: true })).toBeVisible();
    });
});
