import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const AUTH_FILE = 'playwright/.auth/admin.json';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'admin@demo.local';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'password';

/*
 * One-time authentication. Resets the demo DB, logs in via the JSON
 * `/api/auth/login` endpoint (the actual SPA flow) and persists the
 * Sanctum session cookies so the chromium project can reuse them.
 *
 * Why API-based instead of driving the legacy Blade `/login` form:
 *
 *   - The SPA's TanStack Router has `/login` mounted at the root level
 *     but Laravel still serves the legacy Blade auth.login view at
 *     server-side `/login`. Tests written against React testids
 *     wouldn't see them on the Blade form, and porting CSRF token
 *     handling between `request` and `page` contexts in CI proved
 *     fragile (15s waitForURL timeouts in headless chromium).
 *   - The JSON API path is the production user flow — driving it
 *     directly is more honest than a UI proxy in setup.
 *   - Cookies set by `request.get('/sanctum/csrf-cookie')` and
 *     `request.post('/api/auth/login')` propagate to `page.goto(...)`
 *     because `request` shares the browser context.
 */
setup('authenticate as admin', async ({ page, request, context }) => {
    mkdirSync(dirname(AUTH_FILE), { recursive: true });

    // Reset + seed demo data. Both endpoints are guarded by APP_ENV=testing.
    await request.post('/testing/reset');
    await request.post('/testing/seed', { data: { seeder: 'DemoSeeder' } });

    // Sanctum stateful SPA flow:
    //   1. GET /sanctum/csrf-cookie sets `XSRF-TOKEN` + `laravel_session`
    //   2. POST /api/auth/login with the URL-decoded XSRF in the
    //      `X-XSRF-TOKEN` header and the cookies sent automatically
    await request.get('/sanctum/csrf-cookie');

    const cookies = await context.cookies();
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrfCookie) {
        throw new Error(
            'XSRF-TOKEN cookie missing after /sanctum/csrf-cookie — sanctum config or session driver is broken in CI',
        );
    }

    const loginResponse = await request.post('/api/auth/login', {
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
