import type { APIRequestContext, APIResponse, BrowserContext, Page } from '@playwright/test';

/**
 * Per-project login credentials, keyed by Playwright project name as
 * declared in `playwright.config.ts` (NOT spec filenames). Each user
 * is seeded via `User::firstOrCreate()` keyed on email so the
 * email/password pair stays valid across `migrate:fresh` resets even
 * though the bcrypt hash is regenerated each time. Coverage by seeder:
 *
 *  - `admin@demo.local`  — DemoSeeder + EmptyAdminSeeder (+ chained
 *    AdminDegradedSeeder, AdminInsightsSeeder via DemoSeeder).
 *  - `viewer@demo.local` — DemoSeeder + EmptyAdminSeeder.
 *  - `super@demo.local`  — DemoSeeder ONLY. EmptyAdminSeeder does NOT
 *    seed the super-admin user, so spec bodies running under the
 *    `chromium-super-admin` project must NOT switch to
 *    EmptyAdminSeeder via mid-test `seedDb()` — they would lose the
 *    user record they just authenticated with. The `super-admin-setup`
 *    project boots through DemoSeeder via the auto-fixture and stays
 *    on it for the duration of the suite.
 *
 * Setup projects (`setup`, `viewer-setup`, `super-admin-setup` —
 * driven by `auth.setup.ts`, `viewer.setup.ts`, `super-admin.setup.ts`)
 * are not listed because they perform their own login flow against
 * the storageState at boot and don't reuse this map.
 */
export const PROJECT_CREDENTIALS: Record<string, { email: string; password: string }> = {
    chromium: { email: 'admin@demo.local', password: 'password' },
    'chromium-viewer': { email: 'viewer@demo.local', password: 'password' },
    'chromium-super-admin': { email: 'super@demo.local', password: 'password' },
};

/**
 * Either a Page (for setup-time helpers that have one) or a raw
 * APIRequestContext (for spec-level fixtures that don't navigate).
 * Used by the public DB-reset helpers below so callers can pass
 * whichever they have without re-wrapping.
 */
type RequestTarget = Page | APIRequestContext;

function asRequest(target: RequestTarget): APIRequestContext {
    return 'request' in target ? target.request : target;
}

/*
 * Setup-time helpers shared across the spec files `auth.setup.ts`,
 * `viewer.setup.ts`, and `super-admin.setup.ts` (project names in
 * `playwright.config.ts`: `setup`, `viewer-setup`, `super-admin-setup`).
 *
 * `php artisan serve` runs PHP's built-in dev server. Two failure modes
 * we have observed on the CI runner:
 *  (a) it drops early POSTs while Laravel is still finishing bootstrap;
 *  (b) it stalls the accept loop while a long `migrate:fresh` is
 *      running, causing every immediately-following connection to
 *      ECONNREFUSED for the duration of the heavy request.
 *
 * The structural fix for (b) in CI is to run `migrate:fresh` from the
 * CLI before Playwright starts the web server (see the
 * "Migrate test database (CLI)" step in .github/workflows/tests.yml).
 * When the env var E2E_SKIP_HTTP_RESET=1 is set, `resetAndSeed()`
 * skips the POST /testing/reset call entirely and only runs
 * /testing/seed — the DB is already clean from the CLI step and the
 * server never has to execute a blocking `migrate:fresh` inside an
 * HTTP request.
 *
 * For local runs (E2E_SKIP_HTTP_RESET not set), the original behaviour
 * is preserved: /testing/reset runs first, then /testing/seed.
 *
 * The structural fix for (a) lives in playwright.config.ts (the
 * webServer env sets `PHP_CLI_SERVER_WORKERS=4`, which makes the
 * built-in server fork worker children and serve requests
 * concurrently). This retry loop is the belt-and-braces defence
 * against the residual race during the very first POST after the
 * server reports `/healthz` green.
 *
 * Retry budgets are split by phase to fit within Playwright's per-test
 * timeout (20_000 ms in `playwright.config.ts`):
 *
 *  - BOOT_RETRY_ATTEMPTS = 30 × 1500 ms ≈ 45 s. Used by
 *    `resetAndSeed()` only — that helper runs at SETUP-project boot
 *    (project names `setup` / `viewer-setup` / `super-admin-setup`,
 *    driven by `auth.setup.ts` / `viewer.setup.ts` /
 *    `super-admin.setup.ts`) where the first POST after `php artisan
 *    serve` reports `/healthz` green can still ECONNREFUSE for tens
 *    of seconds. Setup projects DO inherit the global per-test
 *    `timeout: 20_000` from `playwright.config.ts` — the 45 s ceiling
 *    is THEORETICAL worst-case. In practice the cold-boot flake
 *    clears within 1–3 retries (~3 s), so the budget rarely drains
 *    past 5 s. If a future workload genuinely needs the full window
 *    (e.g. a slower CI runner), override `timeout` on the setup
 *    project explicitly via `playwright.config.ts`'s project entry
 *    rather than silently relying on Playwright not noticing.
 *
 *  - WARM_RETRY_ATTEMPTS = 3 × 1500 ms = 4.5 s. Used by `resetDb()`
 *    and `seedDb()` — both are called from spec bodies and the
 *    auto-fixture in `frontend/e2e/fixtures.ts` AFTER the dev server
 *    is warm. A 4.5 s worst-case retry budget fits comfortably inside
 *    the 20 s per-test cap, so a transient flake throws this helper's
 *    informative error instead of dying as a generic Playwright
 *    timeout where the failure mode is opaque.
 */
