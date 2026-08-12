import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import { seedDb } from './setup-helpers';

/*
 * v8.5 — the definitive real browser transport E2E.
 *
 * The chat now starts a durable agent run and consumes its resumable event
 * feed before reloading canonical history. These scenarios exercise that REAL
 * path without intercepting internal requests, including retrieval, event
 * rendering, citations, persistence, and terminal state handling.
 *
 * Determinism without a live LLM: the server runs with AI_PROVIDER=fake /
 * AI_EMBEDDINGS_PROVIDER=fake (CI: the workflow's "Start Laravel server"
 * step; local: playwright.config.ts's webServer block). FakeProvider streams
 * a canned answer and returns a constant embedding vector.
 *
 * The `E2eStreamSeeder` ingests ONE hr-portal doc through the REAL
 * DocumentIngestor path (inline, via /testing/seed) so its chunk is embedded
 * with the same constant vector — DemoSeeder's chunks have a NULL embedding
 * and are NOT vector-searchable, which is exactly why every other chat spec
 * stubs retrieval. With the fake provider, the query embeds to the same
 * vector → cosine 1.0 → the doc is ALWAYS retrieved → the controller ALWAYS
 * emits a real `source-url` citation frame. The fresh `/app/chat` turn scopes
 * to PROJECTS[0] = `hr-portal` (ChatView), so the seeded doc is in scope.
 *
 * NOTHING is stubbed here (R13): no `page.route` on internal routes. The whole
 * round-trip — auth, retrieval, durable agent events, canonical history, and
 * React rendering — runs for real.
 */

test.describe.configure({ timeout: 60_000 });

test.describe('Chat agent transport — real durable run and event feed', () => {
    /**
     * Empty retrieval remains a valid completed durable run.
     *
     * Without E2eStreamSeeder the DemoSeeder docs have NULL embeddings, so the
     * run has no grounded evidence. The planner still reaches a terminal state
     * and persists its deterministic fallback answer. The assertions ensure
     * that activity settles, no citation is invented, canonical history is
     * visible, and no event/UI validation error fires.
     */
    test('a query with no matching docs completes without invented citations or transport errors', async ({ page }) => {
        // seeded auto-fixture ran DemoSeeder — NULL-embedding chunks only.
        // We intentionally do NOT call seedDb(page, 'E2eStreamSeeder')
        // so retrieval returns empty → shouldRefuse → refusal stream path.
        const fatalErrors: string[] = [];
        page.on('pageerror', (e) => fatalErrors.push(`pageerror: ${e.message}`));
        page.on('console', (m) => {
            if (m.type() === 'error' && /type validation failed|invalid_union|unrecognized_keys/i.test(m.text())) {
                fatalErrors.push(`console.error: ${m.text()}`);
            }
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('How many days per week can I work remotely?');
        await send.click();

        const activity = page.getByTestId('agent-activity-bar');
        await expect(activity).toHaveAttribute('data-state', 'settled', { timeout: 30_000 });
        await expect(activity).toContainText('The answer is ready.');

        const assistant = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').last();
        await expect(assistant).toContainText('I completed the search with the available information.', { timeout: 30_000 });
        await expect(page.getByTestId('chat-citations')).not.toBeVisible();

        // The event feed completed and canonical history was reloaded.
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');

        // No browser-side transport validation error.
        expect(fatalErrors, fatalErrors.join('\n')).toEqual([]);
    });

    test('a grounded chat turn streams text + a citation chip + completes, with NO SDK validation error', async ({ page }) => {
        // Capture the exact failure mode of the v8.4 bugs: the SDK transport
        // throws "Type validation failed" on a bad frame, surfacing as a
        // pageerror / console error.
        const fatalErrors: string[] = [];
        page.on('pageerror', (e) => fatalErrors.push(`pageerror: ${e.message}`));
        page.on('console', (m) => {
            if (m.type() === 'error' && /type validation failed|invalid_union|unrecognized_keys/i.test(m.text())) {
                fatalErrors.push(`console.error: ${m.text()}`);
            }
        });

        // Seed one vector-searchable hr-portal doc through the REAL ingest
        // path (inline, fake embeddings) so the chat turn below retrieves it.
        // The `seeded` auto-fixture already ran DemoSeeder + logged us in;
        // this is purely additive (db:seed --class, no migrate:fresh).
        await seedDb(page, 'E2eStreamSeeder');

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('How many days per week can I work remotely?');
        await send.click();

        // The assistant answer streams in (text-* frames parsed by the SDK).
        const assistant = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').last();
        await expect(assistant).toBeVisible({ timeout: 30_000 });
        await expect
            .poll(async () => (await assistant.innerText()).trim().length, { timeout: 30_000 })
            .toBeGreaterThan(0);

        // The citation chip rendered → the real `source-url` frame parsed
        // cleanly through the SDK (the v8.4 crash #1 would have aborted here).
        await expect(page.getByTestId('chat-citations')).toBeVisible({ timeout: 30_000 });
        await expect(page.getByTestId('chat-citation-0')).toBeVisible();

        // v8.11/P10 — the citation carries its provenance tier; the seeded
        // E2eStreamSeeder doc is human-vouched, so the chip is data-tier=human
        // (and shows no `auto` badge). Proves the BE→FE tier flow end-to-end.
        await expect(page.getByTestId('chat-citation-0')).toHaveAttribute('data-tier', 'human');
        await expect(page.getByTestId('chat-citation-0-tier')).toHaveCount(0);

        // The stream reached its terminal state → the `finish` frame parsed
        // cleanly (the v8.4 crash #2 would have aborted before ready).
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');

        // No SDK validation error fired at any point.
        expect(fatalErrors, fatalErrors.join('\n')).toEqual([]);
    });
});
