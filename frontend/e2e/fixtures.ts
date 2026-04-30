import { test as base, expect } from '@playwright/test';
import { loginAsProjectUser, resetDb, seedDb } from './setup-helpers';

/*
 * Shared fixtures for AskMyDocs E2E.
 *
 * The `seeded` auto-fixture runs before every test:
 *   1. resetDb(page) → POST /testing/reset → migrate:fresh.
 *      Always wipes (no env-driven short-circuit) — per-scenario state
 *      isolation requires an actual truncate so each test starts from a
 *      known baseline regardless of what the previous spec left behind.
 *      Safe to run here: the dev server is already warm by the time
 *      fixtures execute (auth.setup has handled `/healthz` + login),
 *      so there is no boot-race window. The R38 boot-race protection
 *      (`E2E_SKIP_HTTP_RESET`) only narrows `resetAndSeed()` in
 *      setup-helpers — it intentionally does NOT cover this call.
 *   2. seedDb(page, 'DemoSeeder') → POST /testing/seed { DemoSeeder }
 *      (throws on non-2xx, so a seeder regression surfaces here
 *      instead of as a downstream selector timeout).
 *   3. loginAsProjectUser(page, context, request, projectName)
 *      → CSRF + /api/auth/login + /api/auth/me verification on BOTH
 *      the page-context cookie jar and the top-level `request`
 *      fixture's cookie jar.
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
 * Spec bodies that perform an additional `resetDb()` mid-test (e.g.
 * `admin-dashboard.spec.ts` switching to `EmptyAdminSeeder`) MUST
 * call `loginAsProjectUser()` again themselves before navigating —
 * the second migrate:fresh re-invalidates the session set up here.
 *
 * NOTE: deliberately do NOT probe /api/admin/* in this fixture. The
 * fixture runs before the test body, so the page is still at
 * about:blank — page.request from that context sends no Origin
 * header, so Sanctum's EnsureFrontendRequestsAreStateful middleware
 * can't recognise the request as SPA-stateful and 401s even with a
 * valid session cookie. The test body's own page.goto('/app/admin')
 * sets a real Origin (http://127.0.0.1:8000), so the SPA's fetches
 * DO pass through Sanctum stateful correctly. /me works in
 * loginAsProjectUser() only because that route is wrapped in
 * Route::middleware('web') which forces session loading regardless
 * of Origin.
 */
export const test = base.extend<{ seeded: void }>({
    seeded: [
        async ({ page, context, request }, use, testInfo) => {
            await resetDb(page);
            await seedDb(page, 'DemoSeeder');
            await loginAsProjectUser(page, context, request, testInfo.project.name);
            await use();
        },
        { auto: true },
    ],
});

export { expect };
