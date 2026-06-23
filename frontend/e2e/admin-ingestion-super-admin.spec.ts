import { test as baseTest, expect } from '@playwright/test';

/*
 * v8.21 (Ciclo 2) — "Ingestion & Sync" admin screen + observability endpoints.
 *
 * Auth: `can:manageConnectors` (super-admin) — runs under the
 * `chromium-super-admin` project (storageState). R13: real backend / DB / Gate.
 *
 * IMPORTANT (R38): no resetDb()/migrate:fresh here — that races the other
 * super-admin specs and 401s them. Assertions are data-INDEPENDENT (queue cards
 * always render the three roles regardless of backlog/installs), so the spec is
 * robust to shared global state. The recorder→run pipeline itself is covered by
 * PHPUnit (IngestionObservabilityTest).
 */

baseTest.describe.configure({ timeout: 90_000 });

baseTest.describe('Admin Ingestion & Sync — super-admin', () => {
    baseTest('lands on /app/admin/ingestion with the three queue cards', async ({ page }) => {
        await page.goto('/app/admin/ingestion');

        const view = page.getByTestId('admin-ingestion');
        await expect(view).toBeVisible({ timeout: 15_000 });
        await expect(view).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // The three logical queue roles always render (data-independent).
        await expect(page.getByTestId('ingestion-queue-connector-sync')).toBeVisible();
        await expect(page.getByTestId('ingestion-queue-kb-ingest')).toBeVisible();
        await expect(page.getByTestId('ingestion-queue-default')).toBeVisible();

        // The account selector is present (empty or populated depending on
        // shared state — we don't assert its options here).
        await expect(page.getByTestId('ingestion-account-select')).toBeVisible();
    });

    baseTest('sidebar entry navigates to /app/admin/ingestion', async ({ page }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        const navButton = page.getByRole('button', { name: 'Ingestion & Sync', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        await expect(page).toHaveURL(/\/admin\/ingestion$/);
        await expect(page.getByTestId('admin-ingestion')).toBeVisible({ timeout: 15_000 });
    });

    baseTest('BE contract — queue endpoint returns the three logical roles', async ({ page }) => {
        const resp = await page.request.get('/api/admin/ingestion/queue');
        if (!resp.ok()) {
            throw new Error(`GET queue returned ${resp.status()}: ${await resp.text()}`);
        }
        const roles: string[] = ((await resp.json()).data as Array<{ role: string }>).map((q) => q.role);
        expect(roles).toContain('connector-sync');
        expect(roles).toContain('kb-ingest');
        expect(roles).toContain('default');
    });

    baseTest('failure — sync-runs for a non-existent installation 404s (R14)', async ({ page }) => {
        // A bogus installation id must not leak data — the endpoint 404s rather
        // than returning an empty 200 that reads as "no runs".
        const resp = await page.request.get('/api/admin/connectors/99999999/sync-runs');
        expect(resp.status()).toBe(404);
    });

    baseTest('failure — queue error state renders with a retry when the queue endpoint fails', async ({ page }) => {
        // R13: failure injection — forces the queue depth API to return 500 so
        // the UI error branch (data-state="error" + admin-ingestion-queue-error
        // + admin-ingestion-queue-retry) is exercised end-to-end in the browser.
        await page.route('**/api/admin/ingestion/queue', (route) =>
            route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'server error' }) }),
        );

        await page.goto('/app/admin/ingestion');

        const view = page.getByTestId('admin-ingestion');
        await expect(view).toBeVisible({ timeout: 15_000 });
        await expect(view).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        await expect(page.getByTestId('admin-ingestion-queue-error')).toBeVisible();
        await expect(page.getByTestId('admin-ingestion-queue-retry')).toBeVisible();
    });
});
