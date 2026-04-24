import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const AUTH_FILE = 'playwright/.auth/admin.json';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'admin@demo.local';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'password';

/*
 * One-time authentication. Resets the demo DB, logs in with the admin
 * credentials seeded by DemoSeeder, and persists session state so the
 * chromium project can reuse it across tests.
 */
setup('authenticate as admin', async ({ page, request }) => {
    // Ensure destination directory exists before storageState tries to write.
    mkdirSync(dirname(AUTH_FILE), { recursive: true });

    // Reset + seed demo data. Both endpoints are guarded by APP_ENV=testing.
    await request.post('/testing/reset');
    await request.post('/testing/seed', { data: { seeder: 'DemoSeeder' } });

    // Prime the CSRF cookie like the SPA does on boot, then log in.
    await request.get('/sanctum/csrf-cookie');

    await page.goto('/login');
    await page.getByTestId('login-email').fill(ADMIN_EMAIL);
    await page.getByTestId('login-password').fill(ADMIN_PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL('**/app/**', { timeout: 15_000 });

    await expect(page.getByTestId('appshell-root')).toBeVisible();
    await page.context().storageState({ path: AUTH_FILE });
});
