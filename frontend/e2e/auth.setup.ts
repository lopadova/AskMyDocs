import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const AUTH_FILE = 'playwright/.auth/admin.json';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'admin@demo.local';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'password';

/*
 * One-time authentication.
 *
 * Uses `page.request` (NOT the top-level `request` fixture) for every
 * HTTP call: only `page.request` is guaranteed to share its cookie jar
 * with the page navigation that follows. The top-level `request`
 * fixture in Playwright is its own APIRequestContext with a separate
 * jar — XSRF-TOKEN set there won't be visible to `page.goto(...)`.
 *
 * Sanctum stateful SPA flow:
 *   1. POST /testing/reset                  (env-gated, drops + recreates DB)
 *   2. POST /testing/seed { DemoSeeder }    (env-gated, seeds users)
 *   3. GET  /sanctum/csrf-cookie            (sets XSRF-TOKEN + laravel_session)
 *   4. POST /api/auth/login + X-XSRF-TOKEN  (returns 200, fixates session)
 *   5. page.goto('/app/chat')               (verifies SPA renders for the auth user)
 *   6. context.storageState({ path })       (persists cookies for downstream projects)
 */
setup('authenticate as admin', async ({ page, context }) => {
    mkdirSync(dirname(AUTH_FILE), { recursive: true });

    // Reset + seed demo data. Both endpoints are guarded by APP_ENV=testing.
    // Surface non-2xx loudly so a silent seeder failure doesn't manifest
    // downstream as an opaque "credentials don't match our records" 422
    // (the error mode caught on PR #33's previous run).
    //
    // Retry the first /testing/reset on socket-hang-up — `php artisan
    // serve`'s built-in PHP server occasionally drops the first POST
    // after boot if Laravel is still finishing its bootstrap. The
    // workflow now runs `php artisan optimize` to pre-warm caches but
    // a defensive retry covers the residual race.
    let resetResponse;
    for (let attempt = 0; attempt < 3; attempt++) {
        try {
            resetResponse = await page.request.post('/testing/reset');
            break;
        } catch (err: unknown) {
            const message = err instanceof Error ? err.message : String(err);
            if (attempt === 2) {
                throw new Error(
                    `/testing/reset failed after 3 attempts: ${message}`,
                );
            }
            // Brief sleep — give artisan serve a moment to recover.
            await new Promise((r) => setTimeout(r, 500));
        }
    }
    if (!resetResponse || !resetResponse.ok()) {
        throw new Error(
            `/testing/reset failed: ${resetResponse?.status()} ${await resetResponse?.text()}`,
        );
    }
    const seedResponse = await page.request.post('/testing/seed', {
        data: { seeder: 'DemoSeeder' },
    });
    if (!seedResponse.ok()) {
        throw new Error(
            `/testing/seed failed: ${seedResponse.status()} ${await seedResponse.text()}`,
        );
    }

    await page.request.get('/sanctum/csrf-cookie');

    const cookies = await context.cookies();
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrfCookie) {
        throw new Error(
            'XSRF-TOKEN cookie missing after /sanctum/csrf-cookie — sanctum config or session driver is broken in CI',
        );
    }

    const loginResponse = await page.request.post('/api/auth/login', {
        data: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD },
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value),
            Accept: 'application/json',
        },
    });
    if (!loginResponse.ok()) {
        throw new Error(
            `Login failed for ${ADMIN_EMAIL}: ${loginResponse.status()} ${await loginResponse.text()}`,
        );
    }

    // Verify the SPA shell renders for the authenticated session.
    await page.goto('/app/chat');
    await expect(page.getByTestId('appshell-root')).toBeVisible();
    await page.context().storageState({ path: AUTH_FILE });
});
