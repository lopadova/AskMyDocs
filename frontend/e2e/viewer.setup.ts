import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const AUTH_FILE = 'playwright/.auth/viewer.json';
const VIEWER_EMAIL = process.env.E2E_VIEWER_EMAIL ?? 'viewer@demo.local';
const VIEWER_PASSWORD = process.env.E2E_VIEWER_PASSWORD ?? 'password';

/*
 * PR6 — Phase F1. Logs in as the DemoSeeder-seeded `viewer@demo.local`
 * via the JSON API and writes the session to playwright/.auth/viewer.json
 * so the `chromium-viewer` project can exercise RBAC denial flows
 * against the real backend.
 *
 * See auth.setup.ts for the rationale on API-based auth (vs driving the
 * legacy Blade form).
 *
 * Runs AFTER auth.setup.ts (chronologically) but Playwright does not
 * enforce ordering between setup projects — keep both idempotent.
 */
setup('authenticate as viewer', async ({ page, request, context }) => {
    mkdirSync(dirname(AUTH_FILE), { recursive: true });

    // Reset + seed demo data. DemoSeeder seeds admin + viewer + super-admin.
    await request.post('/testing/reset');
    await request.post('/testing/seed', { data: { seeder: 'DemoSeeder' } });

    await request.get('/sanctum/csrf-cookie');

    const cookies = await context.cookies();
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrfCookie) {
        throw new Error('XSRF-TOKEN cookie missing after /sanctum/csrf-cookie');
    }

    const loginResponse = await request.post('/api/auth/login', {
        data: { email: VIEWER_EMAIL, password: VIEWER_PASSWORD },
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value),
            Accept: 'application/json',
        },
    });
    if (!loginResponse.ok()) {
        throw new Error(
            `Login failed for ${VIEWER_EMAIL}: ${loginResponse.status()} ${await loginResponse.text()}`,
        );
    }

    await page.goto('/app/chat');
    await expect(page.getByTestId('appshell-root')).toBeVisible();
    await page.context().storageState({ path: AUTH_FILE });
});
