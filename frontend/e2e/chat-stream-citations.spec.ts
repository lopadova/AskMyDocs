import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import {
    buildAssistantMessage,
    stubChatAssistantReply,
} from './helpers/stub-chat';

/*
 * v4.0/W3.2 — Chat-stream citations-as-source-parts scenarios per
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
 * applies.
 *
 * Citations stream contract (PLAN §6.1): the SSE response emits
 * `source` events BEFORE the first `text-delta`. The FE renders
 * citation chips as they arrive; the strip is fully populated before
 * the body finishes streaming. Hovering a chip mid-stream opens the
 * popover without interrupting the stream.
 */

// Per-test timeout bumped from the 20s default — see chat-refusal.spec.ts
// for the rationale (slow seeded fixture under local php -S + SQLite).
test.describe.configure({ timeout: 60_000 });

test.describe('Chat-stream citations as source parts', () => {
    test('source events arrive and render citation chips BEFORE text streaming completes', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: the SSE stub emits source events first,
        // then text-delta events. While data-state="loading" (text
        // still streaming), chat-citations must already be visible and
        // chat-citation-{idx} chips must be rendered. The W3.2 helper
        // emits citations under message.parts where part.type ===
        // 'source'.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 7001,
                content: 'Per the policy doc, the stipend applies after 90 days.',
                metadata: {
                    provider: 'mock',
                    model: 'mock',
                    citations: [
                        { id: 'doc-101', title: 'Remote work policy', url: '/app/admin/kb/hr-portal/remote-work-policy' },
                        { id: 'doc-102', title: 'Stipend handbook', url: '/app/admin/kb/hr-portal/stipend-handbook' },
                    ],
                    confidence: 87,
                },
                confidence: 87,
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('When does the stipend apply?');
        await send.click();

        // Citations strip rendered while still loading (source events
        // arrive before the first text-delta).
        await expect(thread(page)).toHaveAttribute('data-state', 'loading');
        await expect(page.getByTestId('chat-citations')).toBeVisible({ timeout: 5_000 });
        await expect(page.getByTestId('chat-citation-0')).toBeVisible();
        await expect(page.getByTestId('chat-citation-1')).toBeVisible();

        // Then the stream completes and we land on ready with the
        // citations strip still in place.
        await waitForThreadReady(page, 30_000);
        await expect(page.getByTestId('chat-citations')).toBeVisible();
    });

    test('hovering citation chip mid-stream opens the popover without interrupting the stream', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: at t=500 ms (mid-stream), hover
        // chat-citation-0; chat-citations-popover must open with
        // title + excerpt. The stream must continue uninterrupted to
        // ready (data-state still observable transitioning to ready
        // afterwards).
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 7002,
                content:
                    'A long answer that streams in chunks so the user has time ' +
                    'to hover a citation chip while data-state is still loading.',
                metadata: {
                    provider: 'mock',
                    model: 'mock',
                    citations: [
                        { id: 'doc-101', title: 'Remote work policy', url: '/app/admin/kb/hr-portal/remote-work-policy', excerpt: 'Stipend applies to full-time employees after 90 days.' },
                    ],
                    confidence: 85,
                },
                confidence: 85,
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Tell me about the stipend in detail.');
        await send.click();

        // Wait until streaming is genuinely under way + chip rendered.
        // Per R12 we poll observable state (assistant body has begun
        // rendering text) rather than wait for a fixed 500 ms — that
        // would race on slow CI runners and pass-spuriously on fast
        // ones where the stream is already finished by the time the
        // sleep returns.
        await expect(thread(page)).toHaveAttribute('data-state', 'loading');
        const chip = page.getByTestId('chat-citation-0');
        await expect(chip).toBeVisible({ timeout: 5_000 });
        const assistant = page.locator('[data-testid^="chat-message-"][data-role="assistant"]');
        await expect
            .poll(async () => (await assistant.first().innerText()).length, { timeout: 5_000 })
            .toBeGreaterThan(0);

        // Mid-stream hover — popover opens without disrupting the
        // streaming text.
        await chip.hover();
        const popover = page.getByTestId('chat-citations-popover');
        await expect(popover).toBeVisible({ timeout: 5_000 });
        await expect(popover).toContainText('Remote work policy');

        // Stream continues to completion regardless of the hover.
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
    });

    test('citation count matches parts.filter(type=source).length — 5 source events render 5 chips', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: the SSE stub emits exactly 5 source
        // events; the chat-citations container must carry
        // data-count="5" and exactly 5 chat-citation-{0..4} chips
        // must render.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 7003,
                content: 'Combining five sources into one answer.',
                metadata: {
                    provider: 'mock',
                    model: 'mock',
                    citations: [
                        { id: 'doc-1', title: 'Source 1', url: '/app/admin/kb/p/s1' },
                        { id: 'doc-2', title: 'Source 2', url: '/app/admin/kb/p/s2' },
                        { id: 'doc-3', title: 'Source 3', url: '/app/admin/kb/p/s3' },
                        { id: 'doc-4', title: 'Source 4', url: '/app/admin/kb/p/s4' },
                        { id: 'doc-5', title: 'Source 5', url: '/app/admin/kb/p/s5' },
                    ],
                    confidence: 91,
                },
                confidence: 91,
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Cite five sources please.');
        await send.click();
        await waitForThreadReady(page, 30_000);

        const citations = page.getByTestId('chat-citations');
        await expect(citations).toBeVisible();
        await expect(citations).toHaveAttribute('data-count', '5');

        for (let i = 0; i < 5; i += 1) {
            await expect(page.getByTestId(`chat-citation-${i}`)).toBeVisible();
        }
    });
});
