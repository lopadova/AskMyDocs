import { test as baseTest, expect, type Page } from '@playwright/test';
import { resetDb, seedDb } from './setup-helpers';

/*
 * v8.25 — the two operator actions added alongside the connection-settings
 * editor: re-enabling a paused account and the read-only "test fetch" probe.
 *
 *   - Enable: a DISABLED account (paused via Disable, credentials kept) is
 *     re-armed back to ACTIVE without re-install. The inverse of Disable.
 *   - Test fetch: download the SINGLE newest email of a folder as a sanitized
 *     preview, WITHOUT ingesting it — the operator's end-to-end credential check.
 *     A reachable-but-empty folder is a valid success (R43 the OTHER state); an
 *     unreachable mailbox surfaces a 503 → error toast, never an empty 200 (R14).
 *
 * Auth posture: `can:manageConnectors` (admin + super-admin) → runs under the
 * super-admin project. R13: real backend / DB / Sanctum / Gate. The ONLY external
 * boundary — the IMAP server — is reached by the BACKEND over TCP and is replaced
 * by the input-driven FakeImapClientFactory (CONNECTOR_IMAP_FAKE_PING=true). Its
 * INBOX is empty (selectMailbox→lastUid 0, searchUids→[]), so the real-data
 * test-fetch returns a valid `message: null` empty-folder preview. The failure
 * path uses a sanctioned internal-route injection (see its marker), since the
 * fake factory never naturally 503s the probe.
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

baseTest.describe('Connectors — Enable + Test fetch (super-admin)', () => {
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

    baseTest('happy — disable then re-enable re-arms the account to active', async ({ page }) => {
        const card = page.getByTestId('connector-list-card-imap');
        const account = card.locator('[data-testid^="connector-account-"][data-account-status]').first();
        await expect(account).toHaveAttribute('data-account-status', 'active');

        // Pause it: the account flips to DISABLED and exposes the Enable action.
        await card.locator('[data-testid$="-disable"]').first().click();
        await expect(page.getByTestId('toast-connector-disabled')).toBeVisible({ timeout: 10_000 });
        await expect(account).toHaveAttribute('data-account-status', 'disabled', { timeout: 10_000 });
        await expect(card.locator('[data-testid$="-enable"]').first()).toBeVisible();

        // Re-enable: back to ACTIVE without a re-install (credentials kept).
        await card.locator('[data-testid$="-enable"]').first().click();
        await expect(page.getByTestId('toast-connector-enabled')).toBeVisible({ timeout: 10_000 });
        await expect(account).toHaveAttribute('data-account-status', 'active', { timeout: 10_000 });
        // The Enable button is gone once active; Disable is back.
        await expect(card.locator('[data-testid$="-enable"]')).toHaveCount(0);
        await expect(card.locator('[data-testid$="-disable"]').first()).toBeVisible();
    });

    baseTest('happy — test fetch opens the read-only preview (empty-folder state)', async ({ page }) => {
        const card = page.getByTestId('connector-list-card-imap');

        // Real-data probe through the backend: the fake INBOX is reachable but
        // empty → a valid 200 with message:null (R43 the OTHER state).
        await card.locator('[data-testid$="-test-fetch"]').first().click();

        const result = page.getByTestId('connector-test-fetch-result');
        await expect(result).toBeVisible({ timeout: 15_000 });
        await expect(result).toHaveAttribute('data-result-state', 'empty');
        await expect(page.getByTestId('connector-test-fetch-folder')).toContainText('INBOX');
        await expect(page.getByTestId('connector-test-fetch-empty')).toBeVisible();
        // It is a diagnostic, not a write: no message preview, no ingest.
        await expect(page.getByTestId('connector-test-fetch-message')).toHaveCount(0);

        await page.getByTestId('connector-test-fetch-close').click();
        await expect(result).toHaveCount(0);
    });

    baseTest('failure — an unreachable mailbox surfaces an error toast, no modal', async ({ page }) => {
        // R13: failure injection. The happy test above exercises the real-data
        // probe (empty INBOX); here we force the internal test-fetch endpoint to
        // 503 to drive the FE error path, which the working fake never returns.
        await page.route('**/api/admin/connectors/*/test-fetch', (route) =>
            route.fulfill({
                status: 503,
                contentType: 'application/json',
                body: JSON.stringify({ error: "Impossibile scaricare l'email di prova." }),
            }),
        );

        const card = page.getByTestId('connector-list-card-imap');
        await card.locator('[data-testid$="-test-fetch"]').first().click();

        await expect(page.getByTestId('toast-connector-error')).toBeVisible({ timeout: 10_000 });
        // The probe failed — the read-only result modal must NOT open.
        await expect(page.getByTestId('connector-test-fetch-result')).toHaveCount(0);
    });
});
