import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import {
    buildAssistantMessage,
    stubChatAssistantReply,
} from './helpers/stub-chat';

/*
 * v4.0/W3.2 — Chat-stream saved-filter-presets-during-streaming
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
 * applies. /api/chat-filter-presets is INTERNAL (per-user CRUD) and
 * exercised against real seeded data — never stubbed.
 *
 * Presets contract preserved from chat-mention.spec.ts: presets are
 * persisted via the real /api/chat-filter-presets endpoint; loading
 * a preset replaces the live filter state; saving creates a new row.
 * The W3.2 streaming-context delta is that these UI flows must still
 * work while a chat turn is streaming.
 */

// Per-test timeout bumped from the 20s default — see chat-refusal.spec.ts
// for the rationale (slow seeded fixture under local php -S + SQLite).
test.describe.configure({ timeout: 60_000 });

test.describe('Chat-stream saved filter presets during streaming', () => {
    test('loading a preset mid-stream replaces live filter state without aborting the stream', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: seed a preset by saving the current
        // filters; clear the bar; start a streaming turn; while
        // data-state="loading", open the presets dropdown and click
        // load on the saved preset. The chip(s) reappear, the
        // streaming turn continues uninterrupted to ready.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 4001,
                content: 'A streamed answer long enough to overlap with preset interaction.',
            }),
        });

        await page.goto('/app/chat');

        // Step 1 — create a preset from a single language=en chip.
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-language').click();
        await page.getByTestId('filter-language-option-en').check();
        await page.getByTestId('filter-popover-close').click();
        await page.getByTestId('chat-filter-presets-trigger').click();
        await page.getByTestId('chat-filter-presets-save').click();
        await page.getByTestId('chat-filter-presets-name-input').fill('English only');
        const createResp = page.waitForResponse(
            (r) => r.url().endsWith('/api/chat-filter-presets') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('chat-filter-presets-save-confirm').click();
        await createResp;

        // Step 2 — clear filter bar so the load action makes a
        // visible delta when triggered.
        await page.getByTestId('chat-filter-bar-clear').click();
        await expect(page.getByTestId('filter-chip-language-en')).not.toBeVisible();

        // Step 3 — start a streaming turn.
        const { input, send } = composer(page);
        await input.fill('Send a message and load a preset mid-stream.');
        await send.click();
        await expect(thread(page)).toHaveAttribute('data-state', 'loading');

        // Step 4 — while streaming, load the preset.
        await page.getByTestId('chat-filter-presets-trigger').click();
        const presetRow = page.locator('[data-preset-name="English only"]');
        await expect(presetRow).toBeVisible({ timeout: 10_000 });
        await presetRow.locator('[data-testid$="-load"]').click();
        await expect(page.getByTestId('filter-chip-language-en')).toBeVisible({ timeout: 10_000 });

        // Step 5 — stream completes uninterrupted.
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
    });

    test('save preset from current filters mid-stream — POST /api/chat-filter-presets fires while data-state="loading"', async ({ page }) => {
        test.skip(true, 'Awaits W3.2 SSE helper switch — see PLAN §7.7');

        // Assertion contract: with active filter chips, start a
        // streaming turn; while data-state="loading", open the
        // presets dropdown and click save. The real
        // POST /api/chat-filter-presets must fire and succeed; the
        // streaming turn continues uninterrupted to ready.
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 4002,
                content: 'Streamed answer that overlaps with preset save.',
            }),
        });

        await page.goto('/app/chat');

        // Add a filter so Save is enabled.
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-source').click();
        await page.getByTestId('filter-source-option-pdf').check();
        await page.getByTestId('filter-popover-close').click();

        // Start streaming turn.
        const { input, send } = composer(page);
        await input.fill('Save my filter preset while streaming.');
        await send.click();
        await expect(thread(page)).toHaveAttribute('data-state', 'loading');

        // Save preset mid-stream — assert the real POST fires.
        await page.getByTestId('chat-filter-presets-trigger').click();
        const save = page.getByTestId('chat-filter-presets-save');
        await expect(save).not.toBeDisabled();
        await save.click();
        await page.getByTestId('chat-filter-presets-name-input').fill('PDF mid-stream');
        const postPromise = page.waitForResponse(
            (r) => r.url().endsWith('/api/chat-filter-presets') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('chat-filter-presets-save-confirm').click();
        const postResp = await postPromise;
        if (!postResp.ok()) {
            throw new Error(
                `POST /api/chat-filter-presets returned non-OK during stream: ${postResp.status()} ${await postResp.text()}`,
            );
        }

        // Streaming turn still completes successfully.
        await waitForThreadReady(page, 30_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
    });
});
