import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.7/W3–W4 — Doc Insights (AI document-change analyses) admin view.
 *
 * R13: Two of the three tests below run against the real `/api/admin/kb/analyses`
 * endpoint backed by the real DB. The third test uses a deliberate failure
 * injection (R13: failure injection) to exercise the error-state branch
 * deterministically — it stubs a single request to return 503 so the error
 * element is guaranteed to render regardless of DB state.
 * (The analyses themselves are produced by the async AnalyzeDocumentChangeJob,
 * which calls the AI provider — that external call is NOT exercised here; this
 * spec covers the read/display contract.)
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Doc Insights', () => {
    test('admin lands on /app/admin/kb/insights and the view never crashes', async ({ page }) => {
        await page.goto('/app/admin/kb/insights');
        await expect(page.getByTestId('admin-kb-insights-view')).toBeVisible({ timeout: 15_000 });
        const empty = page.getByTestId('admin-kb-insights-empty');
        const list = page.getByTestId('admin-kb-insights-list');
        // Exactly one of empty/list must resolve — the view is never blank.
        await expect(empty.or(list)).toBeVisible({ timeout: 15_000 });
    });

    test('the status filter re-queries without crashing the view', async ({ page }) => {
        await page.goto('/app/admin/kb/insights');
        await expect(page.getByTestId('admin-kb-insights-view')).toBeVisible({ timeout: 15_000 });

        const analysesCall = page.waitForResponse(
            (r) => r.url().includes('/api/admin/kb/analyses') && r.url().includes('status=failed'),
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-kb-insights-status-filter').selectOption('failed');
        const resp = await analysesCall;
        expect(resp.ok()).toBeTruthy();

        // View still resolves to a real state after the filtered refetch.
        const empty = page.getByTestId('admin-kb-insights-empty');
        const list = page.getByTestId('admin-kb-insights-list');
        await expect(empty.or(list)).toBeVisible({ timeout: 15_000 });
    });

    // R13: failure injection — stubs ONE request to /api/admin/kb/analyses
    // returning 503 so the error-state branch renders deterministically.
    // The preceding two tests run against the real endpoint; this test is
    // the explicit failure-path coverage required by R12.
    test('shows error state when the analyses endpoint returns 503', async ({ page }) => {
        // R13: failure injection
        await page.route('**/api/admin/kb/analyses**', (route) =>
            route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Service unavailable' }) }),
        );

        await page.goto('/app/admin/kb/insights');

        await expect(page.getByTestId('admin-kb-insights-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-kb-insights-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-kb-insights-error')).toHaveAttribute('data-state', 'error');
    });
});
