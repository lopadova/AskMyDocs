import { test, expect } from '@playwright/test';

/*
 * PR12 — Phase H1. Admin Log Viewer RBAC denial scenarios.
 *
 * Runs under the `chromium-viewer` Playwright project (viewer.setup.ts
 * storage state). Same split as admin-dashboard-viewer.spec.ts /
 * admin-kb-viewer.spec.ts — a viewer must NEVER see the log panels,
 * and the backend must also return 403 for direct API calls.
 */

test.describe('Admin Log Viewer — viewer (RBAC denied)', () => {
    test('viewer hitting /app/admin/logs sees admin-forbidden', async ({ page }) => {
        await page.goto('/app/admin/logs');

        await expect(page.getByTestId('admin-forbidden')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('logs-view')).toHaveCount(0);
        await expect(page.getByTestId('chat-logs')).toHaveCount(0);
    });

    test('viewer API call to /api/admin/logs/chat returns 403', async ({ request }) => {
        const response = await request.get('/api/admin/logs/chat');
        expect(response.status()).toBe(403);
    });
});
