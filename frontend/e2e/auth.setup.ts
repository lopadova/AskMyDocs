import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { resetAndSeed } from './setup-helpers';

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
    // Setup runs `migrate:fresh` + DemoSeeder over HTTP — under php -S
    // single-threaded backend the cycle takes 15-25s. Bump from the
    // 20s default so we're not racing the seeder boot under local runs.
    setup.setTimeout(120_000);
    mkdirSync(dirname(AUTH_FILE), { recursive: true });

    // Reset + seed demo data. Both endpoints are guarded by APP_ENV=testing.
    // The shared `resetAndSeed` helper retries each call up to 8× at
    // 1500ms intervals to cover the `php artisan serve` boot race and
    // the post-reset slot when artisan briefly stops accepting new
    // connections. Surfacing non-2xx loudly here keeps a silent seeder
    // failure from manifesting downstream as an opaque
    // "credentials don't match our records" 422 (PR #33 mode).
    await resetAndSeed(page);

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
