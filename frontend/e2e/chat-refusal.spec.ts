import { expect, type Page } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';

/**
 * Stub BOTH POST /conversations/<id>/messages (the assistant-reply
 * endpoint) and GET /conversations/<id>/messages (the list refetch
 * useChatMutation triggers via invalidateQueries on success).
 *
 * Without the GET stub, the refetch hits the real BE which has no
 * record of the mocked POST → assistant message disappears before
 * the assertions land. Mirrors the chat.spec.ts pattern (Copilot #12
 * fix). Keeping it as a helper so each test stays focused on
 * declaring its own assistant payload.
 */
async function stubAssistantReply(page: Page, body: Record<string, unknown>): Promise<void> {
    await page.route('**/conversations/*/messages', async (route) => {
        const method = route.request().method();
        if (method === 'GET') {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify([body]),
            });
            return;
        }
        if (method !== 'POST') {
            await route.fallback();
            return;
        }
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(body),
        });
    });
}

/*
 * T3.7 / M3-FE — Refusal payload rendering scenarios.
 *
 * The BE refusal contract (T3.3 + T3.4 + T3.8-BE):
 *   - `refusal_reason` is a stable English tag ('no_relevant_context'
 *     | 'llm_self_refusal' | null)
 *   - `content` is the LOCALIZED user-facing string (en or it from
 *     lang/{en,it}/kb.php)
 *   - `confidence` is 0 on refusal payloads
 *   - `metadata.citations` is empty
 *
 * The FE must:
 *   - render <RefusalNotice> in place of the normal markdown body
 *   - skip the citations strip
 *   - show a 'refused' confidence badge
 *
 * R13: this scenario uses page.route() to fake the AI provider AT
 * THE LARAVEL ENDPOINT (the route Laravel exposes is conversations/messages)
 * because the goal is to control the BE response shape verifiably,
 * NOT to test the LLM provider integration. The BE refusal logic is
 * fully covered by KbChatRefusalTest + KbChatSentinelTest at the
 * PHPUnit layer; the FE spec proves the rendering pipeline only.
 */

// Per-test timeout bumped from the 20s default — under php -S
// single-threaded backend + SQLite migrate:fresh, the `seeded`
// fixture alone can take 10-15s before the test logic runs. CI runs
// against Postgres which is much faster; this ceiling only kicks in
// on local boxes that run the spec end-to-end.
test.describe.configure({ timeout: 60_000 });

test.describe('Chat refusal rendering', () => {
    test('no_relevant_context refusal renders RefusalNotice with grey confidence badge', async ({ page }) => {
        await stubAssistantReply(page, {
            id: 2001,
            role: 'assistant',
            // English copy from lang/en/kb.php (T3.8-BE).
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
            rating: null,
            confidence: 0,
            refusal_reason: 'no_relevant_context',
            created_at: new Date().toISOString(),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('How do I configure Kubernetes?');
        await send.click();
        await waitForThreadReady(page, 30_000);

        // Strict-mode locators (no .first()) — useChatMutation now
        // dedupes by message id when merging the assistant reply,
        // preventing the brief duplicate render. If two refusal-notice
        // elements appear, that's a real regression, not a render race.
        const refusalNotice = page.getByTestId('refusal-notice');
        await expect(refusalNotice).toBeVisible({ timeout: 30_000 });
        await expect(refusalNotice).toHaveAttribute('data-reason', 'no_relevant_context');
        await expect(refusalNotice).toHaveAttribute('role', 'status');
        await expect(refusalNotice).toHaveAttribute('aria-live', 'polite');
        await expect(page.getByTestId('refusal-notice-body')).toContainText(
            'No documents in the knowledge base match this question.',
        );

        // Helper text guides the user toward a remediation.
        await expect(page.getByTestId('refusal-notice-hint')).toContainText(
            /broadening filters|adding more documents/i,
        );

        // Confidence badge renders 'refused' tier (grey), NOT 'low'.
        const badge = page.getByTestId('confidence-badge');
        await expect(badge).toBeVisible();
        await expect(badge).toHaveAttribute('data-state', 'refused');

        // Citations strip MUST NOT render on refusal — even though the
        // metadata.citations array is empty by contract, this guard
        // catches a future regression that surfaces stale citations.
        await expect(page.getByTestId('citations-strip')).not.toBeVisible();
    });

    test('llm_self_refusal renders RefusalNotice with the LLM-self-refusal hint', async ({ page }) => {
        await stubAssistantReply(page, {
            id: 2002,
            role: 'assistant',
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
            rating: null,
            confidence: 0,
            refusal_reason: 'llm_self_refusal',
            created_at: new Date().toISOString(),
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

    test('grounded answer with high confidence shows green badge, no refusal notice', async ({ page }) => {
        // Counter-test to make sure the refusal-rendering branch ONLY
        // fires when the BE actually emits a refusal. A high-confidence
        // grounded answer must render the normal Markdown body + a
        // green/'high' badge.
        await stubAssistantReply(page, {
            id: 2003,
            role: 'assistant',
            content: 'The remote work stipend applies to full-time employees after 90 days.',
            metadata: {
                provider: 'openai',
                model: 'gpt-4o-mini',
                chunks_count: 3,
                latency_ms: 980,
                citations: [],
                refusal_reason: null,
                confidence: 87,
            },
            rating: null,
            confidence: 87,
            refusal_reason: null,
            created_at: new Date().toISOString(),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Remote work stipend?');
        await send.click();
        await waitForThreadReady(page, 30_000);

        // No refusal notice on the grounded path.
        await expect(page.getByTestId('refusal-notice')).not.toBeVisible();

        // High-confidence badge.
        const badge = page.getByTestId('confidence-badge');
        await expect(badge).toBeVisible({ timeout: 15_000 });
        await expect(badge).toHaveAttribute('data-state', 'high');
        await expect(badge).toContainText('87/100');
    });

    test('moderate confidence (50-79) renders yellow tier', async ({ page }) => {
        await stubAssistantReply(page, {
            id: 2004,
            role: 'assistant',
            content: 'A partially-grounded answer with one citation.',
            metadata: {
                provider: 'openai',
                model: 'gpt-4o-mini',
                chunks_count: 1,
                latency_ms: 1050,
                citations: [],
                refusal_reason: null,
                confidence: 62,
            },
            rating: null,
            confidence: 62,
            refusal_reason: null,
            created_at: new Date().toISOString(),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Mid-quality query');
        await send.click();
        await waitForThreadReady(page, 30_000);

        const badge = page.getByTestId('confidence-badge');
        await expect(badge).toBeVisible({ timeout: 15_000 });
        await expect(badge).toHaveAttribute('data-state', 'moderate');
    });

    test('Italian-locale refusal body renders verbatim (BE owns localization)', async ({ page }) => {
        // Pin L22: the FE never re-translates; whatever string the BE
        // delivered is what shows up. Italian seed → Italian body.
        await stubAssistantReply(page, {
            id: 2005,
            role: 'assistant',
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
            rating: null,
            confidence: 0,
            refusal_reason: 'no_relevant_context',
            created_at: new Date().toISOString(),
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
