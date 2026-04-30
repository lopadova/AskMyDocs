import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import {
    buildAssistantMessage,
    stubChatAssistantReply,
} from './helpers/stub-chat';

/*
 * v4.0/W3.2 — Chat-stream filters round-trip during streaming
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
 * applies.
 *
 * Contract preserved from chat-filters.spec.ts: the filters object
 * threads into the request body via prepareSendMessagesRequest. The
 * only behavioural delta is that the request is now an SSE POST
 * instead of synchronous JSON.
 */

// Per-test timeout bumped from the 20s default — see chat-refusal.spec.ts
// for the rationale (slow seeded fixture under local php -S + SQLite).
test.describe.configure({ timeout: 60_000 });

test.describe('Chat-stream filters round-trip during streaming', () => {
    test('filters object threads into the SSE POST request body', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: with a source-pdf chip + folder glob in
        // the filter bar, send a message; the captured POST body to
        // the streaming endpoint MUST contain
        //   { content, filters: { source_types: ['pdf'],
        //                         folder_globs: ['hr/policies/**'] } }
        // The W3.2 prepareSendMessagesRequest adapter is responsible
        // for threading filters via useChat's body parameter.
        let capturedBody: { content?: string; filters?: Record<string, unknown> } | null = null;

        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 6001,
                content: 'Filtered streaming answer body.',
                metadata: {
                    provider: 'mock',
                    model: 'mock',
                    citations: [],
                    filters_selected: 2,
                    confidence: 80,
                },
                confidence: 80,
            }),
            onPost: (body) => {
                capturedBody = body;
            },
        });

        await page.goto('/app/chat');

        // Build filter chips: source=pdf + folder glob.
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-source').click();
        await page.getByTestId('filter-source-option-pdf').check();
        await page.getByTestId('filter-tab-folder').click();
        const folderInput = page.getByTestId('filter-folder-input');
        await folderInput.fill('hr/policies/**');
        await folderInput.press('Enter');
        await page.getByTestId('filter-popover-close').click();

        const { input, send } = composer(page);
        await input.fill('Find policy doc with filters applied.');
        await send.click();

        // Wait for the streaming POST to land before asserting on the
        // captured body — same pattern as the existing chat-filters
        // POST-shape assertion.
        await waitForThreadReady(page, 30_000);

        expect(capturedBody, 'streaming POST body must be captured by route handler').not.toBeNull();
        expect(capturedBody!.content).toBe('Find policy doc with filters applied.');
        expect(capturedBody!.filters).toEqual(
            expect.objectContaining({
                source_types: ['pdf'],
                folder_globs: ['hr/policies/**'],
            }),
        );
    });

    test('filter chips persist during streaming and clear-all mid-stream works without aborting the stream', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: while data-state="loading", the filter
        // bar still renders the original chips (the mutation does NOT
        // wipe them). Clicking clear-all mid-stream removes the chips
        // but does NOT abort the stream — data-state continues to
        // 'ready' normally.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 6002,
                content: 'Streamed answer with filters; chips stay during stream.',
            }),
        });

        await page.goto('/app/chat');

        // Add a chip.
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-language').click();
        await page.getByTestId('filter-language-option-en').check();
        await page.getByTestId('filter-popover-close').click();
        await expect(page.getByTestId('filter-chip-language-en')).toBeVisible();

        const { input, send } = composer(page);
        await input.fill('Send while filter chip is active.');
        await send.click();

        // Mid-stream — chips still visible.
        await expect(thread(page)).toHaveAttribute('data-state', 'loading');
        await expect(page.getByTestId('filter-chip-language-en')).toBeVisible();

        // Clear-all mid-stream — chip vanishes.
        await page.getByTestId('chat-filter-bar-clear').click();
        await expect(page.getByTestId('filter-chip-language-en')).not.toBeVisible();

        // Stream continues uninterrupted (no error state, lands on
        // ready normally).
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
    });

    test('filter popover opens mid-stream and selecting a project does NOT stop the stream', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: while data-state="loading", clicking the
        // chat-filter-bar-add trigger opens the popover normally
        // (aria-expanded transitions). Selecting a project filter
        // adds the chip; the stream continues uninterrupted to
        // 'ready' (no error, no early termination).
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 6003,
                content: 'A streamed answer that lasts long enough to interact with the popover.',
            }),
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Send and interact with the filter popover mid-stream.');
        await send.click();

        // Mid-stream popover open.
        await expect(thread(page)).toHaveAttribute('data-state', 'loading');
        const trigger = page.getByTestId('chat-filter-bar-add');
        await trigger.click();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');
        const popover = page.getByTestId('filter-popover');
        await expect(popover).toBeVisible();

        // Tab to project, select first option (project-tab schema —
        // project-option-{id} where id comes from the seeded
        // DemoSeeder; the W3.2 swap commit author should pick a
        // stable project key here once the FE wiring is in place).
        await page.getByTestId('filter-tab-project').click();
        await page.getByTestId('filter-project-option-hr-portal').check();
        await page.getByTestId('filter-popover-close').click();

        // Stream still runs to completion.
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
    });
});
