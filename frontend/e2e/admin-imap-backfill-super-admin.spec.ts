import { test as baseTest, expect, type Page } from '@playwright/test';
import { resetDb, seedDb } from './setup-helpers';

/*
 * Durable IMAP backfill panel. The happy path uses the real Laravel routes,
 * database, vault, queue dispatch and FakeImapClientFactory (the only external
 * boundary is the IMAP TCP server). The paired route override is explicitly the
 * R13 failure injection for the POST error branch.
 */

const PASSWORD = 'password';

async function loginAsSuperAdmin(page: Page): Promise<void> {
    await page.request.get('/sanctum/csrf-cookie');
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    if (!xsrf) throw new Error('XSRF-TOKEN cookie missing');
    const response = await page.request.post('/api/auth/login', {
        data: { email: 'super@demo.local', password: PASSWORD },
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'application/json' },
    });
    if (!response.ok()) throw new Error(`Login failed: ${response.status()} ${await response.text()}`);
}

async function addImapAccount(page: Page): Promise<void> {
    await page.goto('/app/admin/connectors');
    await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
    await page.getByTestId('connector-imap-add-account').click();
    await page.getByTestId('connector-imap-form-label').fill('Backfill E2E');
    await page.getByTestId('connector-imap-form-host').fill('imap.example.com');
    await page.getByTestId('connector-imap-form-username').fill('backfill@example.com');
    await page.getByTestId('connector-imap-form-password').fill('app-password');
    await page.getByTestId('connector-imap-form-test').click();
    await expect(page.getByTestId('connector-imap-form-test-result')).toHaveAttribute('data-status', 'ok', {
        timeout: 15_000,
    });
    await page.getByTestId('connector-imap-form-submit').click();
    await expect(page.getByTestId('connector-source-imap')).toHaveAttribute('data-connection-count', '1', {
        timeout: 15_000,
    });
}

baseTest.describe.configure({ timeout: 120_000, mode: 'serial' });

baseTest.describe('Admin Ingestion — durable IMAP backfill', () => {
    baseTest.beforeEach(async ({ page }) => {
        await resetDb(page);
        await seedDb(page);
        await loginAsSuperAdmin(page);
        await addImapAccount(page);
    });

    baseTest('happy — starts a real durable campaign and renders progress', async ({ page }) => {
        await page.goto('/app/admin/ingestion');

        const panel = page.getByTestId('imap-backfill');
        await expect(panel).toHaveAttribute('data-state', 'empty', { timeout: 15_000 });
        await page.getByTestId('imap-backfill-start').click();

        await expect(panel).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(panel).toHaveAttribute('data-backfill-status', /discovering|running|completed/);
        await expect(page.getByTestId('imap-backfill-progress')).toBeVisible();
    });

    baseTest('failure — start rejection exposes the panel error state', async ({ page }) => {
        // R13: failure injection — the happy test above exercises the real POST;
        // this forces only that internal route to fail so the UI branch is observable.
        await page.route('**/api/admin/connectors/*/imap-backfill', async (route) => {
            if (route.request().method() === 'POST') {
                await route.fulfill({
                    status: 500,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'injected backfill failure' }),
                });
                return;
            }
            await route.continue();
        });

        await page.goto('/app/admin/ingestion');
        const panel = page.getByTestId('imap-backfill');
        await expect(panel).toHaveAttribute('data-state', 'empty', { timeout: 15_000 });
        await page.getByTestId('imap-backfill-start').click();

        await expect(panel).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        await expect(panel.getByRole('alert')).toContainText('Could not start the full import.');
    });
});
