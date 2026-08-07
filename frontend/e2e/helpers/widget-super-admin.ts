import type { BrowserContext, Page } from '@playwright/test';
import { E2E_BASE_URL, resetDb, seedDb } from '../setup-helpers';

/**
 * Reset the disposable E2E database and authenticate only the browser/page
 * cookie jar. Widget admin specs do not use Playwright's independent top-level
 * `request` fixture, so keeping this flow page-scoped avoids stale CSRF state
 * after migrate:fresh while preserving the real Sanctum login boundary.
 */
export async function resetAndLoginWidgetSuperAdmin(
    page: Page,
    context: BrowserContext,
): Promise<void> {
    await resetDb(page);
    await seedDb(page, 'DemoSeeder');
    const csrf = await page.request.get('/sanctum/csrf-cookie');
    if (!csrf.ok()) {
        throw new Error(`GET /sanctum/csrf-cookie failed: ${csrf.status()} ${await csrf.text()}`);
    }
    const xsrf = (await context.cookies(E2E_BASE_URL)).find(
        (cookie) => cookie.name === 'XSRF-TOKEN',
    );
    if (!xsrf) {
        throw new Error('XSRF-TOKEN cookie missing from the widget admin E2E page context.');
    }
    const login = await page.request.post('/api/auth/login', {
        data: { email: 'super@demo.local', password: 'password' },
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(xsrf.value),
            Accept: 'application/json',
        },
    });
    if (!login.ok()) {
        throw new Error(`Super-admin login failed: ${login.status()} ${await login.text()}`);
    }
}
