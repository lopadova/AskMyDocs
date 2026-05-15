import { expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/**
 * v6.1.1 — Playwright proof that the v6.0 cross-mount under
 * `/admin/ai-act-compliance/*` is reachable through host auth and
 * renders the sister-package SPA inside an iframe scaffold.
 *
 * Scope of THIS file: the BOUNDARY the host controls — the cross-mount
 * wrapper, the heading, and the iframe element pointing at the
 * `/admin/ai-act-compliance/embed` entry. The interaction surface of
 * each screen (`tenants-screen` etc.) lives inside the
 * sister-package's compiled SPA assets, which are validated by the
 * package repo's own Playwright suite — re-asserting them here would
 * couple the host CI to the package's build output (R16 violation).
 *
 * R13 note: no external proxy is needed — these scenarios exercise
 * real host routes only.
 */
seededTest.describe('Admin AI Act v1.3 → v1.5 cross-mount reaches the package SPA', () => {
    seededTest('v1.3 — /alerts URL is reachable through the cross-mount wrapper', async ({ page }) => {
        await page.goto('/admin/ai-act-compliance/alerts');
        await assertCrossMountFrame(page);
    });

    seededTest('v1.4 — /regulatory URL is reachable through the cross-mount wrapper', async ({ page }) => {
        await page.goto('/admin/ai-act-compliance/regulatory');
        await assertCrossMountFrame(page);
    });

    seededTest('v1.5 — /tenants URL is reachable through the cross-mount wrapper', async ({ page }) => {
        await page.goto('/admin/ai-act-compliance/tenants');
        await assertCrossMountFrame(page);
    });
});

async function assertCrossMountFrame(page: import('@playwright/test').Page): Promise<void> {
    await expect(page.getByRole('heading', { name: 'AI Act compliance' })).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('iframe').first()).toBeVisible();
    await expect(page.getByRole('link', { name: /Open in new tab/i })).toHaveAttribute(
        'href',
        '/admin/ai-act-compliance/embed',
    );
}
