import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import { seedDb } from './setup-helpers';

/*
 * v8.5 — the DEFINITIVE browser streaming E2E.
 *
 * This is the test that would have caught the v8.4 chat crashes (source-url
 * providerMetadata + finish.usage). It drives the REAL `/messages/stream` SSE
 * through the REAL `@ai-sdk` transport in the browser — the only layer that
 * validates each UIMessageChunk against the SDK zod schema, which is exactly
 * where those wire-format bugs fired ("Type validation failed …").
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
 * round-trip — auth, retrieval, the real SSE frames, the real SDK transport,
 * the React render — runs for real. A wire-format regression makes the assertions
 * below fail (the stream never reaches `ready` / no chip / a pageerror), instead
 * of crashing at the user's first click.
 */

test.describe.configure({ timeout: 60_000 });

test.describe('Chat streaming — real SSE through the real @ai-sdk transport', () => {
    /**
     * Failure path (R12) — empty retrieval → refusal stream.
     *
     * Without E2eStreamSeeder the DemoSeeder docs have NULL embeddings
     * (not vector-searchable). The retrieval layer returns zero hits →
     * shouldRefuse=true → the controller emits the refusal wire variant:
     *   start → data-refusal → data-confidence → finish
     * instead of the grounded path. This exercises the REAL refusal SSE
     * frames through the REAL @ai-sdk transport — the same layer that
     * validated (and crashed on) bad wire format in v8.4.
     * The assertions confirm: the FE renders RefusalNotice (no citation
     * chip), the stream still reaches `ready`, and no SDK validation
     * error fires on the refusal frames.
     */
    test('a query with no matching docs triggers a refusal stream, renders RefusalNotice, and reaches ready without SDK errors', async ({ page }) => {
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

        // The BE streams the refusal path (data-refusal frame) — the UI
        // must render RefusalNotice, NOT a citation chip.
        await expect(page.getByTestId('refusal-notice')).toBeVisible({ timeout: 30_000 });
        await expect(page.getByTestId('refusal-notice')).toHaveAttribute('data-reason', 'no_relevant_context');
        await expect(page.getByTestId('chat-citations')).not.toBeVisible();

        // The stream still completes: finish frame parsed cleanly → ready.
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');

        // No SDK validation error on the refusal frames.
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
