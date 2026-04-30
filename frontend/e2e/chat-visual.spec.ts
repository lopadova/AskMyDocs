import { expect, type Page } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import { stubChatAssistantReply, type StubChatMessage } from './helpers/stub-chat';

/*
 * v4.0/W3.2 — Visual regression scenarios per PLAN-W3 §7.4.
 *
 * Currently SKIPPED — baselines must capture the post-swap
 * Vercel-AI-SDK-driven chat shape, NOT the legacy useChatMutation
 * render. The W3.2 swap commit (FE rewrite) unskips these tests +
 * commits the baseline images in one atomic step, so what lands in
 * `frontend/e2e/__visual__/` is what the FE actually renders going
 * forward — never a stale legacy frame.
 *
 * Pixel-perfect: every assertion uses `maxDiffPixels: 0`. NO
 * ratio-based tolerance (`maxDiffPixelRatio`, `threshold`). Per
 * Lorenzo (2026-04-30): "ferrea test discipline" on visual
 * regression for the FE rewrite — diff one pixel, fail one test.
 *
 * Convention:
 *   - Snapshot path: frontend/e2e/__visual__/chat-visual.spec.ts/<arg>-<project>-<platform>.png
 *     (consolidated via `snapshotPathTemplate` in `playwright.config.ts`).
 *   - State setup uses the same `seeded` auto-fixture + helpers as
 *     the rest of the chat suite (R13).
 *   - AI-stubbed states intercept `**\/conversations/*\/messages`
 *     (POST + GET) — on the EXTERNAL_PROXY allowlist because the
 *     POST triggers the AI provider.
 *
 * 15 core states + 7 supplementary (refusal x2, confidence x4,
 * thinking-trace expanded x1) per PLAN §7.4. See the matching test
 * names below.
 */

test.describe.configure({ timeout: 60_000 });

/**
 * Thin wrapper that delegates to the centralised
 * `stubChatAssistantReply()` so this file inherits the `postObserved`
 * race-fix (GET returns `[]` BEFORE the user's POST is observed,
 * `[assistant]` AFTER) without duplicating the request-interception
 * machinery here. The earlier hand-rolled local stub eagerly
 * answered the GET refetch with the seeded message even before the
 * `send.click()` POST fired — Playwright tests rely on the chat UI
 * fetching messages on mount, so the visual frame would already
 * contain the assistant turn at the moment of the screenshot,
 * weakening "user sends → assistant renders → screenshot" into
 * "page loads with seeded thread → screenshot".
 *
 * R13: only intercepts `**\/conversations/*\/messages`, on the
 * EXTERNAL_PROXY allowlist (POST triggers the AI provider).
 */
async function stubAssistantReply(page: Page, message: StubChatMessage): Promise<void> {
    await stubChatAssistantReply(page, { assistant: message });
}

const SKIP_REASON = 'Visual baseline pending W3.2 SDK swap';
const PIXEL_PERFECT = { maxDiffPixels: 0 } as const;

