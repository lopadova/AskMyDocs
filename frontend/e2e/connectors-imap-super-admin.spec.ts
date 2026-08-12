import { test as baseTest, expect, type Page, type Locator } from '@playwright/test';
import { resetDb, seedDb } from './setup-helpers';

/*
 * v8.17 — the first CREDENTIAL-BASED connector (IMAP) in the admin panel.
 * v8.20 — multi-account: N labelled IMAP mailboxes per tenant, each optionally
 * bound to a KB project.
 * v8.30 — redesigned landing page ("Miglioramento usabilità card" handoff):
 * an "Available sources" tile grid (tile = `connector-source-{key}` with a
 * `data-connection-count` attribute + the Add/Import affordances) over a FLAT
 * "Connections" list (row/card = `connector-connection-{id}` with
 * `data-connection-status`; per-row actions live in the ⋮ menu).
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

/** The flat Connections row/card whose text contains this account label. */
function connectionRow(page: Page, label: string): Locator {
    return page
        .locator('[data-testid^="connector-connection-"][data-connection-status]')
        .filter({ hasText: label });
}

/** Open the ⋮ actions menu on the connection with this account label. */
async function openConnectionMenu(page: Page, label: string): Promise<void> {
    await connectionRow(page, label).locator('[data-testid$="-menu"]').click();
}

/** Fill the IMAP credential form, run the (passing) connection test, and submit one account. */
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
    // v8.26 — Connect is gated behind a passing connection test: test first, then
    // save. Every caller of this helper uses a reachable host, so the fake ping
    // passes and Connect enables.
    await page.getByTestId('connector-imap-form-test').click();
    await expect(page.getByTestId('connector-imap-form-test-result')).toHaveAttribute(
        'data-status',
        'ok',
        { timeout: 15_000 },
    );
    await page.getByTestId('connector-imap-form-submit').click();
}

baseTest.describe.configure({ timeout: 120_000 });

