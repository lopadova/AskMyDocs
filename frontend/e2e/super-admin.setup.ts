import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const AUTH_FILE = 'playwright/.auth/super-admin.json';
const SUPER_EMAIL = process.env.E2E_SUPER_ADMIN_EMAIL ?? 'super@demo.local';
const SUPER_PASSWORD = process.env.E2E_SUPER_ADMIN_PASSWORD ?? 'password';

/*
 * PR13 / Phase H2 — super-admin setup.
 *
 * Logs in as DemoSeeder's `super@demo.local` via the JSON API and
 * persists session state to playwright/.auth/super-admin.json. The
 * `chromium-super-admin` project picks this up via storageState.
 *
 * We need a DEDICATED super-admin account (not just reusing the admin
 * one) because:
 *
 *   - The admin role maps to `commands.run` (non-destructive only).
 *   - The super-admin role maps to `commands.run` + `commands.destructive`.
 *
 * To exercise destructive command flows in Playwright (kb:prune-deleted,
 * kb:prune-orphan-files, etc.) we must be authenticated as a user that
 * the RbacSeeder tagged super-admin. DemoSeeder handles that.
 *
 * See auth.setup.ts for the rationale on API-based auth.
 *
 * Runs after auth.setup.ts / viewer.setup.ts in CI but Playwright does
 * not enforce ordering across setup projects — keep all three idempotent
 * against /testing/reset.
 */
setup('authenticate as super-admin', async ({ page, request, context }) => {
    mkdirSync(dirname(AUTH_FILE), { recursive: true });

    await request.post('/testing/reset');
    await request.post('/testing/seed', { data: { seeder: 'DemoSeeder' } });

    await request.get('/sanctum/csrf-cookie');

    const cookies = await context.cookies();
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrfCookie) {
        throw new Error('XSRF-TOKEN cookie missing after /sanctum/csrf-cookie');
    }

    const loginResponse = await request.post('/api/auth/login', {
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