test.describe('Chat visual regression (PLAN §7.4)', () => {
    // -------------------------------------------------------------
    // 1-6 — Composer + thread shells (no AI traffic required)
    // -------------------------------------------------------------

    test('1 - empty thread shows the chat-thread-empty card', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await expect(page.getByTestId('chat-thread-empty')).toBeVisible();
        await expect(page).toHaveScreenshot('01-empty-thread.png', PIXEL_PERFECT);
    });

    test('2 - composer empty (no text, no chips)', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await expect(composer(page).form).toBeVisible();
        await expect(page).toHaveScreenshot('02-composer-empty.png', PIXEL_PERFECT);
    });

    test('3 - composer with text typed', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await composer(page).input.fill('How does the remote work stipend apply to senior engineers?');
        await expect(page).toHaveScreenshot('03-composer-with-text.png', PIXEL_PERFECT);
    });

    test('4 - composer with filter chips', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-source').click();
        await page.getByTestId('filter-source-option-pdf').check();
        await page.getByTestId('filter-tab-language').click();
        await page.getByTestId('filter-language-option-en').check();
        await page.getByTestId('filter-popover-close').click();
        await expect(page.getByTestId('filter-chip-source-pdf')).toBeVisible();
        await expect(page).toHaveScreenshot('04-composer-with-filter-chips.png', PIXEL_PERFECT);
    });

    test('5 - composer with mention popover open', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        const { input } = composer(page);
        await input.click();
        await input.pressSequentially('@pol');
        await expect(page.getByTestId('mention-popover')).toBeVisible();
        await expect(page).toHaveScreenshot('05-composer-mention-popover.png', PIXEL_PERFECT);
    });

    test('6 - composer with preset menu open', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-presets-trigger').click();
        await expect(page.getByTestId('chat-filter-presets-menu')).toBeVisible();
        await expect(page).toHaveScreenshot('06-composer-preset-menu.png', PIXEL_PERFECT);
    });

    // -------------------------------------------------------------
    // 7-13 — Filter popover, one frame per dimension tab
    // -------------------------------------------------------------

    test('7 - filter popover on Project tab', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-project').click();
        await expect(page.getByTestId('filter-popover')).toBeVisible();
        await expect(page).toHaveScreenshot('07-filter-popover-project.png', PIXEL_PERFECT);
    });

    test('8 - filter popover on Tag tab', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-tag').click();
        await expect(page.getByTestId('filter-popover')).toBeVisible();
        await expect(page).toHaveScreenshot('08-filter-popover-tag.png', PIXEL_PERFECT);
    });

    test('9 - filter popover on Source tab', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-source').click();
        await expect(page.getByTestId('filter-popover')).toBeVisible();
        await expect(page).toHaveScreenshot('09-filter-popover-source.png', PIXEL_PERFECT);
    });

    test('10 - filter popover on Canonical tab', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-canonical').click();
        await expect(page.getByTestId('filter-popover')).toBeVisible();
        await expect(page).toHaveScreenshot('10-filter-popover-canonical.png', PIXEL_PERFECT);
    });

    test('11 - filter popover on Folder tab', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-folder').click();
        await expect(page.getByTestId('filter-popover')).toBeVisible();
        await expect(page).toHaveScreenshot('11-filter-popover-folder.png', PIXEL_PERFECT);
    });

    test('12 - filter popover on Date tab', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-date').click();
        await expect(page.getByTestId('filter-popover')).toBeVisible();
        await expect(page).toHaveScreenshot('12-filter-popover-date.png', PIXEL_PERFECT);
    });

    test('13 - filter popover on Language tab', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-language').click();
        await expect(page.getByTestId('filter-popover')).toBeVisible();
        await expect(page).toHaveScreenshot('13-filter-popover-language.png', PIXEL_PERFECT);
    });

    // -------------------------------------------------------------
    // 14-15 — Thread error state + mid-stream assistant render
    // -------------------------------------------------------------

    test('14 - thread error state (chat-thread-error)', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        /* R13: failure injection — a 500 on the messages list is the
         * only realistic way to drive `data-state="error"` on the
         * thread without losing pixel determinism. Counterpart real-
         * data scenarios live in chat.spec.ts. */
        await page.route('**/conversations/*/messages', (route) => {
            if (route.request().method() === 'GET') {
                void route.fulfill({ status: 500, body: 'Internal Server Error' });
                return;
            }
            void route.fallback();
        });
        await page.goto('/app/chat');
        // Force a conversation context so the GET fires.
        await composer(page).input.fill('Trigger an error frame');
        await composer(page).send.click();
        await expect(page.getByTestId('chat-thread-error')).toBeVisible({ timeout: 30_000 });
        await expect(page).toHaveScreenshot('14-thread-error.png', PIXEL_PERFECT);
    });

    test('15 - mid-stream assistant render (sampled at ~50% completion)', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        /*
         * The mid-stream frame is captured by stalling the assistant
         * payload mid-flight: the fulfil() resolves with HALF the body
         * after a short delay so the UI commits the streaming bubble
         * (data-state=loading on chat-thread, partial Markdown body)
         * to the DOM before the test reads it.
         *
         * The W3.2 swap commit will replace this with a true SSE
         * stream pause via the AI SDK's streaming primitives — at
         * which point the same selectors keep the assertion stable.
         */
        await page.route('**/conversations/*/messages', async (route) => {
            const method = route.request().method();
            if (method === 'GET') {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify([]),
                });
                return;
            }
            if (method !== 'POST') {
                await route.fallback();
                return;
            }
            // 600ms stall — long enough that the streaming placeholder
            // commits to the DOM but short enough for the 60s test
            // timeout. The screenshot is taken BEFORE this resolves.
            await new Promise((resolve) => setTimeout(resolve, 600));
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    id: 9001,
                    role: 'assistant',
                    content: 'Streaming the first half of the answer body',
                    metadata: { provider: 'mock', model: 'mock', citations: [] },
                    rating: null,
                    created_at: new Date().toISOString(),
                }),
            });
        });
        await page.goto('/app/chat');
        await composer(page).input.fill('Long-form mid-stream frame');
        await composer(page).send.click();
        // Capture while the thread is still loading (i.e. before
        // the stalled fulfil resolves).
        await expect(thread(page)).toHaveAttribute('data-state', 'loading', { timeout: 5_000 });
        await expect(page).toHaveScreenshot('15-mid-stream-assistant.png', PIXEL_PERFECT);
    });

    // -------------------------------------------------------------
    // Supplementary states — PLAN §7.4 lists these as "if they fit
    // cleanly". They follow the same stub-and-snapshot recipe as
    // chat-refusal.spec.ts; AI provider boundary only.
    // -------------------------------------------------------------

    test('16 - refusal: no_relevant_context', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await stubAssistantReply(page, {
            id: 7001,
            role: 'assistant',
            content: 'No documents in the knowledge base match this question.',
            metadata: {
                provider: 'none',
                model: 'none',
                chunks_count: 0,
                latency_ms: 22,
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
        await composer(page).input.fill('Off-topic question');
        await composer(page).send.click();
        await waitForThreadReady(page, 30_000);
        await expect(page.getByTestId('refusal-notice')).toBeVisible();
        await expect(page).toHaveScreenshot('16-refusal-no-relevant-context.png', PIXEL_PERFECT);
    });

    test('17 - refusal: llm_self_refusal', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await stubAssistantReply(page, {
            id: 7002,
            role: 'assistant',
            content: 'The AI cannot answer this question based on the provided documents.',
            metadata: {
                provider: 'openai',
                model: 'gpt-4o-mini',
                chunks_count: 3,
                latency_ms: 1250,
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
        await composer(page).input.fill('Out-of-scope query');
        await composer(page).send.click();
        await waitForThreadReady(page, 30_000);
        await expect(page.getByTestId('refusal-notice')).toBeVisible();
        await expect(page).toHaveScreenshot('17-refusal-llm-self-refusal.png', PIXEL_PERFECT);
    });

    test('18 - confidence: high tier (>=80)', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await stubAssistantReply(page, {
            id: 7003,
            role: 'assistant',
            content: 'The remote work stipend applies to full-time employees after 90 days.',
            metadata: {
                provider: 'openai',
                model: 'gpt-4o-mini',
                chunks_count: 4,
                latency_ms: 980,
                citations: [],
                refusal_reason: null,
                confidence: 92,
            },
            rating: null,
            confidence: 92,
            refusal_reason: null,
            created_at: new Date().toISOString(),
        });
        await page.goto('/app/chat');
        await composer(page).input.fill('High-confidence grounded query');
        await composer(page).send.click();
        await waitForThreadReady(page, 30_000);
        await expect(page.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'high');
        await expect(page).toHaveScreenshot('18-confidence-high.png', PIXEL_PERFECT);
    });

    test('19 - confidence: moderate tier (50-79)', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await stubAssistantReply(page, {
            id: 7004,
            role: 'assistant',
            content: 'A partially grounded answer with one strong citation.',
            metadata: {
                provider: 'openai',
                model: 'gpt-4o-mini',
                chunks_count: 1,
                latency_ms: 1080,
                citations: [],
                refusal_reason: null,
                confidence: 64,
            },
            rating: null,
            confidence: 64,
            refusal_reason: null,
            created_at: new Date().toISOString(),
        });
        await page.goto('/app/chat');
        await composer(page).input.fill('Mid-confidence query');
        await composer(page).send.click();
        await waitForThreadReady(page, 30_000);
        await expect(page.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'moderate');
        await expect(page).toHaveScreenshot('19-confidence-moderate.png', PIXEL_PERFECT);
    });

    test('20 - confidence: low tier (<50)', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await stubAssistantReply(page, {
            id: 7005,
            role: 'assistant',
            content: 'A weakly grounded answer; please verify against the cited sources.',
            metadata: {
                provider: 'openai',
                model: 'gpt-4o-mini',
                chunks_count: 1,
                latency_ms: 1140,
                citations: [],
                refusal_reason: null,
                confidence: 31,
            },
            rating: null,
            confidence: 31,
            refusal_reason: null,
            created_at: new Date().toISOString(),
        });
        await page.goto('/app/chat');
        await composer(page).input.fill('Low-confidence query');
        await composer(page).send.click();
        await waitForThreadReady(page, 30_000);
        await expect(page.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'low');
        await expect(page).toHaveScreenshot('20-confidence-low.png', PIXEL_PERFECT);
    });

    test('21 - confidence: refused tier (grey badge on refusal)', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await stubAssistantReply(page, {
            id: 7006,
            role: 'assistant',
            content: 'No documents in the knowledge base match this question.',
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
        await composer(page).input.fill('Refused-tier query');
        await composer(page).send.click();
        await waitForThreadReady(page, 30_000);
        await expect(page.getByTestId('confidence-badge')).toHaveAttribute('data-state', 'refused');
        await expect(page).toHaveScreenshot('21-confidence-refused.png', PIXEL_PERFECT);
    });

    test('22 - thinking trace expanded', async ({ page }) => {
        test.skip(true, SKIP_REASON);
        await stubAssistantReply(page, {
            id: 7007,
            role: 'assistant',
            content: 'Final grounded answer body.',
            metadata: {
                provider: 'openai',
                model: 'gpt-4o-mini',
                chunks_count: 3,
                latency_ms: 1450,
                citations: [],
                refusal_reason: null,
                confidence: 78,
                reasoning_steps: [
                    'Identify which canonical docs match the query.',
                    'Check the supersedes graph for newer revisions.',
                    'Compose the answer from the top three citations.',
                ],
            },
            rating: null,
            confidence: 78,
            refusal_reason: null,
            reasoning_steps: [
                'Identify which canonical docs match the query.',
                'Check the supersedes graph for newer revisions.',
                'Compose the answer from the top three citations.',
            ],
            created_at: new Date().toISOString(),
        });
        await page.goto('/app/chat');
        await composer(page).input.fill('Show me your reasoning steps');
        await composer(page).send.click();
        await waitForThreadReady(page, 30_000);
        await page.getByTestId('chat-thinking-trace-toggle').click();
        await expect(page.getByTestId('chat-thinking-trace')).toHaveAttribute('data-state', 'open');
        await expect(page).toHaveScreenshot('22-thinking-trace-expanded.png', PIXEL_PERFECT);
    });
});
