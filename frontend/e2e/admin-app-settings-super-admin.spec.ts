import { test as baseTest, expect } from './fixtures';
import { openAdminHubSection } from './helpers';
import { E2E_BASE_URL } from './setup-helpers';

/*
 * v8.22 (Ciclo 3) — "Configuration" admin screen + runtime config governance.
 *
 * Auth: `role:super-admin` — runs under the `chromium-super-admin` project
 * (storageState). R13: real backend / DB / Gate, no internal route mocking
 * (except the explicit failure-injection test, marked below).
 *
 * Each scenario uses the shared reset/seed/login fixture. Only the inert
 * connector cadence is mutated; the AI provider stays in the offline profile.
 */

const CADENCE_KEY = 'connector.sync_cadence_minutes';

baseTest.describe.configure({ timeout: 90_000 });

baseTest.describe('Admin Configuration (app-settings) — super-admin', () => {
    // Clear any tenant-wide cadence override BOTH before and after each test so
    // the "config default" baseline is deterministic even on the FIRST test
    // (a previous run/spec may have left a persisted override). Clearing a
    // non-existent override is a valid 200, so we assert success rather than
    // swallowing failures (a silent failure would leak state / mask a 401).
    const clearCadence = async (page: import('@playwright/test').Page) => {
        const resp = await page.request.put('/api/admin/app-settings', {
            data: { key: CADENCE_KEY, value: null, project_key: '*' },
            headers: await apiHeaders(page),
        });
        if (!resp.ok()) throw new Error(`Clearing cadence returned ${resp.status()}: ${await resp.text()}`);
    };

    baseTest.beforeEach(async ({ page }) => {
        // Establish the SPA origin before API calls so Sanctum loads its session.
        await page.goto('/app/admin/app-settings');
        await clearCadence(page);
    });
    baseTest.afterEach(async ({ page }) => clearCadence(page));

    baseTest('lands on /app/admin/app-settings with the guided configuration form', async ({ page }) => {
        await page.goto('/app/admin/app-settings');

        const view = page.getByTestId('admin-app-settings');
        await expect(view).toBeVisible({ timeout: 15_000 });
        await expect(view).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        await expect(page.getByTestId('admin-app-settings-form')).toBeVisible();
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

        const save = page.getByTestId('app-settings-save');
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

        await openAdminHubSection(page, 'system', 'app-settings', 'system');

        await expect(page).toHaveURL(/\/admin\/app-settings$/);
        await expect(page.getByTestId('admin-app-settings')).toBeVisible({ timeout: 15_000 });
    });

    baseTest('BE contract — app-settings endpoint returns the governable keys', async ({ page }) => {
        const resp = await page.request.get('/api/admin/app-settings', { headers: await apiHeaders(page) });
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
            headers: await apiHeaders(page),
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

async function apiHeaders(page: import('@playwright/test').Page) {
    const xsrf = (await page.context().cookies(E2E_BASE_URL)).find(cookie => cookie.name === 'XSRF-TOKEN');
    if (!xsrf) throw new Error('Missing XSRF cookie for app-settings test.');
    return { 'X-Tenant-Id': 'a-demo', 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'application/json' };
}
