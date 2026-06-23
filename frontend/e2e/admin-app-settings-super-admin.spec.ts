import { test as baseTest, expect } from '@playwright/test';

/*
 * v8.22 (Ciclo 3) — "Configuration" admin screen + runtime config governance.
 *
 * Auth: `role:super-admin` — runs under the `chromium-super-admin` project
 * (storageState). R13: real backend / DB / Gate, no internal route mocking
 * (except the explicit failure-injection test, marked below).
 *
 * IMPORTANT (R38): no resetDb()/migrate:fresh here — it would race the other
 * super-admin specs. The one mutating test changes `connector.sync_cadence_minutes`
 * (inert for every other spec — chat/retrieval never read it) and resets the
 * override at the end, so it leaves no cross-spec state. We deliberately do NOT
 * touch `ai.provider` here: a tenant override would re-route the chat provider
 * and break the chat specs.
 */

const CADENCE_KEY = 'connector.sync_cadence_minutes';

baseTest.describe.configure({ timeout: 90_000 });

baseTest.describe('Admin Configuration (app-settings) — super-admin', () => {
    baseTest.afterEach(async ({ page }) => {
        // Belt-and-suspenders cleanup: clear any tenant-wide cadence override so
        // the screen is back to its config default for the next spec.
        await page.request
            .put('/api/admin/app-settings', { data: { key: CADENCE_KEY, value: null, project_key: '*' } })
            .catch(() => undefined);
    });

    baseTest('lands on /app/admin/app-settings with the governable settings table', async ({ page }) => {
        await page.goto('/app/admin/app-settings');

        const view = page.getByTestId('admin-app-settings');
        await expect(view).toBeVisible({ timeout: 15_000 });
        await expect(view).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        await expect(page.getByTestId('admin-app-settings-table')).toBeVisible();
        await expect(page.getByTestId(`app-setting-row-${CADENCE_KEY}`)).toBeVisible();
        // The deploy-managed FinOps switch is visible but read-only.
        await expect(page.getByTestId('app-setting-ai_finops.enabled-deploy-only')).toBeVisible();
    });

    baseTest('changes connector sync cadence from the UI without a deploy', async ({ page }) => {
        await page.goto('/app/admin/app-settings');
        await expect(page.getByTestId('admin-app-settings')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        const row = page.getByTestId(`app-setting-row-${CADENCE_KEY}`);
        // Before: the value comes from the config default.
        await expect(row).toHaveAttribute('data-source', 'config');

        const input = page.getByTestId(`app-setting-${CADENCE_KEY}-input`);
        await input.fill('30');

        const save = page.getByTestId(`app-setting-${CADENCE_KEY}-save`);
        await expect(save).toBeEnabled();

        const respPromise = page.waitForResponse(
            (r) => r.url().includes('/api/admin/app-settings') && r.request().method() === 'PUT',
        );
        await save.click();
        const resp = await respPromise;
        if (!resp.ok()) {
            throw new Error(`PUT app-settings returned ${resp.status()}: ${await resp.text()}`);
        }

        // After: the effective value now comes from the tenant override.
        await expect(row).toHaveAttribute('data-source', 'tenant', { timeout: 10_000 });
        await expect(page.getByTestId(`app-setting-${CADENCE_KEY}-source`)).toHaveText(/tenant override/);

        // Persisted across a reload (no deploy).
        await page.reload();
        await expect(page.getByTestId(`app-setting-row-${CADENCE_KEY}`)).toHaveAttribute('data-source', 'tenant', {
            timeout: 15_000,
        });
        await expect(page.getByTestId(`app-setting-${CADENCE_KEY}-input`)).toHaveValue('30');
    });

    baseTest('sidebar entry navigates to /app/admin/app-settings', async ({ page }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        const navButton = page.getByRole('button', { name: 'Configuration', exact: true });
        await expect(navButton).toBeVisible();
        await navButton.click();

        await expect(page).toHaveURL(/\/admin\/app-settings$/);
        await expect(page.getByTestId('admin-app-settings')).toBeVisible({ timeout: 15_000 });
    });

    baseTest('BE contract — app-settings endpoint returns the governable keys', async ({ page }) => {
        const resp = await page.request.get('/api/admin/app-settings');
        if (!resp.ok()) {
            throw new Error(`GET app-settings returned ${resp.status()}: ${await resp.text()}`);
        }
        const keys: string[] = ((await resp.json()).data as Array<{ key: string }>).map((s) => s.key);
        expect(keys).toContain('ai.provider');
        expect(keys).toContain(CADENCE_KEY);
        expect(keys).toContain('ai_finops.enabled');
    });

    baseTest('failure — deploy-only key is rejected with 422 (R14)', async ({ page }) => {
        const resp = await page.request.put('/api/admin/app-settings', {
            data: { key: 'ai_finops.enabled', value: true, project_key: '*' },
        });
        expect(resp.status()).toBe(422);
    });

    baseTest('failure — error state renders with a retry when the list endpoint fails', async ({ page }) => {
        // R13: failure injection — forces the list API to 500 so the UI error
        // branch (data-state="error" + retry) is exercised end-to-end.
        await page.route('**/api/admin/app-settings*', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'server error' }) });
            }
            return route.continue();
        });

        await page.goto('/app/admin/app-settings');

        const view = page.getByTestId('admin-app-settings');
        await expect(view).toBeVisible({ timeout: 15_000 });
        await expect(view).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        await expect(page.getByTestId('admin-app-settings-retry')).toBeVisible();
    });
});
