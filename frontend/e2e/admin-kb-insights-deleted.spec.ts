import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * v8.8/W2 — Doc Insights: the DELETE-trigger obsolescence-impact analysis.
 *
 * R13: the happy path runs against the REAL `/api/admin/kb/analyses` endpoint
 * backed by the real DB. `KbDeletionInsightSeeder` inserts a soft-deleted
 * canonical document + a completed `kb_doc_analyses` row with
 * trigger='deleted' (no async job / no AI provider needed — the read/display
 * contract for the new trigger value is what we cover here). The failure path
 * uses a deterministic 503 injection.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Doc Insights — deletion-impact analysis', () => {
    test('renders a deleted-trigger card with the impacted doc (real data)', async ({ page, request }) => {
        await seedDb(request, 'KbDeletionInsightSeeder');

        await page.goto('/app/admin/kb/insights');
        await expect(page.getByTestId('admin-kb-insights-view')).toBeVisible({ timeout: 15_000 });

        const list = page.getByTestId('admin-kb-insights-list');
        await expect(list).toBeVisible({ timeout: 15_000 });

        // The deleted document surfaces with its DELETED trigger label and the
        // obsolescence-impact advice for the doc that referenced it.
        await expect(list).toContainText('Cache decision v1 (removed)');
        await expect(list).toContainText(/deleted/i);
        await expect(list).toContainText('dangling reference to the deleted decision');
        await expect(list).toContainText('drop the link to dec-cache-v1');
    });

    // R13: failure injection — the panel must show its error state (not empty)
    // when the analyses endpoint fails, so the deletion card never silently
    // disappears behind a "no analyses yet" message.
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
