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
 * The structural fix lives in playwright.config.ts (the webServer env
 * sets `PHP_CLI_SERVER_WORKERS=4`, which makes the built-in server
 * fork worker children and serve requests concurrently). This retry
 * loop is the belt-and-braces defence against the residual race during
 * the very first POST after the server reports `/healthz` green.
 *
 * 16 attempts at 1500ms covers 24 s — comfortably longer than the 12 s
 * window post-merge run #25078597176 stayed in ECONNREFUSED before the
 * workers fix landed.
 */
export async function postWithRetry(
    page: Page,
    path: string,
    body?: unknown,
): Promise<APIResponse> {
    const MAX_ATTEMPTS = 16;
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
    const resetResponse = await postWithRetry(page, '/testing/reset');
    if (!resetResponse.ok()) {
        throw new Error(
            `/testing/reset failed: ${resetResponse.status()} ${await resetResponse.text()}`,
        );
    }
    const seedResponse = await postWithRetry(page, '/testing/seed', { seeder });
    if (!seedResponse.ok()) {
        throw new Error(
            `/testing/seed failed: ${seedResponse.status()} ${await seedResponse.text()}`,
        );
    }
}
