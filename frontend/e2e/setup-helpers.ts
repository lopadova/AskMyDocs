import type { APIResponse, Page } from '@playwright/test';

/*
 * Setup-time helpers shared across auth.setup.ts, viewer.setup.ts, and
 * super-admin.setup.ts.
 *
 * `php artisan serve` runs PHP's built-in dev server, which (a) can drop
 * early POSTs while Laravel is still finishing its bootstrap, and (b) can
 * briefly stop accepting new connections while a previous request is
 * running a long `migrate:fresh` against Postgres.
 *
 * The webServer probe in playwright.config.ts hits `/healthz` (no DB),
 * so the runner sees green before the full request stack is warm. The
 * three setup projects then chain reset/seed cycles serially — a flaky
 * window can ECONNREFUSED any one of them. Eight attempts at 1500ms
 * gives 12s of resilience per call, which has been enough on every
 * empirical CI run we've inspected.
 */
export async function postWithRetry(
    page: Page,
    path: string,
    body?: unknown,
): Promise<APIResponse> {
    const MAX_ATTEMPTS = 8;
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
