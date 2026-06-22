import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, waitForThreadReady } from './helpers';
import { buildAssistantMessage, stubChatAssistantReply } from './helpers/stub-chat';

/*
 * R12 coverage for the chat conversation project selector (PR #343).
 *
 * What this spec proves (Copilot review ask on ChatView.tsx):
 *   1. Happy path — switching the project scope while inside an existing
 *      conversation starts a FRESH conversation. A conversation binds to
 *      ONE project at creation (`conversations.project_key`) and the BE
 *      scopes every turn to it, so a scope switch can only mean "start a
 *      new chat" — the URL drops the `/{conversationId}` segment and the
 *      header flips back to "New chat".
 *   2. Constrained path — the "All projects" scope is NOT unscoped
 *      retrieval. A project-less conversation must carry
 *      `filters.project_keys = <my reachable projects>` so retrieval can
 *      never reach beyond the user's memberships (a cross-membership
 *      leak). We capture the streamed turn's POST body and assert the
 *      reachable list was injected.
 *
 * R13: only the AI provider call site is stubbed
 * (`/conversations/*​/messages/stream` — EXTERNAL_PROXY allowlist). The
 * conversation-create POST hits the REAL backend + SQLite, so the bound
 * `project_key` and membership-derived selector options come from real
 * data. The admin DemoSeeder user has memberships in `engineering` +
 * `hr-portal` (see DemoSeeder::seedProjectMemberships), which the
 * selector lists alphabetically.
 */

// Slow seeded fixture under local php -S + SQLite (fast under CI Postgres);
// the conversation-create round-trip + stubbed stream needs headroom.
test.describe.configure({ timeout: 60_000 });

test.describe('Chat project selector', () => {
    test('switching the project scope inside a conversation starts a new chat', async ({ page }) => {
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 4101,
                content: 'Scoped answer body.',
            }),
        });

        await page.goto('/app/chat');

        const selector = page.getByTestId('chat-project-selector');
        await expect(selector).toBeVisible({ timeout: 15_000 });
        // The reachable memberships are offered as real options (R18).
        await expect(page.getByRole('option', { name: 'engineering' })).toBeAttached();
        await expect(page.getByRole('option', { name: 'hr-portal' })).toBeAttached();

        // Bind a brand-new conversation to `engineering` by selecting it
        // before the first turn, then send so requireConversation() creates
        // the conversation scoped to it.
        await selector.selectOption('engineering');
        const { input, send } = composer(page);
        await input.fill('What is the on-call rotation?');
        await send.click();

        await page.waitForResponse(
            (r) => r.url().includes('/messages/stream') && r.request().method() === 'POST',
        );
        await waitForThreadReady(page);

        // The conversation now exists — the URL carries its id.
        await expect.poll(() => page.url(), { timeout: 15_000 }).toMatch(/\/chat\/\d+/);

        // Switch to a DIFFERENT project. Because the active conversation is
        // bound to `engineering`, this must reset to a fresh chat.
        await selector.selectOption('hr-portal');

        await expect.poll(() => page.url(), { timeout: 15_000 }).not.toMatch(/\/chat\/\d+/);
        // Scope to the header — "New chat" also appears on the sidebar's
        // new-conversation affordance, so an unscoped getByText is ambiguous.
        await expect(page.getByTestId('chat-header').getByText('New chat')).toBeVisible();
        // The selector now reflects the freshly chosen scope for the new chat.
        await expect(selector).toHaveValue('hr-portal');
    });

    test('the "All projects" scope constrains retrieval to the reachable projects', async ({ page }) => {
        let capturedBody: { content?: string; filters?: Record<string, unknown> } | null = null;

        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 4102,
                content: 'Cross-project answer body.',
            }),
            onPost: (body) => {
                capturedBody = body;
            },
        });

        await page.goto('/app/chat');

        const selector = page.getByTestId('chat-project-selector');
        await expect(selector).toBeVisible({ timeout: 15_000 });

        // Pick "All projects" (the empty-string sentinel) — a project-less
        // conversation that MUST be constrained to the user's memberships.
        await selector.selectOption({ label: 'All projects' });
        await expect(selector).toHaveValue('');

        const { input, send } = composer(page);
        await input.fill('Summarise everything I can reach.');
        await send.click();

        await page.waitForResponse(
            (r) => r.url().includes('/messages/stream') && r.request().method() === 'POST',
        );

        expect(capturedBody, 'stream POST body must be captured').not.toBeNull();
        expect(capturedBody!.content).toBe('Summarise everything I can reach.');

        // The core assertion: "All projects" is NOT unscoped. The reachable
        // membership list is injected so retrieval can never exceed it.
        const projectKeys = (capturedBody!.filters?.project_keys ?? null) as string[] | null;
        expect(Array.isArray(projectKeys), 'project_keys must be injected, not unscoped').toBe(true);
        expect(projectKeys).toEqual(expect.arrayContaining(['engineering', 'hr-portal']));
        expect(projectKeys).toHaveLength(2);
    });
});