baseTest.describe('Connectors — IMAP credential flow (super-admin)', () => {
    baseTest.beforeEach(async ({ page }) => {
        await resetDb(page);
        await seedDb(page);
        await loginAs(page, 'super@demo.local');
    });

    baseTest('IMAP tile opens a credential form with a label + project binding', async ({ page }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const source = page.getByTestId('connector-source-imap');
        await expect(source).toBeVisible();
        await expect(source).toHaveAttribute('data-connection-count', '0');

        await page.getByTestId('connector-imap-add-account').click();
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();
        // v8.20 — label (required) + project binding precede the schema fields.
        await expect(page.getByTestId('connector-imap-form-label')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-project_key')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-host')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-password')).toBeVisible();
        await expect(page.getByTestId('connector-imap-form-xoauth2_provider')).toHaveCount(0);
    });

    baseTest('happy — test connection passes → Connect enables → account becomes Active', async ({ page }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByTestId('connector-imap-add-account').click();
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();
        await page.getByTestId('connector-imap-form-label').fill('Support');
        await page.getByTestId('connector-imap-form-host').fill('imap.example.com');
        await page.getByTestId('connector-imap-form-username').fill('alice@example.com');
        await page.getByTestId('connector-imap-form-password').fill('app-password');

        // v8.26 — Connect is gated: disabled until the connection test passes.
        await expect(page.getByTestId('connector-imap-form-submit')).toBeDisabled();

        await page.getByTestId('connector-imap-form-test').click();
        await expect(page.getByTestId('connector-imap-form-test-result')).toHaveAttribute(
            'data-status',
            'ok',
            { timeout: 15_000 },
        );

        // Test OK → Connect enables → save.
        await expect(page.getByTestId('connector-imap-form-submit')).toBeEnabled();
        await page.getByTestId('connector-imap-form-submit').click();

        // The fake ping succeeds → BE vaults the secret + flips the row ACTIVE;
        // the mutation invalidates the list → the tile badge counts one account,
        // the Connections list shows it active, and the modal closes.
        await expect(page.getByTestId('connector-source-imap')).toHaveAttribute(
            'data-connection-count',
            '1',
            { timeout: 15_000 },
        );
        await expect(page.locator('[data-connection-status="active"]')).toHaveCount(1);
        await expect(page.getByTestId('connector-imap-form')).toHaveCount(0);
    });

    baseTest('failure — bad host → connection test fails → Connect stays disabled, no account', async ({ page }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByTestId('connector-imap-add-account').click();
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();
        await page.getByTestId('connector-imap-form-label').fill('Support');
        // `invalid` in the host drives the fake ping to fail.
        await page.getByTestId('connector-imap-form-host').fill('invalid.example.com');
        await page.getByTestId('connector-imap-form-username').fill('alice@example.com');
        await page.getByTestId('connector-imap-form-password').fill('app-password');

        await page.getByTestId('connector-imap-form-test').click();

        // R14 — the failed test is surfaced loudly; Connect STAYS disabled so
        // nothing is saved, the modal stays open, and no account is created.
        await expect(page.getByTestId('connector-imap-form-test-result')).toHaveAttribute(
            'data-status',
            'error',
            { timeout: 15_000 },
        );
        await expect(page.getByTestId('connector-imap-form-submit')).toBeDisabled();
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();
        await expect(page.locator('[data-connection-status="active"]')).toHaveCount(0);
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
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrf.value),
                'X-Tenant-Id': 'a-demo',
                Accept: 'application/json',
            },
        });
        if (!proj.ok()) throw new Error(`seed project failed: ${proj.status()} ${await proj.text()}`);

        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const source = page.getByTestId('connector-source-imap');

        // Account 1: "Support" bound to acme-hr.
        await addImapAccount(page, { label: 'Support', project: 'acme-hr' });
        await expect(source).toHaveAttribute('data-connection-count', '1', { timeout: 15_000 });

        // Account 2: "Sales" unbound (→ tenant default).
        await addImapAccount(page, { label: 'Sales' });
        await expect(source).toHaveAttribute('data-connection-count', '2', { timeout: 15_000 });

        // Both connections present with their bindings in the flat table.
        await expect(connectionRow(page, 'Support')).toBeVisible();
        await expect(connectionRow(page, 'Sales')).toBeVisible();
        await expect(
            connectionRow(page, 'Support').locator('[data-testid$="-project"]'),
        ).toHaveText('acme-hr');
        await expect(
            connectionRow(page, 'Sales').locator('[data-testid$="-project"]'),
        ).toHaveText('Tenant default');

        // Account 3: reusing label "Support" → the (tenant, imap, label) unique
        // rejects it; the form shows a 422 label error and the count stays 2.
        await addImapAccount(page, { label: 'Support', host: 'imap2.example.com' });
        await expect(page.getByTestId('connector-imap-form-label-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('connector-imap-form')).toBeVisible();

        // Close the modal; the source still counts exactly 2 connections.
        await page.getByTestId('connector-imap-form-cancel').click();
        await expect(source).toHaveAttribute('data-connection-count', '2');
    });

    // ── v8.30 — the redesigned Connections toolbar (search + view toggle) ─────
    baseTest('toolbar — search filters the connections and the cards view renders', async ({ page }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await addImapAccount(page, { label: 'Support' });
        await expect(page.getByTestId('connector-source-imap')).toHaveAttribute(
            'data-connection-count',
            '1',
            { timeout: 15_000 },
        );
        await addImapAccount(page, { label: 'Sales' });
        await expect(page.getByTestId('connector-source-imap')).toHaveAttribute(
            'data-connection-count',
            '2',
            { timeout: 15_000 },
        );

        // Search narrows the flat list; the count pill follows the visible rows.
        await expect(page.getByTestId('connector-connections-count')).toHaveText('2');
        await page.getByTestId('connector-connections-search').fill('sal');
        await expect(connectionRow(page, 'Sales')).toBeVisible();
        await expect(connectionRow(page, 'Support')).toHaveCount(0);
        await expect(page.getByTestId('connector-connections-count')).toHaveText('1');

        // A no-match query surfaces the observable empty state (R14).
        await page.getByTestId('connector-connections-search').fill('no-such-account');
        await expect(page.getByTestId('connector-connections-empty')).toBeVisible();

        // Clear the search + switch to the cards view: both connections render
        // as cards; switching back restores the table.
        await page.getByTestId('connector-connections-search').fill('');
        await page.getByTestId('connector-connections-view-cards').click();
        await expect(page.getByTestId('connector-connections-cards')).toBeVisible();
        await expect(
            page.locator('[data-testid^="connector-connection-"][data-connection-status]'),
        ).toHaveCount(2);
        await page.getByTestId('connector-connections-view-table').click();
        await expect(page.getByTestId('connector-connections-table')).toBeVisible();
    });

    // ── v8.30 — "Sync all" toolbar action (R12 happy path) ───────────────────
    baseTest('toolbar — Sync all queues every active connection with one summary toast', async ({
        page,
    }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await addImapAccount(page, { label: 'Support' });
        await addImapAccount(page, { label: 'Sales' });
        await expect(page.getByTestId('connector-source-imap')).toHaveAttribute(
            'data-connection-count',
            '2',
            { timeout: 15_000 },
        );

        // Sync all fans out over the two active connections and reports ONE summary
        // success toast (never two per-account ones) naming the count.
        await page.getByTestId('connector-connections-sync-all').click();
        const toast = page.getByTestId('toast-connector-synced');
        await expect(toast).toBeVisible({ timeout: 10_000 });
        await expect(toast).toContainText('2 connections');
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
        await expect(page.getByTestId('connector-source-imap')).toHaveAttribute(
            'data-connection-count',
            '1',
            { timeout: 15_000 },
        );

        // Edit lives in the connection's ⋮ menu (v8.30).
        await openConnectionMenu(page, 'Support');
        await page.locator('[data-testid$="-edit"]').click();

        // AccountMetaForm (edit) opens pre-filled with the existing label.
        const editForm = page.getByTestId('connector-imap-account-form');
        await expect(editForm).toBeVisible();
        const labelInput = page.getByTestId('connector-imap-account-form-label');
        await expect(labelInput).toHaveValue('Support');

        // Rename and save via the modal's single sticky footer (v8.31).
        await labelInput.fill('Support v2');
        await page.locator('[data-testid$="-edit-save"]').click();

        // Form closes; success toast fires; the list reflects the updated label.
        await expect(editForm).toHaveCount(0, { timeout: 15_000 });
        await expect(page.getByTestId('toast-connector-updated')).toBeVisible({ timeout: 10_000 });
        await expect(connectionRow(page, 'Support v2')).toBeVisible();
    });

    baseTest('edit failure — renaming to an existing label shows inline label-error', async ({
        page,
    }) => {
        await page.goto('/app/admin/connectors');
        await expect(page.getByTestId('admin-connectors')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const source = page.getByTestId('connector-source-imap');

        // Create two active IMAP accounts.
        await addImapAccount(page, { label: 'Support' });
        await expect(source).toHaveAttribute('data-connection-count', '1', { timeout: 15_000 });
        await addImapAccount(page, { label: 'Sales' });
        await expect(source).toHaveAttribute('data-connection-count', '2', { timeout: 15_000 });

        // Edit the "Sales" account via its row's ⋮ menu.
        await openConnectionMenu(page, 'Sales');
        await page.locator('[data-testid$="-edit"]').click();

        const editForm = page.getByTestId('connector-imap-account-form');
        await expect(editForm).toBeVisible();
        await expect(page.getByTestId('connector-imap-account-form-label')).toHaveValue('Sales');

        // Rename to "Support" → duplicate → 422 (save via the modal footer).
        await page.getByTestId('connector-imap-account-form-label').fill('Support');
        await page.locator('[data-testid$="-edit-save"]').click();

        // R14: label error surfaces inline; form stays open; count unchanged.
        await expect(
            page.getByTestId('connector-imap-account-form-label-error'),
        ).toBeVisible({ timeout: 15_000 });
        await expect(editForm).toBeVisible();
        await expect(source).toHaveAttribute('data-connection-count', '2');
    });
});
