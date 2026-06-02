import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.7/W3–W4 — Doc Insights (AI document-change analyses) admin view.
 *
 * R13: read-only against the real `/api/admin/kb/analyses` endpoint backed
 * by the real DB. ZERO route stubs — the view either renders seeded
 * analyses or the empty state, and never crashes. (The analyses themselves
 * are produced by the async AnalyzeDocumentChangeJob, which calls the AI
 * provider — that external call is NOT exercised here; this spec covers the
 * read/display contract.)
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
});
