import { test as baseTest, expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/*
 * v8.16/W4 — AI FinOps admin SPA.
 *
 * Unlike the host-React cross-mounts (pii-redactor / eval-harness, served under
 * /app/admin/*), the `padosoft/laravel-ai-finops-admin` package mounts its OWN
 * self-contained React SPA via a Blade view at the TOP-LEVEL prefix
 * /admin/ai-finops, with its own prebuilt Vite assets (published in CI via
 * `php artisan vendor:publish --tag=ai-finops-admin-assets --force`). The route
 * registers ONLY when AI_FINOPS_ADMIN_ENABLED=true (set in playwright.config
 * webServer env). The default-OFF clean-404 landing is covered by
 * FinOpsDisabledTest (phpunit) — R43 both states. The route gate
 * (auth + can:viewAiFinOps → super-admin + admin) is locked by
 * FinOpsAdminMountingTest (phpunit).
 *
 * R13: real backend, real Sanctum cookies, no page.route — the BE responses ARE
 * the assertion. The package SPA is the package's own test surface; the HOST's
 * concern here is the integration boundary: an allowed role reaches the served
 * shell, a denied role never does.
 */
seededTest.describe('Admin AI FinOps — admin (package-served SPA shell)', () => {
    seededTest('happy — admin reaches the served /admin/ai-finops SPA shell', async ({ page }) => {
        // Establish the admin session first (the seeded fixture logs in as admin
        // inside /app), then navigate to the top-level package route. The Sanctum
        // session cookie is path "/", so it carries to the package route.
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        const resp = await page.goto('/admin/ai-finops');
        expect(resp?.status()).toBe(200);

        // The package Blade shell always renders its mount node + bootstrap,
        // independent of whether the SPA JS has hydrated — a stable marker that
        // the gated route served the panel (and did not redirect to /login).
        await expect(page.locator('#aifinops-admin')).toBeAttached();
        await expect(page).toHaveURL(/\/admin\/ai-finops$/);
    });
});

baseTest.describe('Admin AI FinOps — RBAC (viewer denied)', () => {
    baseTest.use({ storageState: 'playwright/.auth/viewer.json' });

    /*
     * R13: failure injection on the package mount URL. The BE Gate is the
     * load-bearing defence. With the route registered (env=true) a viewer is
     * denied by `can:viewAiFinOps` with a 403; an expired viewer session
     * redirects to /login (302). Either proves the panel is NOT served to a
     * viewer — never a 200 serving the shell. maxRedirects:0 keeps the 302
     * observable instead of following it to a 200 login page.
     */
    baseTest('viewer GET /admin/ai-finops is not served (403 gate / 302 login)', async ({ request }) => {
        const response = await request.get('/admin/ai-finops', { maxRedirects: 0 });

        expect([403, 302]).toContain(response.status());
        if (response.status() === 200) {
            // Defensive: if a 200 ever slips through, prove it is not the panel.
            expect(await response.text()).not.toContain('aifinops-admin');
        }
    });
});