const BOOT_RETRY_ATTEMPTS = 30;
const WARM_RETRY_ATTEMPTS = 3;
const RETRY_SLEEP_MS = 1500;

/**
 * When true, skip the heavy POST /testing/reset (migrate:fresh) because
 * the CI workflow already ran `php artisan migrate:fresh --force` from
 * the CLI before Playwright started. This prevents the dev server from
 * executing a blocking migration inside an HTTP request, which caused
 * ECONNREFUSED on 127.0.0.1:8000 for the immediately-following calls.
 */
const SKIP_HTTP_RESET = process.env.E2E_SKIP_HTTP_RESET === '1';

export interface RetryOptions {
    /**
     * Maximum number of attempts before throwing. Defaults to
     * BOOT_RETRY_ATTEMPTS (30) — appropriate for setup-project boot
     * where the first POST after `php artisan serve` answers `/healthz`
     * can still ECONNREFUSE for tens of seconds. Pass
     * WARM_RETRY_ATTEMPTS (3) for spec-body / fixture calls where the
     * server is already warm and Playwright's per-test 20 s timeout
     * caps the retry window.
     */
    maxAttempts?: number;
    /**
     * Sleep between attempts. Defaults to RETRY_SLEEP_MS (1500).
     */
    sleepMs?: number;
}

export async function postWithRetry(
    target: RequestTarget,
    path: string,
    body?: unknown,
    options: RetryOptions = {},
): Promise<APIResponse> {
    const request = asRequest(target);
    const maxAttempts = options.maxAttempts ?? BOOT_RETRY_ATTEMPTS;
    const sleepMs = options.sleepMs ?? RETRY_SLEEP_MS;
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        try {
            return await request.post(path, body ? { data: body } : undefined);
        } catch (err: unknown) {
            const message = err instanceof Error ? err.message : String(err);
            if (attempt === maxAttempts - 1) {
                throw new Error(`${path} failed after ${maxAttempts} attempts: ${message}`);
            }
            await new Promise((r) => setTimeout(r, sleepMs));
        }
    }
    throw new Error(`${path} unreachable`);
}

/**
 * Single entry point for "wipe the DB before this test/setup runs".
 *
 * Always import this helper — never `page.request.post('/testing/reset')`
 * directly. The helper exists to (a) keep all reset call-sites grepable
 * for future refactors and (b) guarantee a deterministic DB state per
 * scenario. UNLIKE `resetAndSeed()` below, this function does NOT honour
 * `E2E_SKIP_HTTP_RESET`: per-scenario reseeding (e.g. `EmptyAdminSeeder`
 * after `DemoSeeder`) requires an actual wipe — `/testing/seed` only
 * inserts rows, it does not truncate, so skipping the reset would leave
 * cross-scenario state and produce order-dependent assertions.
 *
 * The boot-time race that motivated `E2E_SKIP_HTTP_RESET` lives only in
 * the auth.setup window (very first POST after `php artisan serve`
 * boots). By the time spec scenarios run, the dev server is warm and a
 * fresh `/testing/reset` lands without flake.
 *
 * Throws on a non-2xx HTTP response so the calling test fails loudly
 * instead of running against a partially-reset database.
 */
