import { defineConfig, devices } from '@playwright/test';

/*
 * Playwright configuration for AskMyDocs SPA E2E.
 *
 * Tests live under frontend/e2e/. The `setup` project signs in once,
 * writes the storage state to playwright/.auth/admin.json, and all
 * subsequent projects reuse it — no per-test login.
 *
 * CI runs with APP_ENV=testing so the TestingController endpoints are
 * reachable (/testing/reset + /testing/seed). Local dev can run the
 * same pipeline with `APP_ENV=testing npm run e2e`.
 */
export default defineConfig({
    testDir: './frontend/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
        video: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /.*\.setup\.ts/ },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/admin.json',
            },
            dependencies: ['setup'],
            testIgnore: /.*\.setup\.ts/,
        },
    ],
});
