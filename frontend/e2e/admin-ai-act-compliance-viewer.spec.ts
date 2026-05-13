import { test, expect } from '@playwright/test';

/*
 * v6.0 — AI Act compliance scaffold RBAC denial.
 *
 * Runs under the `chromium-viewer` Playwright project (viewer.setup.ts
 * storage state). Mirrors the other *-viewer specs: no seeded auto-fixture,
 * so the authenticated viewer session created by viewer.setup stays intact.
 */

test.describe('Admin AI Act compliance scaffold — viewer (RBAC denied)', () => {
    test('viewer hitting /app/admin/ai-act-compliance sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/ai-act-compliance');

        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-ai-act-compliance')).toHaveCount(0);
    });

    test('viewer GET /admin/ai-act-compliance rejects with 403', async ({ request }) => {
        const response = await request.get('/admin/ai-act-compliance');

        expect(response.status()).toBe(403);
    });
});
