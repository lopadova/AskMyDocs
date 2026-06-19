import { test as baseTest, expect, type Page } from '@playwright/test';
import { test as seededTest } from './fixtures';
import { resetDb, seedDb } from './setup-helpers';

const PASSWORD = 'password';

// Inline login (CSRF + /api/auth/login), mirroring role-access.spec.ts. Used by
// the viewer-denied test so it establishes a FRESH, valid viewer session and the
// gated GET deterministically hits the `can:viewAiFinOps` 403 — never a stale
// "logged-out" 302 that would green-pass for the wrong reason (R16).
async function loginAs(page: Page, email: string): Promise<void> {
    await page.request.get('/sanctum/csrf-cookie');
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrf) {
        throw new Error('XSRF-TOKEN cookie missing after /sanctum/csrf-cookie');
    }
    const res = await page.request.post('/api/auth/login', {
        data: { email, password: PASSWORD },
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'application/json' },
    });
    if (!res.ok()) {
        throw new Error(`Login failed for ${email}: ${res.status()} ${await res.text()}`);
    }
}

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
    // Seed fresh + log in as the seeded viewer so the gate (not a stale session
    // or leftover cross-test data) is what we observe. resetDb()+seedDb() ALWAYS
    // wipe via /testing/reset even under CI's E2E_SKIP_HTTP_RESET=1 (unlike the
    // setup-time resetAndSeed(), which honours the skip) — so the baseline is
    // clean and the 403 is evaluated deterministically (R16). This file runs in
    // the `chromium` (admin) project, so the inline login makes the role explicit.
    baseTest.beforeEach(async ({ page }) => {
        await resetDb(page);
        await seedDb(page);
        await loginAs(page, 'viewer@demo.local');
    });

    /*
     * R13: real-backend failure path. The BE Gate is the load-bearing defence.
     * With the route registered (env=true) and a VALID viewer session, the only
     * thing standing between the viewer and the panel is `can:viewAiFinOps` —
     * so the response is a hard 403. maxRedirects:0 keeps any redirect observable
     * rather than following it to a 200 login page that would mask the result.
     */
    baseTest('viewer GET /admin/ai-finops is denied by the viewAiFinOps gate (403)', async ({ page }) => {
        const response = await page.request.get('/admin/ai-finops', { maxRedirects: 0 });

        expect(response.status()).toBe(403);
    });
});
