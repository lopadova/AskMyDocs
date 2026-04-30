import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import {
    buildAssistantMessage,
    stubChatAssistantReply,
} from './helpers/stub-chat';

/*
 * v4.0/W3.2 — Chat-stream @mention round-trip during streaming
 * scenarios per
 * docs/v4-platform/PLAN-W3-vercel-chat-migration.md §7.3.
 *
 * Currently SKIPPED — these specs assert against the SSE-streaming
 * chat UI which lands in the W3.2 swap commit (helper-switch from
 * sync JSON to SSE protocol). The unskip happens in that commit.
 *
 * R13 strategy: stubChatAssistantReply() targets
 * /conversations/{id}/messages, which is on the EXTERNAL_PROXY_PATTERNS
 * allowlist (the route triggers the AI provider). The W3.2 swap will
 * also update the helper to target /messages/stream — same allowlist
 * applies. /api/kb/documents/search (mention autocomplete) is INTERNAL
 * and exercised against real DemoSeeder-seeded data — never stubbed.
 *
 * Mention contract preserved from chat-mention.spec.ts: typing `@<term>`
 * shows the popover, results land via the real /api/kb/documents/search
 * endpoint, selecting a doc adds it to filters.doc_ids which threads
 * into the next turn's request body.
 */

// Per-test timeout bumped from the 20s default — see chat-refusal.spec.ts
// for the rationale (slow seeded fixture under local php -S + SQLite).
test.describe.configure({ timeout: 60_000 });

test.describe('Chat-stream @mention round-trip during streaming', () => {
    test('mention popover opens for @policy keystroke while previous turn is streaming', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: with a turn in flight (data-state =
        // loading), typing @<term> in the composer must open the
        // mention popover and load results from the real
        // /api/kb/documents/search endpoint. The streaming turn must
        // not be interrupted by the keystroke / popover render.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 5001,
                content: 'A streamed answer long enough to overlap with the user typing the next message.',
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('First turn — start the stream.');
        await send.click();

        // While streaming, type @policy in the composer (the input
        // remains responsive — useChat's input field is independent of
        // the streaming state).
        await expect(thread(page)).toHaveAttribute('data-state', 'loading');
        await input.click();
        await input.pressSequentially('@policy');

        const popover = page.getByTestId('mention-popover');
        await expect(popover).toBeVisible({ timeout: 10_000 });
        await expect(popover).toHaveAttribute('data-state', /ready|empty/, { timeout: 10_000 });

        // Stream continues uninterrupted.
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
    });

    test('selected mention persists across stream completion and threads into the next turns filters.doc_ids', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: select a mention from the popover; that
        // mention's doc_id appears in filters.doc_ids. Send the next
        // turn; the captured POST body MUST contain the same doc_id
        // in filters.doc_ids — proving the mention persisted across
        // the stream + the next request inherited it.
        let capturedBody: { content?: string; filters?: Record<string, unknown> } | null = null;

        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 5002,
                content: 'Second-turn streamed answer with a mention threaded in.',
            }),
            onPost: (body) => {
                capturedBody = body;
            },
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);

        // First, type @ to open the popover, then click the first
        // visible mention option. The DemoSeeder seeds
        // remote-work-policy among other slugs; the popover's
        // ready-state result list is live data, so the spec author
        // for the W3.2 swap commit may need to bind to a known seed
        // doc_id here once the implementation lands.
        await input.click();
        await input.pressSequentially('@policy');
        const popover = page.getByTestId('mention-popover');
        await expect(popover).toBeVisible({ timeout: 10_000 });
        // Select the first option — the W3.2 mention-popover renders
        // mention-option-{id} children; clicking adds the doc to the
        // filters.doc_ids set.
        await popover.locator('[data-testid^="mention-option-"]').first().click();

        // Now send the actual question.
        await input.fill('Tell me about this document.');
        await send.click();
        await waitForThreadReady(page, 30_000);

        expect(capturedBody, 'streaming POST body must be captured by route handler').not.toBeNull();
        expect(capturedBody!.filters).toBeDefined();
        const docIds = (capturedBody!.filters as { doc_ids?: unknown }).doc_ids;
        expect(Array.isArray(docIds)).toBe(true);
        expect((docIds as unknown[]).length).toBeGreaterThan(0);
    });
});
