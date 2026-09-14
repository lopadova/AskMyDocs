import { expect, type Page } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.7/W5 — Cloud Time Machine (per-document version timeline + diff + restore).
 *
 * R13: real `/api/admin/kb/documents/*` endpoints + real DB. ZERO route
 * stubs. The happy path makes the seeded document a two-version family
 * through the REAL ingest endpoint (QUEUE_CONNECTION=sync in the E2E
 * server, so the new version exists when the request returns), opens its
 * timeline, diffs the two versions and reads the v8.36 diff-source contract.
 * The failure paths open a non-existent doc id (real 404 → error state) and
 * ask the version-content endpoint for a version outside the family.
 */

test.describe.configure({ timeout: 90_000 });

const TENANT = 'a-demo';

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
    await page.request.get('/sanctum/csrf-cookie');
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrf) {
        throw new Error('XSRF-TOKEN cookie missing after /sanctum/csrf-cookie');
    }
    return {
        'X-XSRF-TOKEN': decodeURIComponent(xsrf.value),
        'X-Tenant-Id': TENANT,
        Accept: 'application/json',
    };
}

/** Re-ingests the seeded document id 1 with new content so its family has (at least) two versions. */
async function ingestSecondVersionOfDocument1(page: Page): Promise<void> {
    const headers = await csrfHeaders(page);
    const show = await page.request.get('/api/admin/kb/documents/1', { headers });
    if (!show.ok()) {
        throw new Error(`GET /api/admin/kb/documents/1 returned ${show.status()} ${await show.text()}`);
    }
    const doc = (await show.json()).data as { project_key: string; source_path: string; title: string };

    const ingest = await page.request.post('/api/kb/ingest', {
        headers,
        data: {
            documents: [
                {
                    project_key: doc.project_key,
                    source_path: doc.source_path,
                    title: doc.title,
                    content: `# ${doc.title}\n\nSecond version written by the Time Machine E2E at ${Date.now()}.\n`,
                },
            ],
        },
    });
    if (![200, 201, 202].includes(ingest.status())) {
        throw new Error(`POST /api/kb/ingest returned ${ingest.status()} ${await ingest.text()}`);
    }
}

test.describe('Admin Time Machine', () => {
    test('diffs two real versions of a document and reports the diff source', async ({ page }) => {
        await ingestSecondVersionOfDocument1(page);

        // Document id 1 is now the ARCHIVED version of a two-version family;
        // the Time Machine resolves the whole family from any member.
        await page.goto('/app/admin/kb/time-machine/1');
        await expect(page.getByTestId('kb-time-machine-view')).toBeVisible({ timeout: 15_000 });
        const timeline = page.getByTestId('kb-time-machine-timeline');
        await expect(timeline).toBeVisible({ timeout: 15_000 });

        const rows = page.locator('[data-testid^="kb-time-machine-version-"][data-version-status]');
        await expect(rows.nth(1)).toBeVisible();
        expect(await rows.count()).toBeGreaterThanOrEqual(2);

        // v8.36 / ADR 0030 — every real row carries the version provenance
        // contract: an actor line (or "unknown actor" for rows that predate
        // it) and an explicit has-artifact flag — never a missing attribute.
        const first = rows.nth(0);
        const second = rows.nth(1);
        await expect(first).toHaveAttribute('data-has-artifact', /^(true|false)$/);
        await expect(first.locator('[data-testid$="-actor"]')).toBeVisible();
        await expect(second).toHaveAttribute('data-has-artifact', /^(true|false)$/);

        // Diff the two versions through the real `/versions/diff` endpoint.
        await first.getByRole('button', { name: /^Diff from/ }).click();
        await second.getByRole('button', { name: /^Diff to/ }).click();
        await expect(page.getByTestId('kb-time-machine-diff')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-time-machine-diff')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('kb-time-machine-diff-summary')).toBeVisible({ timeout: 15_000 });
        // v8.36 / ADR 0030 §5 — the note says whether the diff compares the
        // stored documents (faithful) or chunk reconstructions; it is never absent.
        await expect(page.getByTestId('kb-time-machine-diff-source')).toHaveAttribute('data-diff-faithful', /^(true|false)$/);
        await expect(page.getByTestId('kb-time-machine-diff-body')).toBeVisible();
    });

    test('shows the error state for a non-existent document (real 404)', async ({ page }) => {
        await page.goto('/app/admin/kb/time-machine/999999');
        await expect(page.getByTestId('kb-time-machine-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-time-machine-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-time-machine-error')).toHaveAttribute('data-state', 'error');
    });

    test('version content outside the family is a real 404, not an empty 200 (R14)', async ({ page }) => {
        const headers = await csrfHeaders(page);
        const res = await page.request.get('/api/admin/kb/documents/1/versions/999999/content', { headers });
        expect(res.status()).toBe(404);
    });
});
