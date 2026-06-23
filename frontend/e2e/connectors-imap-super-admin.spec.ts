import { test as baseTest, expect, type Page } from '@playwright/test';
import { resetDb, seedDb } from './setup-helpers';

/*
 * v8.17 — the first CREDENTIAL-BASED connector (IMAP) in the admin panel.
 * v8.20 — multi-account: N labelled IMAP mailboxes per tenant, each optionally
 * bound to a KB project. The card lists ACCOUNTS; "Add account" opens the
 * schema-driven credential form (now with a label + project binding on top).
 *
 * Auth posture: `can:manageConnectors` is super-admin only → this spec runs
 * under the `chromium-super-admin` project (storageState super-admin.json).
 *
 * R13: real backend, real DB, real Sanctum cookies, real Gate. The ONLY
 * external boundary — the IMAP server — is reached by the BACKEND over TCP, so
 * Playwright cannot stub it with page.route. The server runs with
 * CONNECTOR_IMAP_FAKE_PING=true, an INPUT-DRIVEN fake (host containing
 * `invalid`/`fail` → login failure; otherwise success). No internal route is
 * intercepted.
 *
 * Each test resets + seeds + re-logs-in (migrate:fresh invalidates the
 * storageState session, hence the inline login).
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

/** Fill + submit the IMAP credential form for one account. */
async function addImapAccount(
    page: Page,
    opts: { label: string; host?: string; project?: string },
): Promise<void> {
    await page.getByTestId('connector-imap-add-account').click();
    await expect(page.getByTestId('connector-imap-form')).toBeVisible();
    await page.getByTestId('connector-imap-form-label').fill(opts.label);
    if (opts.project) {
        const projectSelect = page.getByTestId('connector-imap-form-project_key');
        // The projects registry loads independently of the connectors list, so
        // wait for the option to exist before selecting (avoids a select flake
        // when the dropdown still has only the "Global" sentinel).
        await expect(projectSelect.locator(`option[value="${opts.project}"]`)).toHaveCount(1, {
            timeout: 15_000,
        });
        await projectSelect.selectOption(opts.project);
    }
    await page.getByTestId('connector-imap-form-host').fill(opts.host ?? 'imap.example.com');
    await page.getByTestId('connector-imap-form-username').fill('alice@example.com');
    await page.getByTestId('connector-imap-form-password').fill('app-password');
    await page.getByTestId('connector-imap-form-submit').click();
}

baseTest.describe.configure({ timeout: 120_000 });

