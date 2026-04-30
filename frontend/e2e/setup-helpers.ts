import type { APIRequestContext, APIResponse, Page } from '@playwright/test';

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
 * Setup-time helpers shared across auth.setup.ts, viewer.setup.ts, and
 * super-admin.setup.ts.
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
 * 30 attempts at 1500ms covers 45 s — enough headroom for /testing/seed
 * on a freshly migrated Postgres schema and for any first-request boot
 * race on a cold CI runner.
 */

/**
 * When true, skip the heavy POST /testing/reset (migrate:fresh) because
 * the CI workflow already ran `php artisan migrate:fresh --force` from
 * the CLI before Playwright started. This prevents the dev server from
 * executing a blocking migration inside an HTTP request, which caused
 * ECONNREFUSED on 127.0.0.1:8000 for the immediately-following calls.
 */
const SKIP_HTTP_RESET = process.env.E2E_SKIP_HTTP_RESET === '1';

export async function postWithRetry(
    target: RequestTarget,
    path: string,
    body?: unknown,
): Promise<APIResponse> {
    const request = asRequest(target);
    const MAX_ATTEMPTS = 30;
    const SLEEP_MS = 1500;
    for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
        try {
            return await request.post(path, body ? { data: body } : undefined);
        } catch (err: unknown) {
            const message = err instanceof Error ? err.message : String(err);
            if (attempt === MAX_ATTEMPTS - 1) {
                throw new Error(`${path} failed after ${MAX_ATTEMPTS} attempts: ${message}`);
            }
            await new Promise((r) => setTimeout(r, SLEEP_MS));
        }
    }
    throw new Error(`${path} unreachable`);
}

/**
 * Single entry point for "wipe the DB before this test/setup runs".
 *
 * Always import this helper — never `page.request.post('/testing/reset')`
 * directly. Direct calls bypass `E2E_SKIP_HTTP_RESET` and force the dev
 * server to execute a blocking `migrate:fresh` inside an HTTP request,
 * which is exactly the failure mode the CI fix is meant to avoid (R38).
 *
 * Behaviour:
 * - In CI (`E2E_SKIP_HTTP_RESET=1`): no-op. The workflow already ran
 *   `php artisan migrate:fresh --force` from the CLI before the dev
 *   server started, so the DB is clean.
 * - Local runs (flag unset): hits POST /testing/reset via the dev
 *   server, with the postWithRetry early-boot retry loop.
 *
 * Throws on a non-2xx HTTP response so the calling test fails loudly
 * instead of running against a partially-reset database.
 */
export async function resetDb(target: RequestTarget): Promise<void> {
    if (SKIP_HTTP_RESET) {
        return;
    }
    const resetResponse = await postWithRetry(target, '/testing/reset');
    if (!resetResponse.ok()) {
        throw new Error(
            `/testing/reset failed: ${resetResponse.status()} ${await resetResponse.text()}`,
        );
    }
}

export async function resetAndSeed(target: RequestTarget, seeder = 'DemoSeeder'): Promise<void> {
    await resetDb(target);
    const seedResponse = await postWithRetry(target, '/testing/seed', { seeder });
    if (!seedResponse.ok()) {
        throw new Error(
            `/testing/seed failed: ${seedResponse.status()} ${await seedResponse.text()}`,
        );
    }
}
