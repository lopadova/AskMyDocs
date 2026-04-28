import { test as setup, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { resetAndSeed } from './setup-helpers';

const AUTH_FILE = 'playwright/.auth/viewer.json';
const VIEWER_EMAIL = process.env.E2E_VIEWER_EMAIL ?? 'viewer@demo.local';
const VIEWER_PASSWORD = process.env.E2E_VIEWER_PASSWORD ?? 'password';

/*
 * PR6 — Phase F1. Viewer auth setup. Uses `page.request` (not the
 * top-level `request` fixture) so the XSRF cookie set by
 * /sanctum/csrf-cookie shares the same jar as `page.goto(...)` —
 * see auth.setup.ts for the full rationale.
 */
setup('authenticate as viewer', async ({ page, context }) => {
    mkdirSync(dirname(AUTH_FILE), { recursive: true });

    await resetAndSeed(page);

    await page.request.get('/sanctum/csrf-cookie');

    const cookies = await context.cookies();
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrfCookie) {
        throw new Error('XSRF-TOKEN cookie missing after /sanctum/csrf-cookie');
    }

    const loginResponse = await page.request.post('/api/auth/login', {
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