baseTest.describe('Connectors — IMAP credential flow (super-admin)', () => {
    baseTest.beforeEach(async ({ page }) => {
        await resetDb(page);
        await seedDb(page);
        await loginAs(page, 'super@demo.local');
    });

    baseTest('IMAP card opens a credential form with a label + project binding', async ({ page }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const card = page.getByTestId('connector-list-card-imap');
        await expect(card).toBeVisible();
        await expect(card).toHaveAttribute('data-account-count', '0');

        await page.getByTestId('connector-imap-add-account').click();
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();
        // v8.20 — label (required) + project binding precede the schema fields.
        await expect(page.getByTestId('connector-imap-form-label')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-project_key')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-host')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-password')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-xoauth2_provider')).toHaveCount(0);
    });

    baseTest('happy — fill credentials → ping succeeds → account becomes Active', async ({ page }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await addImapAccount(page, { label: 'Support' });

        // The fake ping succeeds → BE vaults the secret + flips the row ACTIVE;
        // the mutation invalidates the list → the card shows one active account
        // and the modal closes.
        const card = page.getByTestId('connector-list-card-imap');
        await expect(card).toHaveAttribute('data-account-count', '1', { timeout: 15_000 });
        await expect(card.locator('[data-account-status="active"]')).toHaveCount(1);
        await expect(page.getByTestId('connector-imap-form')).toHaveCount(0);
    });

    baseTest('failure — bad host → ping fails → 422 error shown, no active account', async ({ page }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // `invalid` in the host drives the fake ping to fail → BE returns 422.
        await addImapAccount(page, { label: 'Support', host: 'invalid.example.com' });

        // R14 — the failure is surfaced loudly, the modal stays open, and no
        // account reaches active.
        await expect(page.getByTestId('connector-imap-form-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();
        await expect(
            page.getByTestId('connector-list-card-imap').locator('[data-account-status="active"]'),
        ).toHaveCount(0);
    });

    // ── v8.20 §7 — multi-account + project binding + unique rejection ──────────
    baseTest('multi-account — two mailboxes (one project-bound, one default) + duplicate-label rejected', async ({
        page,
    }) => {
        // A real project to bind the first account to (R18 dropdown domain).
        const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
        if (!xsrf) throw new Error('XSRF-TOKEN cookie missing after login — cannot seed project');
        const proj = await page.request.post('/api/admin/projects', {
            data: { name: 'Acme HR', project_key: 'acme-hr' },
            headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'application/json' },
        });
        if (!proj.ok()) throw new Error(`seed project failed: ${proj.status()} ${await proj.text()}`);

        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const card = page.getByTestId('connector-list-card-imap');

        // Account 1: "Support" bound to acme-hr.
        await addImapAccount(page, { label: 'Support', project: 'acme-hr' });
        await expect(card).toHaveAttribute('data-account-count', '1', { timeout: 15_000 });

        // Account 2: "Sales" unbound (→ tenant default).
        await addImapAccount(page, { label: 'Sales' });
        await expect(card).toHaveAttribute('data-account-count', '2', { timeout: 15_000 });

        // Both accounts present with their bindings.
        await expect(card.getByText('Support', { exact: true })).toBeVisible();
        await expect(card.getByText('Sales', { exact: true })).toBeVisible();
        await expect(card.getByText('→ acme-hr')).toBeVisible();
        await expect(card.getByText('→ Global (tenant default)')).toBeVisible();

        // Account 3: reusing label "Support" → the (tenant, imap, label) unique
        // rejects it; the form shows a 422 label error and the count stays 2.
        await addImapAccount(page, { label: 'Support', host: 'imap2.example.com' });
        await expect(page.getByTestId('connector-imap-form-label-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();

        // Close the modal; the card still has exactly 2 accounts.
        await page.getByTestId('connector-imap-form-cancel').click();
        await expect(card).toHaveAttribute('data-account-count', '2');
    });

    // ── v8.20 §8 — Edit flow (AccountMetaForm kind='edit') ───────────────────
    baseTest('edit happy — AccountMetaForm pre-fills from active account; label rename persists', async ({
        page,
    }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Create one active IMAP account.
        await addImapAccount(page, { label: 'Support' });
        const card = page.getByTestId('connector-list-card-imap');
        await expect(card).toHaveAttribute('data-account-count', '1', { timeout: 15_000 });

        // The Edit button is available for an active account.
        const editBtn = card.locator('[data-testid$="-edit"]').first();
        await expect(editBtn).toBeVisible();
        await editBtn.click();

        // AccountMetaForm (edit) opens pre-filled with the existing label.
        const editForm = page.getByTestId('connector-imap-account-form');
        await expect(editForm).toBeVisible();
        const labelInput = page.getByTestId('connector-imap-account-form-label');
        await expect(labelInput).toHaveValue('Support');

        // Rename and save.
        await labelInput.fill('Support v2');
        await page.getByTestId('connector-imap-account-form-submit').click();

        // Form closes; success toast fires; card reflects the updated label.
        await expect(editForm).toHaveCount(0, { timeout: 15_000 });
        await expect(page.getByTestId('toast-connector-updated')).toBeVisible({ timeout: 10_000 });
        await expect(card.getByText('Support v2', { exact: true })).toBeVisible();
    });

    baseTest('edit failure — renaming to an existing label shows inline label-error', async ({
        page,
    }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const card = page.getByTestId('connector-list-card-imap');

        // Create two active IMAP accounts.
        await addImapAccount(page, { label: 'Support' });
        await expect(card).toHaveAttribute('data-account-count', '1', { timeout: 15_000 });
        await addImapAccount(page, { label: 'Sales' });
        await expect(card).toHaveAttribute('data-account-count', '2', { timeout: 15_000 });

        // Edit the "Sales" account. Accounts render sorted by LABEL (Sales <
        // Support), so target by label text rather than creation-order index.
        await expect(card.locator('[data-testid$="-edit"]')).toHaveCount(2);
        const salesRow = card.locator('li').filter({ hasText: 'Sales' });
        await salesRow.locator('[data-testid$="-edit"]').click();

        const editForm = page.getByTestId('connector-imap-account-form');
        await expect(editForm).toBeVisible();
        await expect(page.getByTestId('connector-imap-account-form-label')).toHaveValue('Sales');

        // Rename to "Support" → duplicate → 422.
        await page.getByTestId('connector-imap-account-form-label').fill('Support');
        await page.getByTestId('connector-imap-account-form-submit').click();

        // R14: label error surfaces inline; form stays open; count unchanged.
        await expect(
            page.getByTestId('connector-imap-account-form-label-error'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(editForm).toBeVisible();
        await expect(card).toHaveAttribute('data-account-count', '2');
    });
});
