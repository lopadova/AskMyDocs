import { test as base, expect } from '@playwright/test';

/*
 * Shared fixtures for AskMyDocs E2E. All tests in the chromium project
 * implicitly inherit the admin storage state (see playwright.config.ts
 * `storageState`). The `seeded` auto-fixture resets the DB via the
 * /testing endpoints exposed only when APP_ENV=testing.
 *
 * Reset runs before each test to keep scenarios independent; it is
 * cheap against the SQLite/Postgres demo dataset (< 100ms).
 */
export const test = base.extend<{ seeded: void }>({
    seeded: [
        async ({ request }, use) => {
            await request.post('/testing/reset');
            await request.post('/testing/seed', { data: { seeder: 'DemoSeeder' } });
            await use();
        },
        { auto: true },
    ],
});

export { expect };
