import { test as baseTest, expect, type Page } from '@playwright/test';
import { resetDb, seedDb } from './setup-helpers';

/*
 * v8.25 — the schema-driven connection-settings editor for a credential (IMAP)
 * account (supersedes the v8.24 folder-only picker). It renders the connector's
 * full `connection_settings_schema` (folders include/exclude, sync window,
 * sender/recipient/subject filters, body format, scope flags, attachments).
 *
 * Auth posture: `can:manageConnectors` (admin + super-admin) → runs under the
 * super-admin project. R13: real backend / DB / Sanctum / Gate. The ONLY external
 * boundary — the IMAP server — is reached by the BACKEND over TCP and is replaced
 * by the input-driven FakeImapClientFactory (CONNECTOR_IMAP_FAKE_PING=true), whose
 * listMailboxes() returns a fixed folder set (INBOX, [Gmail]/Sent Mail,
 * rotta-logistics-1). The happy path uses that real-data flow; the failure path
 * uses a sanctioned internal-route injection (see its marker).
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

async function addImapAccount(page: Page, label: string): Promise<void> {
    await page.getByTestId('connector-imap-add-account').click();
    await expect(page.getByTestId('connector-imap-form')).toBeVisible();
    await page.getByTestId('connector-imap-form-label').fill(label);
    await page.getByTestId('connector-imap-form-host').fill('imap.example.com');
    await page.getByTestId('connector-imap-form-username').fill('alice@example.com');
    await page.getByTestId('connector-imap-form-password').fill('app-password');
    await page.getByTestId('connector-imap-form-submit').click();
}

baseTest.describe.configure({ timeout: 120_000 });

baseTest.describe('Connectors — IMAP connection settings (super-admin)', () => {
    baseTest.beforeEach(async ({ page }) => {
        await resetDb(page);
        await seedDb(page);
        await loginAs(page, 'super@demo.local');
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await addImapAccount(page, 'Support');
        await expect(page.getByTestId('connector-list-card-imap')).toHaveAttribute(
            'data-account-count',
            '1',
            { timeout: 15_000 },
        );
    });

    baseTest('happy — edits folders + window + a sender filter and persists them', async ({ page }) => {
        const card = page.getByTestId('connector-list-card-imap');
        await card.locator('[data-testid$="-folders"]').first().click();

        const form = page.getByTestId('connector-imap-settings-form');
        await expect(form).toBeVisible();
        // The live folder list resolves → data-state ready (R14 observable state).
        await expect(form).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('connector-imap-settings-folders-include-list')).toBeVisible();

        // Whitelist INBOX, set a 90-day window, add a sender exclude filter, then save.
        await page.getByTestId('connector-imap-settings-folders-include-opt-inbox').check();
        await page.getByTestId('connector-imap-settings-date-window-days').fill('90');
        await page.getByTestId('connector-imap-settings-senders-exclude-input').fill('noreply@x.com');
        await page.getByTestId('connector-imap-settings-senders-exclude-add').click();
        await expect(page.getByTestId('connector-imap-settings-senders-exclude-chip-noreply-x-com-remove')).toBeVisible();
        await page.getByTestId('connector-imap-settings-form-submit').click();

        await expect(page.getByTestId('toast-connector-folders-saved')).toBeVisible({ timeout: 10_000 });
        await expect(form).toHaveCount(0);

        // Reopen → the saved values round-trip (INBOX checked, window 90, tag chip present).
        await card.locator('[data-testid$="-folders"]').first().click();
        await expect(page.getByTestId('connector-imap-settings-form')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('connector-imap-settings-folders-include-opt-inbox')).toBeChecked();
        await expect(page.getByTestId('connector-imap-settings-date-window-days')).toHaveValue('90');
        await expect(page.getByTestId('connector-imap-settings-senders-exclude-chip-noreply-x-com-remove')).toBeVisible();
    });

    baseTest('failure — an unreachable mailbox surfaces the fetch-error state', async ({ page }) => {
        // R13: failure injection. The happy test above exercises the real-data
        // folder flow; here we force the internal /folders endpoint to 503 to drive
        // the FE error state, which a working fake factory never returns.
        await page.route('**/api/admin/connectors/*/folders', (route) =>
            route.fulfill({
                status: 503,
                contentType: 'application/json',
                body: JSON.stringify({ error: 'Impossibile elencare le cartelle.' }),
            }),
        );

        const card = page.getByTestId('connector-list-card-imap');
        await card.locator('[data-testid$="-folders"]').first().click();

        const form = page.getByTestId('connector-imap-settings-form');
        await expect(form).toBeVisible();
        await expect(form).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        await expect(page.getByTestId('connector-imap-settings-folders-include-fetch-error').first()).toBeVisible();
        await expect(page.getByTestId('connector-imap-settings-folders-include-retry').first()).toBeVisible();
    });
});
