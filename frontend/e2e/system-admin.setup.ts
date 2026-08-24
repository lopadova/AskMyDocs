import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { resetAndSeed } from './setup-helpers';

const AUTH_FILE = 'playwright/.auth/system-admin.json';
const EMAIL = process.env.E2E_SYSTEM_ADMIN_EMAIL ?? 'system@demo.local';
const PASSWORD = process.env.E2E_SYSTEM_ADMIN_PASSWORD ?? 'password';

setup('authenticate as system administrator', async ({ page, context }) => {
    mkdirSync(dirname(AUTH_FILE), { recursive: true });
    await resetAndSeed(page);
    await page.request.get('/sanctum/csrf-cookie');

    const xsrf = (await context.cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    if (!xsrf) throw new Error('XSRF-TOKEN cookie missing for system-admin setup.');

    const response = await page.request.post('/api/auth/login', {
        data: { email: EMAIL, password: PASSWORD },
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrf.value),
            Accept: 'application/json',
        },
    });
    if (!response.ok()) {
        throw new Error(`System-admin login failed: ${response.status()} ${await response.text()}`);
    }

    await page.goto('/app');
    await expect(page.getByTestId('appshell-root')).toBeVisible();
    await page.context().storageState({ path: AUTH_FILE });
});
