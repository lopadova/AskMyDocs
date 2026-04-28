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
 *
 * Copilot #2/#3 fix: `webServer` boots `php artisan serve` automatically
 * so `npm run e2e` is self-contained in CI and locally. Set
 * `E2E_SKIP_WEBSERVER=1` when an external server is already running.
 *
 * PR6 Phase F1 — added the `viewer-setup` + `chromium-viewer` projects
 * so the admin-dashboard RBAC scenarios can run as a non-admin without
 * trampling the admin storage state.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const skipWebServer = process.env.E2E_SKIP_WEBSERVER === '1';

export default defineConfig({
    testDir: './frontend/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    // Retries kept at 0 in CI while the suite is being stabilised on
    // the new pgvector-enabled Playwright job — a flaky test can
    // compound to hours of runner time with retries:2 (60 tests * 90s
    // worst case). Once green and stable, bump back to 2 for nightly
    // runs.
    retries: process.env.CI ? 0 : 0,
    workers: process.env.CI ? 1 : undefined,
    // Per-test timeout. Default is 30s; tighter so a stuck test
    // (e.g., page.goto blocking on a slow CI server response) fails
    // before it costs serious wall-clock budget.
    timeout: 20_000,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL,
        trace: 'on-first-retry',
        video: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: skipWebServer
        ? undefined
        : {
              command: 'php artisan serve --host=127.0.0.1 --port=8000',
              // `/healthz` returns a plain 200 with no auth / no DB hit.
              // The previous `baseURL` poll on `/` was hitting the home
              // route (auth middleware → 302 to /login) which CI's webServer
              // probe interpreted as not-ready and timed out after 120s.
              // `/healthz` is the unambiguous green signal.
              url: `${baseURL}/healthz`,
              reuseExistingServer: !process.env.CI,
              timeout: 120_000,
              env: {
                  APP_ENV: 'testing',
                  // PHP_CLI_SERVER_WORKERS spawns N worker children for
                  // the PHP built-in dev server (PHP 7.4+). Without it,
                  // `php artisan serve` is single-threaded and the
                  // accept loop stalls during a long migrate:fresh
                  // request, causing every concurrent / immediately-
                  // following request to ECONNREFUSED for ≥12s — the
                  // root of the recurring auth.setup flake. Four workers
                  // is enough headroom for healthz + reset + seed +
                  // login to land in parallel.
                  PHP_CLI_SERVER_WORKERS: '4',
              },
              stdout: 'pipe',
              stderr: 'pipe',
          },
    projects: [
        // Setup projects are chained sequentially via `dependencies` so
        // they don't all hammer /testing/reset (migrate:fresh on real
        // Postgres) at the same instant. PHP's built-in `artisan serve`
        // is single-threaded; three parallel migrate:fresh requests
        // queue + sometimes lock the server long enough for downstream
        // requests to ECONNREFUSED. Chaining keeps the API surface
        // exercised one-at-a-time during boot.
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        { name: 'viewer-setup', testMatch: /viewer\.setup\.ts/, dependencies: ['setup'] },
        // PR13 / Phase H2 — super-admin setup. Seeds super@demo.local
        // (DemoSeeder) and persists storage state so the
        // chromium-super-admin project can exercise destructive
        // maintenance commands behind the `commands.destructive`
        // permission — which the admin role alone doesn't hold.
        {
            name: 'super-admin-setup',
            testMatch: /super-admin\.setup\.ts/,
            dependencies: ['viewer-setup'],
        },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/admin.json',
            },
            dependencies: ['setup'],
            // Every *-viewer.spec.ts file runs under the viewer storage
            // state; every *-super-admin.spec.ts under the super-admin
            // one. Keep the ignore list a single regex so new RBAC
            // denial / elevation specs don't need this config touched.
            testIgnore: [/.*\.setup\.ts/, /.*-viewer\.spec\.ts/, /.*-super-admin\.spec\.ts/],
        },
        {
            // Non-admin project — runs ONLY the *-viewer scenarios.
            // Uses a separate storage state so the admin cookie from
            // `auth.setup.ts` does not leak in.
            name: 'chromium-viewer',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/viewer.json',
            },
            dependencies: ['viewer-setup'],
            testMatch: /.*-viewer\.spec\.ts/,
        },
        {
            // PR13 / Phase H2 — super-admin project. Scoped ONLY to
            // *-super-admin.spec.ts specs so destructive command
            // flows don't leak into the admin project's scope.
            name: 'chromium-super-admin',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/super-admin.json',
            },
            dependencies: ['super-admin-setup'],
            testMatch: /.*-super-admin\.spec\.ts/,
        },
    ],
});
