import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import {
    buildAssistantMessage,
    stubChatAssistantReply,
} from './helpers/stub-chat';

/*
 * v4.0/W3.2 — Chat-stream refusal-as-data-refusal-part scenarios per
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
 * applies (verify-e2e-real-data.sh treats /conversations/[^"]/messages
 * as a wildcard match including the /stream suffix).
 *
 * Refusal stream contract (PLAN §6.1): the SSE response emits
 *   data-refusal { reason, body, hint } -> data-confidence
 *     { confidence: null, tier: 'refused' } -> finish
 * with NO text-delta events. The FE must:
 *   - render <RefusalNotice> in place of the markdown body
 *   - skip the citations strip
 *   - show a 'refused' confidence badge
 */

// Per-test timeout bumped from the 20s default — the seeded fixture
// alone consumes 10–15 s under local php -S + SQLite before the test
// body runs. CI runs against Postgres which is faster; this ceiling
// only kicks in on local boxes.
test.describe.configure({ timeout: 60_000 });

test.describe('Chat-stream refusal as data-refusal part', () => {
    test('no_relevant_context refusal emitted via data-refusal stream renders RefusalNotice', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: the SSE stub emits a data-refusal event
        // (no text-delta events). After waitForThreadReady, the
        // assistant message body must be empty, refusal-notice must
        // be visible with data-reason="no_relevant_context", role
        // attributes preserved (status + aria-live), and the
        // confidence-badge data-state="refused" (grey tier).
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 8001,
                content: 'No documents in the knowledge base match this question.',
                metadata: {
                    provider: 'none',
                    model: 'none',
                    chunks_count: 0,
                    latency_ms: 25,
                    citations: [],
                    refusal_reason: 'no_relevant_context',
                    confidence: 0,
                },
                refusal_reason: 'no_relevant_context',
                confidence: 0,
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('How do I configure Kubernetes?');
        await send.click();
        await waitForThreadReady(page, 30_000);

        // RefusalNotice surfaces, with the stable English reason tag.
        const refusalNotice = page.getByTestId('refusal-notice');
        await expect(refusalNotice).toBeVisible({ timeout: 30_000 });
        await expect(refusalNotice).toHaveAttribute('data-reason', 'no_relevant_context');
        await expect(refusalNotice).toHaveAttribute('role', 'status');
        await expect(refusalNotice).toHaveAttribute('aria-live', 'polite');
        await expect(page.getByTestId('refusal-notice-body')).toContainText(
            'No documents in the knowledge base match this question.',
        );
        await expect(page.getByTestId('refusal-notice-hint')).toContainText(
            /broadening filters|adding more documents/i,
        );

        // Refused (grey) confidence tier.
        const badge = page.getByTestId('confidence-badge');
        await expect(badge).toBeVisible();
        await expect(badge).toHaveAttribute('data-state', 'refused');

        // Citations strip MUST NOT render on refusal — even though the
        // refusal stream emits zero source events, this guard catches
        // a future regression that surfaces stale citations from the
        // previous turn.
        await expect(page.getByTestId('citations-strip')).not.toBeVisible();

        // Thread reaches 'ready' even though no text-delta events
        // ever fired — the refusal stream still terminates with a
        // finish event.
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
    });

    test('llm_self_refusal stream renders RefusalNotice with llm-self-refusal hint copy', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: data-reason="llm_self_refusal"; the
        // hint copy differs from the no_relevant_context branch
        // (matches /rephrasing the question/i). Confidence badge
        // remains 'refused' regardless of which refusal reason fired.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 8002,
                content: 'The AI cannot answer this question based on the provided documents.',
                metadata: {
                    provider: 'openai',
                    model: 'gpt-4o-mini',
                    chunks_count: 3,
                    latency_ms: 1200,
                    citations: [],
                    refusal_reason: 'llm_self_refusal',
                    confidence: 0,
                },
                refusal_reason: 'llm_self_refusal',
                confidence: 0,
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Anything off-topic?');
        await send.click();
        await waitForThreadReady(page, 30_000);

        const refusalNotice = page.getByTestId('refusal-notice');
        await expect(refusalNotice).toBeVisible({ timeout: 30_000 });
        await expect(refusalNotice).toHaveAttribute('data-reason', 'llm_self_refusal');

        // The per-reason hint differs from the no_relevant_context one.
        await expect(page.getByTestId('refusal-notice-hint')).toContainText(
            /rephrasing the question/i,
        );

        // Refused tier on the badge regardless of which refusal reason fired.
        await expect(page.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'refused');
    });

    test('Italian-locale refusal body renders verbatim from the stream (BE owns localization)', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // R24: BE owns the human-readable copy; the FE renders
        // verbatim. The machine-readable identifier
        // (refusal_reason / data-reason) stays English.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 8003,
                content: 'Nessun documento nella knowledge base corrisponde a questa domanda.',
                metadata: {
                    provider: 'none',
                    model: 'none',
                    chunks_count: 0,
                    latency_ms: 18,
                    citations: [],
                    refusal_reason: 'no_relevant_context',
                    confidence: 0,
                },
                refusal_reason: 'no_relevant_context',
                confidence: 0,
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Domanda in italiano');
        await send.click();
        await waitForThreadReady(page, 30_000);

        await expect(page.getByTestId('refusal-notice-body')).toContainText(
            'Nessun documento nella knowledge base corrisponde a questa domanda.',
        );
        // Reason tag stays English (machine-readable identifier).
        await expect(page.getByTestId('refusal-notice')).toHaveAttribute(
            'data-reason',
            'no_relevant_context',
        );
    });
});
