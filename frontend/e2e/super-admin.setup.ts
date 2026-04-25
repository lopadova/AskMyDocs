import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const AUTH_FILE = 'playwright/.auth/super-admin.json';
const SUPER_EMAIL = process.env.E2E_SUPER_ADMIN_EMAIL ?? 'super@demo.local';
const SUPER_PASSWORD = process.env.E2E_SUPER_ADMIN_PASSWORD ?? 'password';

/*
 * PR13 / Phase H2 — super-admin setup.
 *
 * Logs in via the JSON `/api/auth/login` endpoint and persists session
 * state to playwright/.auth/super-admin.json. The `chromium-super-admin`
 * project picks this up via storageState.
 *
 * Uses `page.request` (not the top-level `request` fixture) so the
 * XSRF cookie shares the same jar as `page.goto(...)` — see
 * auth.setup.ts for the full rationale.
 *
 * We need a DEDICATED super-admin account because:
 *   - admin role        → commands.run (non-destructive only)
 *   - super-admin role  → commands.run + commands.destructive
 *
 * Destructive command flows (kb:prune-deleted, kb:prune-orphan-files,
 * etc.) require the super-admin storage state.
 */
setup('authenticate as super-admin', async ({ page, context }) => {
    mkdirSync(dirname(AUTH_FILE), { recursive: true });

    await page.request.post('/testing/reset');
    await page.request.post('/testing/seed', { data: { seeder: 'DemoSeeder' } });

    await page.request.get('/sanctum/csrf-cookie');

    const cookies = await context.cookies();
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrfCookie) {
        throw new Error('XSRF-TOKEN cookie missing after /sanctum/csrf-cookie');
    }

    const loginResponse = await page.request.post('/api/auth/login', {
        data: { email: SUPER_EMAIL, password: SUPER_PASSWORD },
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value),
            Accept: 'application/json',
        },
    });
    if (!loginResponse.ok()) {
        throw new Error(
            `Login failed for ${SUPER_EMAIL}: ${loginResponse.status()} ${await loginResponse.text()}`,
        );
    }

    await page.goto('/app/chat');
    await expect(page.getByTestId('appshell-root')).toBeVisible();
    await page.context().storageState({ path: AUTH_FILE });
});