export async function resetDb(
    target: RequestTarget,
    options: RetryOptions = {},
): Promise<void> {
    // Default to WARM_RETRY_ATTEMPTS regardless of whether the caller
    // passed `{}` or `{ sleepMs: ... }` — without this merge, an
    // options object that omits `maxAttempts` would silently fall back
    // to BOOT_RETRY_ATTEMPTS via `postWithRetry()`'s default and the
    // retry window (≈45 s) would blow past Playwright's per-test cap
    // (20 s). Setup-time callers (resetAndSeed) override explicitly.
    const merged: RetryOptions = { maxAttempts: WARM_RETRY_ATTEMPTS, ...options };
    const resetResponse = await postWithRetry(target, '/testing/reset', undefined, merged);
    if (!resetResponse.ok()) {
        throw new Error(
            `/testing/reset failed: ${resetResponse.status()} ${await resetResponse.text()}`,
        );
    }
}

/**
 * Single entry point for "seed the DB with <seeder> before this test/setup
 * runs". Always import this helper — never call `request.post('/testing/seed', ...)`
 * directly. Reasons match `resetDb()`: (a) keep all seed call-sites grepable
 * for future refactors and (b) guarantee the seeder ran successfully — a
 * silent 422/500 from the seed endpoint would otherwise leave the test running
 * against a partially-seeded DB and surface as an opaque downstream
 * "data-state=error" or "element not visible" 15 s later instead of the real
 * cause.
 *
 * Throws on a non-2xx HTTP response so the calling test fails loudly with
 * the seeder name + status + body in the error message.
 */
export async function seedDb(
    target: RequestTarget,
    seeder = 'DemoSeeder',
    options: RetryOptions = {},
): Promise<void> {
    // Same warm-default merge as `resetDb()` — see the comment there
    // for the per-test-timeout rationale.
    const merged: RetryOptions = { maxAttempts: WARM_RETRY_ATTEMPTS, ...options };
    const seedResponse = await postWithRetry(target, '/testing/seed', { seeder }, merged);
    if (!seedResponse.ok()) {
        throw new Error(
            `/testing/seed (${seeder}) failed: ${seedResponse.status()} ${await seedResponse.text()}`,
        );
    }
}

export async function resetAndSeed(target: RequestTarget, seeder = 'DemoSeeder'): Promise<void> {
    // Setup-project boot path: pass the full BOOT_RETRY_ATTEMPTS budget
    // explicitly so the resetDb/seedDb defaults (which are sized for
    // warm-server per-test calls) don't underbudget the cold-boot
    // window where the first POST after `/healthz` answers green can
    // still ECONNREFUSE for tens of seconds.
    const bootRetry: RetryOptions = { maxAttempts: BOOT_RETRY_ATTEMPTS };
    if (!SKIP_HTTP_RESET) {
        // Boot-race protection: in CI the workflow already ran
        // `php artisan migrate:fresh --force` from the CLI BEFORE the
        // dev server started, so the DB is clean and this initial
        // /testing/reset would be redundant work that risks the
        // single-threaded accept-loop stall (R38). Skip it. Spec-level
        // `resetDb()` calls keep working because they hit the dev
        // server only AFTER it has handled `/healthz` (i.e., warm).
        await resetDb(target, bootRetry);
    }
    await seedDb(target, seeder, bootRetry);
}

