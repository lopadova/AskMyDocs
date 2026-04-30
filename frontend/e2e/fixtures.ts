import { test as base, expect } from '@playwright/test';
import { resetDb } from './setup-helpers';

/*
 * Shared fixtures for AskMyDocs E2E.
 *
 * The `seeded` auto-fixture runs before every test:
 *   1. resetDb(page) → POST /testing/reset → migrate:fresh
 *      (no-op in CI when E2E_SKIP_HTTP_RESET=1; the workflow already
 *      ran `migrate:fresh` from the CLI before the dev server started.
 *      See R38 in CLAUDE.md and `setup-helpers.ts` for the rationale.)
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
        async ({ page, context, request }, use, testInfo) => {
            // resetDb() respects E2E_SKIP_HTTP_RESET — in CI the DB is
            // already clean from the CLI `migrate:fresh` step, so this
            // is a no-op. Locally it does the HTTP reset as before.
            await resetDb(page);
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

                // Verification step: confirm /api/auth/me returns 200
                // with the right user. If the session cookie set by
                // /api/auth/login isn't carried by page.request to the
                // protected /me endpoint, this throws with the auth
                // body so the failure mode is unambiguous (vs the
                // downstream 'data-state=error' mystery from the
                // previous CI iteration).
                const meResponse = await page.request.get('/api/auth/me', {
                    headers: { Accept: 'application/json' },
                });
                if (!meResponse.ok()) {
                    throw new Error(
                        `seeded fixture: /api/auth/me failed AFTER successful login for ${creds.email}: ${meResponse.status()} ${await meResponse.text()}`,
                    );
                }
                const mePayload = (await meResponse.json()) as { user?: { email?: string } };
                if (mePayload.user?.email !== creds.email) {
                    throw new Error(
                        `seeded fixture: /api/auth/me returned wrong user. expected ${creds.email}, got ${mePayload.user?.email ?? '(no user)'}`,
                    );
                }

                // NOTE: deliberately do NOT probe /api/admin/* here.
                // The fixture runs before the test body, so the page
                // is still at about:blank — page.request from that
                // context sends no Origin header, so Sanctum's
                // EnsureFrontendRequestsAreStateful middleware can't
                // recognise the request as SPA-stateful and 401s
                // even with a valid session cookie. The test body's
                // own page.goto('/app/admin') sets a real Origin
                // (http://127.0.0.1:8000), so the SPA's fetches DO
                // pass through Sanctum stateful correctly. /me works
                // here only because that route is wrapped in
                // Route::middleware('web') which forces session
                // loading regardless of Origin.

                // Re-login in the TOP-LEVEL `request` fixture's context
                // too. Playwright gives `request` and `page.request`
                // SEPARATE cookie jars by default — even when
                // storageState is set on the project, the top-level
                // `request` fixture inherits the saved cookies but
                // NOT the new session cookie set by page.request after
                // login. Tests using `{ request }` (admin-maintenance,
                // admin-users, admin-kb ingest) would then fire calls
                // with the stale cookies → password-hash mismatch →
                // 401. Run the same login dance against `request`'s
                // own context so its cookie jar holds a fresh,
                // valid session under the new bcrypt salt.
                await request.get('/sanctum/csrf-cookie');
                const requestStorage = await request.storageState();
                const requestXsrf = requestStorage.cookies.find(
                    (c) => c.name === 'XSRF-TOKEN',
                );
                if (requestXsrf) {
                    const reqLoginResponse = await request.post('/api/auth/login', {
                        data: creds,
                        headers: {
                            'X-XSRF-TOKEN': decodeURIComponent(requestXsrf.value),
                            Accept: 'application/json',
                        },
                    });
                    if (!reqLoginResponse.ok()) {
                        throw new Error(
                            `seeded fixture: top-level request re-login failed for ${creds.email}: ${reqLoginResponse.status()} ${await reqLoginResponse.text()}`,
                        );
                    }
                }
            }

            await use();
        },
        { auto: true },
    ],
});

export { expect };
