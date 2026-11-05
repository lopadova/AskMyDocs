import { expect, test } from '@playwright/test';

// Synthetic authorization only: no login, token issuance or external callback.
test.use({ storageState: { cookies: [], origins: [] } });

for (const theme of ['light', 'dark'] as const) {
    for (const viewport of [{ width: 1280, height: 960 }, { width: 390, height: 844 }]) {
        test(`PactAuth explicit consent, ${theme}, ${viewport.width}px`, async ({ page }, info) => {
            await page.setViewportSize(viewport);
            await page.addInitScript((value) => {
                localStorage.setItem('amd-ui-locale', 'en');
                localStorage.setItem('amd-theme', value);
            }, theme);
            await page.route('**/api/auth/me', route => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
            await page.route('**/sanctum/csrf-cookie', route => route.fulfill({ status: 204 }));
            const decisions: unknown[] = [];
            await page.route('**/api/oauth/authorization/pact-synthetic', route => {
                if (route.request().method() === 'POST') {
                    decisions.push(route.request().postDataJSON());
                    return route.fulfill({ json: { redirect_to: '/oauth/connections' } });
                }
                return route.fulfill({ json: {
                    client: { name: 'Sorgente', redirect_uri: 'https://sorgente.test/fixture/integrations/pact-auth/askmydocs/callback' },
                    scopes: [{ id: 'pact:delegate', description: 'Delegate read-only knowledge tasks in the one project you select; no document imports or email actions' }],
                    requires_project: true, requires_explicit_selection: true, delegation_available: false,
                    teams: [{ tenant_id: 'synthetic-acme', name: 'Synthetic Acme', projects: [{ project_key: 'engineering' }] },
                        { tenant_id: 'synthetic-other', name: 'Synthetic Other', projects: [{ project_key: 'legal' }] }],
                    user: { name: 'Synthetic User', email: 'synthetic@example.test' }, token_ttl_days: 1,
                } });
            });
            await page.route('**/api/oauth/connections', route => route.fulfill({ json: { connections: [] } }));
            await page.goto('/oauth/consent?request=pact-synthetic');
            const connect = page.getByRole('button', { name: 'Connect application', exact: true });
            await expect(connect).toBeDisabled();
            await page.getByLabel('Company', { exact: true }).focus();
            await page.keyboard.press('Enter');
            await page.getByRole('option', { name: 'Synthetic Acme', exact: true }).click();
            await expect(connect).toBeDisabled();
            await page.getByLabel('Project', { exact: true }).focus();
            await page.keyboard.press('Enter');
            await page.getByRole('option', { name: 'engineering', exact: true }).click();
            await expect(connect).toBeEnabled();
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            await page.screenshot({ path: info.outputPath('consent.png'), fullPage: true });
            await connect.focus();
            await page.keyboard.press('Enter');
            await expect.poll(() => decisions.length).toBe(1);
            expect(decisions[0]).toEqual({ decision: 'approve', tenant_id: 'synthetic-acme', project_key: 'engineering' });
        });
    }
}
