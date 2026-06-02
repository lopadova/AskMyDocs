import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * v8.8/W4 — Content Gaps admin screen (questions the KB could not answer).
 *
 * R13: the happy path runs against the REAL `/api/admin/kb/content-gaps`
 * endpoint backed by the real DB (`KbContentGapSeeder` inserts ranked rows +
 * one resolved). The resolve flow exercises the real PATCH. The failure path
 * injects a 503 on the GET.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Content Gaps', () => {
    test('ranks gaps by frequency and resolves one (real data)', async ({ page, request }) => {
        await seedDb(request, 'KbContentGapSeeder');

        await page.goto('/app/admin/kb/content-gaps');
        await expect(page.getByTestId('admin-content-gaps-view')).toBeVisible({ timeout: 15_000 });
        const list = page.getByTestId('admin-content-gaps-list');
        await expect(list).toBeVisible({ timeout: 15_000 });

        // The most-asked unanswered question (17×) surfaces with its count.
        await expect(list).toContainText('How do I rotate the signing key in production?');
        await expect(list).toContainText('17×');

        // Resolve the top gap via the real PATCH endpoint.
        const topRow = page.locator('[data-testid^="admin-content-gap-"][data-resolved="false"]').first();
        const resolveBtn = topRow.getByRole('button', { name: /resolve gap/i });
        const patchResp = page.waitForResponse(
            (r) => /\/api\/admin\/kb\/content-gaps\/\d+\/resolve/.test(r.url()) && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await resolveBtn.click();
        const resp = await patchResp;
        expect(resp.ok()).toBeTruthy();
    });

    // R13: failure injection — stubs the GET to 503 so the error-state branch
    // renders deterministically. The happy path above exercises real data.
    test('shows error state when the content-gaps endpoint returns 503', async ({ page }) => {
        // R13: failure injection
        await page.route('**/api/admin/kb/content-gaps**', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Service unavailable' }) });
            }
            return route.continue();
        });

        await page.goto('/app/admin/kb/content-gaps');
        await expect(page.getByTestId('admin-content-gaps-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-content-gaps-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-content-gaps-error')).toHaveAttribute('data-state', 'error');
    });
});
