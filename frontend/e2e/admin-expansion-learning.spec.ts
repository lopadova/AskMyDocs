import { expect, type Page } from '@playwright/test';
import { test } from './fixtures';
import { chooseSelectOption, openSelect } from './helpers';

/*
 * Admin "Expansion learning" scenarios — the query-expansion learning loop.
 *
 * R13: the happy path runs against the REAL Laravel app and DemoSeeder data —
 * ZERO route stubs. The analyze POST is real too: the E2E server runs the
 * queue `sync`, so the job executes inline; with no recorded expansion
 * events it records nothing (below `min_events`) and never reaches the
 * benchmark gate, so no external provider is called. The one `page.route`
 * below is a deliberately-marked failure injection (500 from the status
 * GET), and the real-data load in the first test already covers that flow.
 *
 * Assumes the Playwright env runs with KB_EXPANSION_LEARNING_ENABLED=true
 * (set in playwright.config.ts). With the flag OFF the page renders the
 * `unavailable` panel, which is covered by the Vitest suite (R43).
 *
 * The project is chosen from the select's REAL options (derived from the
 * projects API) — no hard-coded project key (R18).
 *
 * Locale: assertions rely on testids / attributes, never on translated copy.
 */

test.describe.configure({ timeout: 90_000 });

const STATUS_PATH = '/api/admin/kb/expansion-learning';

async function openPage(page: Page) {
    await page.goto('/app/admin/kb/expansion-learning');
    const root = page.getByTestId('admin-expansion-learning-page');
    await expect(root).toBeVisible({ timeout: 15_000 });
    return root;
}

/** Selects the first real project in the filter (index 0 is "All projects") and returns its key. */
async function pickFirstProject(page: Page): Promise<string> {
    const trigger = page.getByTestId('admin-expansion-learning-project-filter');
    const menu = await openSelect(trigger);
    const firstProject = menu.getByRole('option').nth(1);
    await expect(firstProject).toBeVisible({ timeout: 10_000 });
    const projectKey = await firstProject.getAttribute('data-value');
    if (!projectKey) throw new Error('No project option is available in the expansion-learning filter.');
    await chooseSelectOption(trigger, projectKey);
    await expect(trigger).toHaveAttribute('data-value', projectKey);
    return projectKey;
}

test.describe('Admin Expansion learning', () => {
    test('loads the real status and queues an analysis for a chosen project', async ({ page }) => {
        const root = await openPage(page);
        // Settled = anything but loading/idle; the flag must be ON in this env.
        await expect(root).toHaveAttribute('data-state', /^(empty|ready)$/, { timeout: 15_000 });
        await expect(root).toHaveAttribute('aria-busy', 'false');

        // Analyze needs a project: without one the action is not offered.
        const analyze = page.getByTestId('admin-expansion-learning-analyze');
        await expect(analyze).toBeDisabled();

        const projectKey = await pickFirstProject(page);
        await expect(root).toHaveAttribute('data-state', /^(empty|ready)$/, { timeout: 15_000 });
        await expect(analyze).toBeEnabled();

        const analyzeCall = page.waitForResponse(
            (r) => r.url().endsWith(`${STATUS_PATH}/analyze`) && r.request().method() === 'POST',
            { timeout: 30_000 },
        );
        await analyze.click();
        const resp = await analyzeCall;
        if (resp.status() !== 202) {
            throw new Error(`POST ${STATUS_PATH}/analyze returned ${resp.status()}: ${await resp.text()}`);
        }
        expect((await resp.json()).project_key).toBe(projectKey);

        await expect(page.getByTestId('admin-expansion-learning-analyze-queued')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('admin-expansion-learning-action-error')).toHaveCount(0);
        // The page re-reads its status and stays in a settled state.
        await expect(root).toHaveAttribute('data-state', /^(empty|ready)$/, { timeout: 15_000 });
    });

    // R13: failure injection — the test above already covers the real-data
    // status load; this one forces a 500 so the FE error branch (R14) is
    // exercised deterministically, then lets the real backend answer the retry.
    test('a 500 from the status read shows the error state, and Retry recovers', async ({ page }) => {
        let failures = 0;
        // R13: failure injection
        // `**` also matches the `?project_key=` query; only the FIRST status
        // GET fails — POST actions and the retry reach the real backend.
        await page.route('**/api/admin/kb/expansion-learning**', (route) => {
            const isStatusRead = route.request().method() === 'GET' && new URL(route.request().url()).pathname === '/api/admin/kb/expansion-learning';
            if (!isStatusRead || failures >= 1) {
                return route.continue();
            }
            failures += 1;
            return route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Server exploded.' }),
            });
        });

        const root = await openPage(page);
        await expect(root).toHaveAttribute('data-state', 'error', { timeout: 15_000 });
        const error = page.getByTestId('admin-expansion-learning-error');
        await expect(error).toBeVisible();
        await expect(error).toContainText('Server exploded.');
        await expect(page.getByTestId('admin-expansion-learning-empty')).toHaveCount(0);

        await page.getByTestId('admin-expansion-learning-retry').click();
        await expect(root).toHaveAttribute('data-state', /^(empty|ready)$/, { timeout: 15_000 });
    });
});
