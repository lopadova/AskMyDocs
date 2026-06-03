import { expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/**
 * AI Act compliance — native overview (replaces the v6.0 iframe cross-mount).
 *
 * The v6.0 design iframed the standalone `laravel-ai-act-compliance-admin`
 * SPA at `/admin/ai-act-compliance/embed`. That package is a frontend-only
 * prototype (mock data, no servable Laravel bundle), and the host
 * `/admin/ai-act-compliance/{any?}` placeholder route redirected the iframe
 * target back into the host SPA — so the iframe re-rendered this view's
 * iframe, recursing indefinitely. The old spec here asserted only that an
 * `<iframe>` element existed (deliberately not inspecting its contents),
 * which is exactly why the recursion shipped unnoticed.
 *
 * The view now renders a NATIVE overview backed by the real core-package
 * endpoints `/api/admin/ai-act-compliance/*` — live counts per compliance
 * register, no iframe, no recursion. These scenarios assert that real
 * behaviour (R16): the panel reaches `ready` and renders the six register
 * cards with live counts. The deep `/admin/ai-act-compliance/<suffix>`
 * URLs still resolve to the panel through the host redirect + SPA splat
 * route.
 *
 * R13 note: host routes only; no external proxy. The card data comes from
 * the real seeded database via the core package controllers.
 */
seededTest.describe('Admin AI Act compliance — native live overview', () => {
    seededTest('renders the six compliance register cards with live counts', async ({ page }) => {
        await page.goto('/app/admin/ai-act-compliance');

        const panel = page.getByTestId('admin-ai-act-compliance');
        await expect(panel).toBeVisible({ timeout: 15_000 });
        await expect(panel).toHaveAttribute('data-state', 'ready');
        await expect(page.getByRole('heading', { name: 'AI Act compliance' })).toBeVisible();

        // No recursive iframe any more — the page is native.
        await expect(page.locator('iframe')).toHaveCount(0);

        for (const key of ['incidents', 'dsar', 'consent', 'bias', 'attestations', 'human-reviews']) {
            await expect(page.getByTestId(`admin-ai-act-card-${key}`)).toBeVisible();
            // Count renders as a number (not the loading em-dash) once ready.
            await expect(page.getByTestId(`admin-ai-act-count-${key}`)).toHaveText(/^\d+$/);
        }
    });

    seededTest('deep cross-mount URL still resolves to the native panel', async ({ page }) => {
        // /tenants was a package screen route in v6.0; it now resolves through
        // the host redirect + SPA splat to the same native overview.
        await page.goto('/admin/ai-act-compliance/tenants');

        await expect(page.getByTestId('admin-ai-act-compliance')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByRole('heading', { name: 'AI Act compliance' })).toBeVisible();
    });

    seededTest('shows error state when the compliance API is unavailable', async ({ page }) => {
        // R13: failure injection — intercepts the internal compliance API to
        // simulate a backend outage (503) so the FE error branch is exercised.
        // The happy-path variant above already covers the real-data flow.
        await page.route('**/api/admin/ai-act-compliance/**', (route) =>
            // R13: failure injection
            route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"Service unavailable"}' }),
        );

        await page.goto('/app/admin/ai-act-compliance');

        const panel = page.getByTestId('admin-ai-act-compliance');
        await expect(panel).toBeVisible({ timeout: 15_000 });
        await expect(panel).toHaveAttribute('data-state', 'error');
        await expect(page.getByTestId('admin-ai-act-compliance-error')).toBeVisible();
    });
});
