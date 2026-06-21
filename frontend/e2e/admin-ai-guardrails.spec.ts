import { test as baseTest, expect, type Page } from '@playwright/test';
import { test as seededTest } from './fixtures';
import { resetDb, seedDb } from './setup-helpers';

const PASSWORD = 'password';

// Inline login (CSRF + /api/auth/login), mirroring admin-ai-finops.spec.ts. The
// viewer-denied test establishes a FRESH, valid viewer session so the gated GET
// deterministically hits the `can:viewAiGuardrails` 403 — never a stale
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
 * v8.19/W3 — AI Guardrails admin SPA.
 *
 * Like finops-admin (and unlike the host-React cross-mounts), the
 * `padosoft/laravel-ai-guardrails-admin` package mounts its OWN self-contained
 * React SPA via a Blade view at the TOP-LEVEL prefix /admin/ai-guardrails, with
 * its own prebuilt Vite assets (published in CI via
 * `php artisan vendor:publish --tag=ai-guardrails-admin-assets --force`). The
 * package mounts the route unconditionally; the host `guardrails-admin.enabled`
 * middleware 404s it unless AI_GUARDRAILS_ADMIN_ENABLED=true (set in the CI
 * server env). The default-OFF clean-404 landing + the route gate
 * (web + auth + can:viewAiGuardrails) are locked by GuardrailsAdminMountingTest
 * (phpunit) — R43 both states.
 *
 * R13: real backend, real Sanctum cookies, no page.route — the BE responses ARE
 * the assertion. The package SPA is the package's own test surface; the HOST's
 * concern here is the integration boundary: an allowed role reaches the served
 * shell, a denied role never does.
 */
seededTest.describe('Admin AI Guardrails — admin (package-served SPA shell)', () => {
    seededTest('happy — admin reaches the served /admin/ai-guardrails SPA shell', async ({ page }) => {
        // Establish the admin session first (the seeded fixture logs in as admin
        // inside /app), then navigate to the top-level package route. The Sanctum
        // session cookie is path "/", so it carries to the package route.
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        const resp = await page.goto('/admin/ai-guardrails');
        expect(resp?.status()).toBe(200);

        // The gated route served the package shell (no redirect to /login).
        await expect(page.locator('#agr-root')).toBeAttached();
        await expect(page).toHaveURL(/\/admin\/ai-guardrails$/);

        // Prove the REAL bundle hydrated, not the "assets not built" fallback:
        // the Blade renders `data-testid="agr-assets-missing"` inside #agr-root
        // when the manifest is absent, and the React app replaces it on mount.
        // Asserting it is GONE catches a CI asset-publish drift (R16 — the mount
        // node alone is present in BOTH states, so it cannot be the only signal).
        await expect(page.getByTestId('agr-assets-missing')).toHaveCount(0, { timeout: 15_000 });

        // Real-data (R13): the dashboard fetches the core API
        // (GET /api/admin/ai-guardrails/overview) and renders the FOUR control
        // cards (input screen / output handler / tool firewall / HITL). Asserting
        // the exact count proves assets + hydration + the secured core API
        // end-to-end — and would catch 3/4 cards silently failing to render (R16).
        await expect(page.getByTestId('agr-control-card').first()).toBeVisible({ timeout: 15_000 });
        // Same explicit timeout as the visibility wait above — the default
        // (shorter) timeout could flake on a slow CI runner mid-hydration.
        await expect(page.getByTestId('agr-control-card')).toHaveCount(4, { timeout: 15_000 });
    });
});

baseTest.describe('Admin AI Guardrails — RBAC (viewer denied)', () => {
    // Seed fresh + log in as the seeded viewer so the gate (not a stale session
    // or leftover cross-test data) is what we observe. resetDb()+seedDb() ALWAYS
    // wipe via /testing/reset even under CI's E2E_SKIP_HTTP_RESET=1, so the
    // baseline is clean and the 403 is evaluated deterministically (R16).
    baseTest.beforeEach(async ({ page }) => {
        await resetDb(page);
        await seedDb(page);
        await loginAs(page, 'viewer@demo.local');
    });

    /*
     * R13: real-backend failure path. With the route registered (env=true) and a
     * VALID viewer session, the only thing standing between the viewer and the
     * panel is `can:viewAiGuardrails` — so the response is a hard 403.
     * maxRedirects:0 keeps any redirect observable rather than following it to a
     * 200 login page that would mask the result.
     */
    baseTest('viewer GET /admin/ai-guardrails is denied by the viewAiGuardrails gate (403)', async ({ page }) => {
        const response = await page.request.get('/admin/ai-guardrails', { maxRedirects: 0 });

        expect(response.status()).toBe(403);
    });
});
