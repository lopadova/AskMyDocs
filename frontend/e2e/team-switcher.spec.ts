import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * Team switcher (= tenant switcher) E2E — admin project.
 *
 * DemoSeeder seeds TWO tenants:
 *   - `default`: 3 docs, 5 chat logs (hr-portal + engineering)
 *   - `acme`   : 2 docs, 2 chat logs (acme-kb), label "Acme Corp",
 *                membership for admin@demo.local only
 *
 * The doc/chat counts are deliberately different per tenant (R16):
 * a leaked cross-tenant payload cannot produce the expected number
 * by coincidence.
 *
 * R13: every scenario hits the real backend with real seeded data —
 * zero `page.route` interception. The forged-header scenario is a
 * REAL request the middleware rejects, not an injected response.
 */

test.describe('Team switcher', () => {
    test('switching team re-scopes the dashboard KPIs', async ({ page }) => {
        await page.goto('/app/admin');

        const kpiStrip = page.getByTestId('kpi-strip');
        await expect(kpiStrip).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });

        // Bootstrap team is `default` (first in /api/auth/me ordering).
        const trigger = page.getByTestId('team-switcher-trigger');
        await expect(trigger).toHaveText(/Default/);
        await expect(page.getByTestId('kpi-card-chats')).toContainText('5');
        await expect(page.getByTestId('kpi-card-docs')).toContainText('3');

        // Switch to Acme Corp.
        await trigger.click();
        const menu = page.getByTestId('team-switcher-menu');
        await expect(menu).toBeVisible();
        await expect(page.getByTestId('team-switcher-item-default')).toHaveAttribute(
            'aria-checked',
            'true',
        );
        await page.getByTestId('team-switcher-item-acme').click();

        // The whole dashboard refetches under X-Tenant-Id: acme.
        await expect(trigger).toHaveText(/Acme Corp/);
        await expect(kpiStrip).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('kpi-card-chats')).toContainText('2');
        await expect(page.getByTestId('kpi-card-docs')).toContainText('2');
    });

    test('the selected team survives a full page reload', async ({ page }) => {
        await page.goto('/app/admin');

        const trigger = page.getByTestId('team-switcher-trigger');
        await expect(trigger).toHaveText(/Default/);
        await trigger.click();
        await page.getByTestId('team-switcher-item-acme').click();
        await expect(trigger).toHaveText(/Acme Corp/);

        await page.reload();

        // localStorage persistence + /api/auth/me re-sync land back on acme.
        await expect(page.getByTestId('team-switcher-trigger')).toHaveText(/Acme Corp/, {
            timeout: 15_000,
        });
        const kpiStrip = page.getByTestId('kpi-strip');
        await expect(kpiStrip).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('kpi-card-chats')).toContainText('2');
    });

    test('switching team re-scopes the KB explorer and the chat logs', async ({ page }) => {
        await page.goto('/app/admin');

        const trigger = page.getByTestId('team-switcher-trigger');
        await expect(trigger).toHaveText(/Default/, { timeout: 15_000 });
        await trigger.click();
        await page.getByTestId('team-switcher-item-acme').click();
        await expect(trigger).toHaveText(/Acme Corp/);

        // KB explorer: the project picker derives its options from the
        // tenant-scoped GET /api/admin/kb/projects (R18) — under acme it
        // must offer ONLY acme-kb (hr-portal / engineering live in
        // `default`). Full navigation also re-exercises persistence.
        await page.goto('/app/admin/kb');
        const select = page.getByTestId('kb-project-select');
        await expect(select).toBeVisible({ timeout: 15_000 });
        await expect(select.locator('option')).toHaveText(['All projects', 'acme-kb'], {
            timeout: 15_000,
        });

        // Logs → Chat Logs tab: exactly the 2 seeded acme rows; the 5
        // default-tenant rows must not surface.
        await page.goto('/app/admin/logs?tab=chat');
        await expect(page.getByTestId('chat-logs-table')).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-testid^="chat-log-row-"]')).toHaveCount(2);

        // The deployment-wide tabs advertise that the team filter does
        // not apply to them.
        for (const tab of ['app', 'activity', 'failed']) {
            await expect(page.getByTestId(`logs-tab-${tab}-global-badge`)).toBeVisible();
        }
        await expect(page.getByTestId('logs-tab-chat-global-badge')).toHaveCount(0);
    });

    test('a forged X-Tenant-Id for a tenant without membership is rejected with 403', async ({
        page,
    }) => {
        // Real request, real middleware: admin@demo.local has memberships
        // in `default` + `acme` but NOT in `umbrella`, and no
        // tenant.cross-access permission. AuthorizeTenantHeader must
        // answer tenant_forbidden — distinct from a role-based 403.
        await page.goto('/app/admin');
        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });

        const response = await page.request.get('/api/admin/metrics/overview?days=7', {
            headers: { 'X-Tenant-Id': 'umbrella' },
        });

        expect(response.status()).toBe(403);
        const body = (await response.json()) as { error?: string };
        expect(body.error).toBe('tenant_forbidden');
    });
});
