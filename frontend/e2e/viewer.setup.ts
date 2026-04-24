import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const AUTH_FILE = 'playwright/.auth/viewer.json';
const VIEWER_EMAIL = process.env.E2E_VIEWER_EMAIL ?? 'viewer@demo.local';
const VIEWER_PASSWORD = process.env.E2E_VIEWER_PASSWORD ?? 'password';

/*
 * PR6 — Phase F1. Logs in as the DemoSeeder-seeded `viewer@demo.local`
 * account and writes the session to playwright/.auth/viewer.json so
 * the `chromium-viewer` project can exercise RBAC denial flows
 * against the real backend.
 *
 * Runs AFTER auth.setup.ts (chronologically) but Playwright does not
 * enforce ordering between setup projects — keep both idempotent.
 */
setup('authenticate as viewer', async ({ page, request }) => {
    mkdirSync(dirname(AUTH_FILE), { recursive: true });

    // Reset + seed demo data. DemoSeeder now seeds both admin and
    // viewer accounts (see PR6 commit).
    await request.post('/testing/reset');
    await request.post('/testing/seed', { data: { seeder: 'DemoSeeder' } });

    await request.get('/sanctum/csrf-cookie');

    await page.goto('/login');
    await page.getByTestId('login-email').fill(VIEWER_EMAIL);
    await page.getByTestId('login-password').fill(VIEWER_PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL('**/app/**', { timeout: 15_000 });

    await expect(page.getByTestId('appshell-root')).toBeVisible();
    await page.context().storageState({ path: AUTH_FILE });
});
