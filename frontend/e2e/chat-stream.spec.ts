import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import {
    buildAssistantMessage,
    stubChatAssistantReply,
} from './helpers/stub-chat';

/*
 * v4.0/W3.2 — Chat-stream token-level UX scenarios per
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
 */

// Per-test timeout bumped from the 20s default — the seeded fixture
// alone consumes 10–15 s under local php -S + SQLite before the test
// body runs, and the streaming scenarios poll data-state transitions
// over multiple seconds. CI runs against Postgres which is faster;
// this ceiling only kicks in on local boxes.
test.describe.configure({ timeout: 60_000 });

test.describe('Chat-stream token-level UX', () => {
    test('progressive render: assistant text grows monotonically over time', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Stub a multi-chunk SSE response. The helper will be switched
        // to emit chunked text-delta events in W3.2; today the
        // synchronous fallback still applies, which is why this test
        // is skipped. Assertion contract for the swap commit author:
        // sample the rendered assistant body at t=200 ms, t=600 ms,
        // t=2 s, prove length(t1) <= length(t2) <= length(t3) AND
        // length(t3) > length(t1).
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 9001,
                content: 'The remote work stipend applies to full-time employees after 90 days.',
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('How does the remote work stipend apply?');
        await send.click();

        const t = thread(page);
        const assistant = page.locator('[data-testid^="chat-message-"][data-role="assistant"]');

        // Sample 1 — wait until the FIRST chunk renders (deterministic,
        // not a fixed-time gamble). Per R12 we poll observable state
        // instead of waitForTimeout — flakiness on slower machines is
        // the failure mode the rule guards against.
        await expect
            .poll(async () => (await assistant.first().innerText()).length, { timeout: 5_000 })
            .toBeGreaterThan(0);
        const lenInitial = (await assistant.first().innerText()).length;
        await expect(t).toHaveAttribute('data-state', 'loading');

        // Sample 2 — wait until the body grows beyond the initial
        // sample. Proves the user observes incremental growth, not
        // an all-at-once render.
        await expect
            .poll(async () => (await assistant.first().innerText()).length, { timeout: 5_000 })
            .toBeGreaterThan(lenInitial);
        const lenMid = (await assistant.first().innerText()).length;

        // Sample 3 — stream completes; capture the final length.
        await waitForThreadReady(page, 30_000);
        const lenFinal = (await assistant.first().innerText()).length;

        // Monotonic growth across the three observed states.
        expect(lenMid).toBeGreaterThan(lenInitial);
        expect(lenFinal).toBeGreaterThanOrEqual(lenMid);
        await expect(t).toHaveAttribute('data-state', 'ready');
    });

    test('data-state transitions in order: idle -> loading -> ready (no skips, no regressions)', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: poll `data-state` via page.evaluate over
        // the full turn lifecycle and prove the observed sequence is
        // a prefix of [idle, loading, ready]. No backwards transitions
        // (loading -> idle, ready -> loading), no skipped states
        // (idle -> ready without loading).
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 9002,
                content: 'A grounded answer.',
            }),
        });

        await page.goto('/app/chat');
        await expect(thread(page)).toHaveAttribute('data-state', 'idle');

        // Per R12 we drive transition-capture through `expect.poll`,
        // which is Playwright's first-class polling primitive (50 ms
        // built-in cadence by default, configurable via `intervals`).
        // The poller's CALLBACK accumulates the observed sequence as
        // a side effect and the polling exits cleanly the first time
        // 'ready' appears — no manual race between a parallel `while`
        // loop and `waitForThreadReady`. The previous shape was a
        // hand-rolled poll loop with `page.waitForTimeout(50)` which
        // is the exact pattern R12 forbids.
        const observed: string[] = [];

        const { input, send } = composer(page);
        await input.fill('Trace state transitions please.');
        await send.click();

        await expect
            .poll(
                async () => {
                    const state = await thread(page).getAttribute('data-state');
                    if (state && observed[observed.length - 1] !== state) {
                        observed.push(state);
                    }
                    return state;
                },
                { timeout: 30_000, intervals: [50, 100, 250] },
            )
            .toBe('ready');

        // Observed must be a strict prefix of [idle, loading, ready].
        const expectedSequence = ['idle', 'loading', 'ready'];
        expect(observed.length).toBeGreaterThanOrEqual(2);
        for (let i = 0; i < observed.length; i += 1) {
            expect(observed[i]).toBe(expectedSequence[i]);
        }
        // We must have seen `loading` (the user-visible streaming state).
        expect(observed).toContain('loading');
        expect(observed[observed.length - 1]).toBe('ready');
    });

    test('typing indicator visible during stream, hidden after ready', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: while data-state="loading", a node with
        // data-testid="chat-typing-indicator" exists and is visible.
        // After data-state="ready", the same node MUST be hidden /
        // detached. New testid introduced in W3.2.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 9003,
                content: 'A grounded answer.',
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Show me the typing indicator.');
        await send.click();

        const indicator = page.getByTestId('chat-typing-indicator');
        // While loading, indicator is visible.
        await expect(thread(page)).toHaveAttribute('data-state', 'loading');
        await expect(indicator).toBeVisible({ timeout: 5_000 });

        await waitForThreadReady(page, 30_000);
        // After ready, indicator must vanish.
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
        await expect(indicator).not.toBeVisible();
    });

    test('stop() mid-stream truncates message and finishes with finish_reason=stopped', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: after 500 ms of streaming, click
        // chat-composer-stop. The assistant body length must NOT grow
        // after the click; the SDK emits its own `finish` event with
        // finish_reason='stopped'; data-state lands on 'ready' (not
        // 'error'); the partial text remains rendered (not rolled
        // back). The W3.2 chat-composer-stop testid replaces the send
        // button while a stream is in flight.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 9004,
                content:
                    'A long answer that would have many chunks of streamed text ' +
                    'so the user has time to click the stop button mid-stream.',
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Generate a long answer please.');
        await send.click();

        // Wait until streaming is genuinely under way — text has begun
        // rendering. Per R12 we poll observable state (assistant body
        // length > 0) instead of a fixed `waitForTimeout(500)` which
        // races on slow CI / pass-spuriously on fast machines where
        // the stream may already be finished.
        await expect(thread(page)).toHaveAttribute('data-state', 'loading');
        const assistant = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').first();
        await expect
            .poll(async () => (await assistant.innerText()).length, { timeout: 5_000 })
            .toBeGreaterThan(0);
        const lengthBeforeStop = (await assistant.innerText()).length;

        await page.getByTestId('chat-composer-stop').click();

        // After clicking stop, data-state lands on ready quickly.
        await expect(thread(page)).toHaveAttribute('data-state', 'ready', { timeout: 5_000 });

        // Text frozen at click-time — must not have grown.
        const lengthAfterStop = (await assistant.innerText()).length;
        expect(lengthAfterStop).toBe(lengthBeforeStop);

        // Partial text is preserved (no rollback to empty).
        expect(lengthAfterStop).toBeGreaterThan(0);
    });

    test('network error mid-stream surfaces error state and preserves rendered text', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        /* R13: failure injection — happy-path covered by "progressive render: assistant text grows monotonically over time" earlier in this file. */
        // Assertion contract: abort the SSE connection AFTER the first
        // chunk has rendered. data-state must transition from
        // 'loading' to 'error'. chat-thread-error must be visible.
        // The previously-rendered partial text must remain (no rollback).
        //
        // IMPORTANT for the swap-commit author: SSE is ONE long-lived
        // HTTP request. Playwright's request-interception API fires
        // once per request, NOT per chunk — so a counter-based
        // abort-after-N-chunks does NOT work via the request-router
        // alone. Two cleaner options:
        //
        //   (a) Server-side scaffold — extend the W3.1 streaming
        //       controller with a `?testing_abort_after=N` query
        //       param that closes the connection after emitting N
        //       chunks. Toggle gated behind APP_ENV=testing.
        //
        //   (b) Client-side fetch wrap — `page.addInitScript()` that
        //       monkey-patches `window.fetch` for the streaming URL,
        //       wraps the ReadableStream, lets N bytes through, then
        //       errors the stream. The SDK's transport sees a stream
        //       error and propagates `status: 'error'`.
        //
        // Stub the conversations-messages refetch via the centralised
        // helper as usual; the abort injection is layered on TOP of
        // that stub by the swap-commit author per (a) or (b).
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 9005,
                content: 'Streamed answer that will be aborted mid-flight.',
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Trigger a mid-stream abort please.');
        await send.click();

        // Wait for some text to render BEFORE the abort fires.
        const assistant = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').first();
        await expect(assistant).toBeVisible({ timeout: 10_000 });
        const lengthBeforeAbort = (await assistant.innerText()).length;
        expect(lengthBeforeAbort).toBeGreaterThan(0);

        // After abort propagates, thread data-state must be 'error'.
        await expect(thread(page)).toHaveAttribute('data-state', 'error', { timeout: 10_000 });
        await expect(page.getByTestId('chat-thread-error')).toBeVisible();

        // Partial text preserved (no rollback).
        const lengthAfterAbort = (await assistant.innerText()).length;
        expect(lengthAfterAbort).toBeGreaterThanOrEqual(lengthBeforeAbort);
    });
});