/**
 * Re-establish the project's auth session after a `resetDb()`/`seedDb()`
 * pair inside a spec body. The auto-fixture in `frontend/e2e/fixtures.ts`
 * handles this once before each test, but specs that perform an additional
 * `migrate:fresh` (e.g. switching from `DemoSeeder` to `EmptyAdminSeeder`)
 * end up with a stale session because:
 *   - `migrate:fresh` drops the `users` and `sessions` tables.
 *   - The seeder re-creates the user via `User::firstOrCreate()` with
 *     a fresh `bcrypt` hash — different from the hash the auto-fixture
 *     used to log in.
 *   - The session cookie still in the cookie jar points at a session
 *     row that no longer exists, AND the password hash check in
 *     Laravel's session middleware now fails.
 *
 * Without this re-login, `page.goto('/app/admin')` is racy: the SPA's
 * `RequireAuth` boot calls `/api/auth/me`, gets 401, and redirects to
 * `/login`. Test assertions on admin-shell test-ids then time out.
 *
 * The function performs the same CSRF + login + `/me` verification dance
 * the auto-fixture runs at boot, against BOTH the page-scoped cookie
 * jar (`page.request`) AND the top-level `request` fixture's cookie
 * jar (Playwright keeps these separate by default).
 *
 * Pass `testInfo.project.name` so the helper picks the right credentials
 * from `PROJECT_CREDENTIALS`. Unknown project names are a no-op (matches
 * the auto-fixture behaviour for the setup projects).
 */
export async function loginAsProjectUser(
    page: Page,
    context: BrowserContext,
    request: APIRequestContext,
    projectName: string,
): Promise<void> {
    const creds = PROJECT_CREDENTIALS[projectName];
    if (!creds) return;

    const pageCsrfResponse = await page.request.get('/sanctum/csrf-cookie');
    if (!pageCsrfResponse.ok()) {
        throw new Error(
            `loginAsProjectUser: /sanctum/csrf-cookie failed on page context: ${pageCsrfResponse.status()} ${await pageCsrfResponse.text()}`,
        );
    }
    const cookies = await context.cookies();
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrfCookie) {
        throw new Error(
            'loginAsProjectUser: XSRF-TOKEN cookie missing on page context after /sanctum/csrf-cookie',
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
            `loginAsProjectUser: page-context login failed for ${creds.email} on project ${projectName}: ${loginResponse.status()} ${await loginResponse.text()}`,
        );
    }

    const meResponse = await page.request.get('/api/auth/me', {
        headers: { Accept: 'application/json' },
    });
    if (!meResponse.ok()) {
        throw new Error(
            `loginAsProjectUser: /api/auth/me failed AFTER successful login for ${creds.email}: ${meResponse.status()} ${await meResponse.text()}`,
        );
    }
    const mePayload = (await meResponse.json()) as { user?: { email?: string } };
    if (mePayload.user?.email !== creds.email) {
        throw new Error(
            `loginAsProjectUser: /api/auth/me returned wrong user. expected ${creds.email}, got ${mePayload.user?.email ?? '(no user)'}`,
        );
    }

    // Re-login on the top-level `request` fixture's cookie jar too —
    // see the long comment in fixtures.ts for the rationale (Playwright
    // keeps `request` and `page.request` cookie jars separate).
    const csrfResponse = await request.get('/sanctum/csrf-cookie');
    if (!csrfResponse.ok()) {
        throw new Error(
            `loginAsProjectUser: /sanctum/csrf-cookie failed on top-level request context: ${csrfResponse.status()} ${await csrfResponse.text()}`,
        );
    }
    const requestStorage = await request.storageState();
    const requestXsrf = requestStorage.cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!requestXsrf) {
        // Throwing instead of returning silently: a missing XSRF cookie
        // here means the top-level `request` context can't perform an
        // authenticated POST later, which surfaces as confusing 401/419
        // failures in spec bodies that use `{ request }`. Surface the
        // root cause at the point of failure instead of letting it
        // cascade into a downstream selector timeout.
        throw new Error(
            'loginAsProjectUser: XSRF-TOKEN cookie missing on top-level request context after /sanctum/csrf-cookie — Sanctum stateful misconfiguration or session storage wiped mid-request',
        );
    }
    const reqLoginResponse = await request.post('/api/auth/login', {
        data: creds,
        headers: {
            'X-XSRF-TOKEN': decodeURIComponent(requestXsrf.value),
            Accept: 'application/json',
        },
    });
    if (!reqLoginResponse.ok()) {
        throw new Error(
            `loginAsProjectUser: top-level request re-login failed for ${creds.email}: ${reqLoginResponse.status()} ${await reqLoginResponse.text()}`,
        );
    }
}
