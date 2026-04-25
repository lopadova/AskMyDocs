import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * PR12 — Phase H1. Admin Log Viewer (read-only) scenarios.
 *
 * Runs against the REAL backend seeded with DemoSeeder. The seeder
 * ships 5 chat_log rows across 2 projects (hr-portal + engineering)
 * with two models (gpt-4o + claude-3-5-sonnet), so the filter
 * assertions have real data to chew on — NO request interception on
 * internal endpoints on the happy path (R13).
 *
 * Failure-path scenarios that require a 5xx response use request
 * interception and flag the stub with the mandatory
 * `R13: failure injection` marker comment on one of the preceding
 * five lines — the only shape the verify-e2e-real-data.sh gate
 * accepts.
 */

test.describe('Admin Log Viewer — Phase H1', () => {
    test('happy — chat logs tab renders seeded rows from DemoSeeder', async ({ page }) => {
        await page.goto('/app/admin/logs');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('logs-view')).toBeVisible();
        await expect(page.getByTestId('logs-tab-chat')).toHaveAttribute('data-active', 'true');

        // DemoSeeder seeds 5 chat_log rows — table settles on `ready`.
        await expect(page.getByTestId('chat-logs')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        const rows = page.locator('[data-testid^="chat-log-row-"]');
        expect(await rows.count()).toBeGreaterThan(0);
    });

    test('happy — filter by model reduces the visible row count', async ({ page }) => {
        await page.goto('/app/admin/logs?tab=chat');
        await expect(page.getByTestId('chat-logs')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const allRows = await page.locator('[data-testid^="chat-log-row-"]').count();
        expect(allRows).toBeGreaterThanOrEqual(2);

        // DemoSeeder's 5 rows mix gpt-4o + claude-3-5-sonnet. Filtering
        // to only claude should leave strictly fewer rows than the full
        // set but still at least one.
        //
        // Copilot #3 fix: instead of `waitForTimeout(500)` (flaky under
        // CI load), wait on the exact network response whose query
        // string matches the applied filter. The DOM is guaranteed to
        // have re-rendered by the time the response resolves, and the
        // deterministic signal survives slow machines without bloating
        // the timeout budget on fast ones.
        await Promise.all([
            page.waitForResponse((response) => {
                const url = new URL(response.url());
                return url.pathname.includes('/api/admin/logs/chat')
                    && url.searchParams.get('model') === 'claude-3-5-sonnet'
                    && response.ok();
            }),
            page.getByTestId('chat-filter-model').fill('claude-3-5-sonnet'),
        ]);
        await expect(page.getByTestId('chat-logs')).toHaveAttribute('data-state', 'ready', {
            timeout: 10_000,
        });

        const claudeRows = await page.locator('[data-testid^="chat-log-row-"]').count();
        expect(claudeRows).toBeGreaterThan(0);
        expect(claudeRows).toBeLessThan(allRows);
    });

    test('happy — application log tab opens and exposes filter controls', async ({ page }) => {
        await page.goto('/app/admin/logs?tab=app');

        await expect(page.getByTestId('logs-panel-app')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('application-log-file')).toBeVisible();
        await expect(page.getByTestId('application-log-level')).toBeVisible();
        await expect(page.getByTestId('application-log-tail')).toBeVisible();
    });

    test('failure — application log invalid filename surfaces 422 error', async ({ page }) => {
        await page.goto('/app/admin/logs?tab=app');
        await expect(page.getByTestId('logs-panel-app')).toBeVisible({ timeout: 15_000 });

        // Type a filename that fails the whitelist regex. Real 422
        // comes back from the backend — NO stub. This is the honest
        // E2E path the verify script rewards.
        await page.getByTestId('application-log-file-custom').fill('../secrets.txt');

        await expect(page.getByTestId('application-log-error')).toBeVisible({
            timeout: 15_000,
        });
        const text = await page.getByTestId('application-log-error').textContent();
        expect(text ?? '').toContain('422');
    });

    test('failure — chat logs 500 injection surfaces data-state=error', async ({ page }) => {
        /* R13: failure injection — real path tested in "happy — chat logs tab renders seeded rows". */
        await page.route('**/api/admin/logs/chat**', (route) =>
            route.fulfill({ status: 500, body: '{"message":"boom"}', contentType: 'application/json' }),
        );

        await page.goto('/app/admin/logs?tab=chat');

        await expect(page.getByTestId('chat-logs-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('chat-logs')).toHaveAttribute('data-state', 'error');
    });

    test('happy — audit tab renders and failed-jobs tab shows clean state', async ({ page }) => {
        await page.goto('/app/admin/logs?tab=audit');
        await expect(page.getByTestId('logs-panel-audit')).toBeVisible({ timeout: 15_000 });

        // Audit rows depend on DemoSeeder writing at least one row;
        // accept either `ready` (seeded row) or `empty` (no rows).
        const auditState = await page
            .getByTestId('audit-logs')
            .getAttribute('data-state', { timeout: 15_000 });
        expect(['ready', 'empty']).toContain(auditState);

        await page.getByTestId('logs-tab-failed').click();
        await expect(page.getByTestId('failed-jobs')).toBeVisible({ timeout: 15_000 });
        // Clean DemoSeeder stack has zero failed jobs. Wait for the
        // panel to settle out of `loading` before sampling — the raw
        // attribute read otherwise races with TanStack Query's first
        // fetch and reports the transient loading state.
        await expect(page.getByTestId('failed-jobs')).toHaveAttribute(
            'data-state',
            /^(empty|ready)$/,
            { timeout: 15_000 },
        );
    });
});
