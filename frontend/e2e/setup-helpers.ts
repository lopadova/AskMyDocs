import type { APIResponse, Page } from '@playwright/test';

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
    page: Page,
    path: string,
    body?: unknown,
): Promise<APIResponse> {
    const MAX_ATTEMPTS = 30;
    const SLEEP_MS = 1500;
    for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
        try {
            return await page.request.post(path, body ? { data: body } : undefined);
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

export async function resetAndSeed(page: Page, seeder = 'DemoSeeder'): Promise<void> {
    if (!SKIP_HTTP_RESET) {
        // Local runs: reset the DB via the HTTP endpoint so the dev server
        // is the single entry point for both reset and seed. In CI, this
        // is skipped because `migrate:fresh` was already run from the CLI
        // before Playwright started (E2E_SKIP_HTTP_RESET=1).
        const resetResponse = await postWithRetry(page, '/testing/reset');
        if (!resetResponse.ok()) {
            throw new Error(
                `/testing/reset failed: ${resetResponse.status()} ${await resetResponse.text()}`,
            );
        }
    }
    const seedResponse = await postWithRetry(page, '/testing/seed', { seeder });
    if (!seedResponse.ok()) {
        throw new Error(
            `/testing/seed failed: ${seedResponse.status()} ${await seedResponse.text()}`,
        );
    }
}
