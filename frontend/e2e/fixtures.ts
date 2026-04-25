import { test as base, expect } from '@playwright/test';

/*
 * Shared fixtures for AskMyDocs E2E.
 *
 * The `seeded` auto-fixture runs before every test:
 *   1. POST /testing/reset → migrate:fresh
 *   2. POST /testing/seed { DemoSeeder }
 *   3. Re-establishes the project's auth via /api/auth/login
 *
 * Step 3 is non-obvious but load-bearing. The setup projects
 * (auth.setup / viewer.setup / super-admin.setup) save a session
 * cookie to the project's storageState. That cookie contains a
 * `password_<id>` key Laravel uses to detect "the user changed their
 * password — log them out". When migrate:fresh drops + recreates the
 * users table, the new admin@demo.local has a fresh bcrypt salt →
 * different password hash → Laravel sees the mismatch and invalidates
 * the session. Subsequent /api/admin/* calls return 401.
 *
 * Re-running login per test re-fixates the session under the new
 * user record's password hash. Cheap (< 50ms) against the fixture
 * already-running cycle.
 *
 * Response checks on every step so a regression on /testing/reset
 * (CSRF, migration error) or /api/auth/login (Sanctum stateful misconfig)
 * surfaces immediately rather than as an opaque downstream "data-state=
 * error" assertion 15s later.
 */
const PROJECT_CREDENTIALS: Record<string, { email: string; password: string }> = {
    chromium: { email: 'admin@demo.local', password: 'password' },
    'chromium-viewer': { email: 'viewer@demo.local', password: 'password' },
    'chromium-super-admin': { email: 'super@demo.local', password: 'password' },
};

export const test = base.extend<{ seeded: void }>({
    seeded: [
        async ({ page, context }, use, testInfo) => {
            const resetResponse = await page.request.post('/testing/reset');
            if (!resetResponse.ok()) {
                throw new Error(
                    `seeded fixture: /testing/reset failed: ${resetResponse.status()} ${await resetResponse.text()}`,
                );
            }
            const seedResponse = await page.request.post('/testing/seed', {
                data: { seeder: 'DemoSeeder' },
            });
            if (!seedResponse.ok()) {
                throw new Error(
                    `seeded fixture: /testing/seed failed: ${seedResponse.status()} ${await seedResponse.text()}`,
                );
            }

            // Re-login as the project's user. The storageState cookie
            // from setup is no longer valid because migrate:fresh
            // changed the user's password hash and Laravel logs the
            // user out on hash mismatch.
            const creds = PROJECT_CREDENTIALS[testInfo.project.name];
            if (creds) {
                await page.request.get('/sanctum/csrf-cookie');
                const cookies = await context.cookies();
                const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
                if (!xsrfCookie) {
                    throw new Error(
                        'seeded fixture: XSRF-TOKEN cookie missing after /sanctum/csrf-cookie',
                    );
                }
                const loginResponse = await page.request.post('/api/auth/login', {
                    data: creds,
                    headers: {
                        'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value),
                        Accept: 'application/json',
                    },
                });
                if (!loginResponse.ok()) {
                    throw new Error(
                        `seeded fixture: re-login failed for ${creds.email} on project ${testInfo.project.name}: ${loginResponse.status()} ${await loginResponse.text()}`,
                    );
                }
            }

            await use();
        },
        { auto: true },
    ],
});

export { expect };
