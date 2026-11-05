import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import { buildAssistantMessage, stubChatAssistantReply } from './helpers/stub-chat';

/*
 * v8.x — the anti-duplication proof.
 *
 * The Sessions workspace is a new SHELL over the existing chat engine:
 * useChatSession, useAgentChat, the deferred-send queue, Composer,
 * MessageThread, MessageBubble are all shared with /chat. This scenario
 * exists to prove that claim end-to-end — a turn sent from the new panel
 * must behave exactly like one sent from /chat, including creating the
 * conversation on the first message and moving the URL to
 * /sessions/{id}.
 *
 * If this spec ever needs its own transport stub, or diverges from
 * chat.spec.ts's expectations, the two surfaces have forked and the
 * whole point of the shared hook has been lost.
 *
 * The AI provider is the ONLY thing stubbed (R13): it is the external
 * boundary, and the same helper serves every chat*.spec.ts.
 */

test.describe('Sessions workspace — a real turn on the shared chat engine', () => {
    test.describe.configure({ timeout: 60_000 });

    test('sending the first message creates the session and renders the reply', async ({ page }) => {
        await stubChatAssistantReply(page, {
            assistant: buildAssistantMessage({
                id: 2001,
                content: 'The remote work stipend applies after 90 days.',
            }),
        });

        await page.goto('/app/sessions');
        await expect(page.getByTestId('chat-sessions-view')).toBeVisible({ timeout: 20_000 });
        // A brand-new session: no conversation id in the URL yet.
        await expect(page.getByTestId('chat-sessions-header')).toContainText('New session');

        // R22 §4: a non-2xx on the conversation create used to surface here
        // as a generic "thread stayed idle" timeout. Failing on the real
        // status instead keeps the next diagnosis one line long.
        const createResponse = page.waitForResponse(
            (r) => r.url().endsWith('/conversations') && r.request().method() === 'POST',
            { timeout: 20_000 },
        );

        const { input, send } = composer(page);
        await input.fill('How does the remote work stipend apply?');
        await send.click();

        const created = await createResponse;
        if (!created.ok()) {
            throw new Error(
                `POST /conversations returned ${created.status()}: ${await created.text()}`,
            );
        }

        await waitForThreadReady(page, 45_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');

        // The deferred-send queue had to wait for the created conversation
        // id to reach the URL before dispatching — if it did not, the
        // message would have landed in an orphaned state and no assistant
        // bubble would appear.
        await expect(page).toHaveURL(/\/sessions\/\d+$/, { timeout: 15_000 });

        const assistant = page
            .locator('[data-testid^="chat-message-"][data-role="assistant"]')
            .first();
        await expect(assistant).toBeVisible({ timeout: 30_000 });
    });

    test('opening a seeded session from the rail loads its thread', async ({ page }) => {
        await page.goto('/app/sessions');
        await expect(page.getByTestId('chat-sessions-sidebar')).toHaveAttribute(
            'data-state',
            'ready',
            { timeout: 20_000 },
        );

        const row = page
            .getByTestId('chat-sessions-group-unfiled')
            .locator('[data-testid^="chat-sessions-row-"]')
            .first();
        const rowId = (await row.getAttribute('data-testid'))?.replace('chat-sessions-row-', '');

        await page.getByTestId(`chat-sessions-row-${rowId}-open`).click();

        await expect(page).toHaveURL(new RegExp(`/sessions/${rowId}$`), { timeout: 15_000 });
        await expect(page.getByTestId(`chat-sessions-row-${rowId}`)).toHaveAttribute(
            'data-active',
            'true',
        );
        // The thread reaches a terminal state rather than spinning.
        await waitForThreadReady(page, 30_000);
    });

    test('failure path — an empty message is refused before any request', async ({ page }) => {
        await page.goto('/app/sessions');
        await expect(page.getByTestId('chat-sessions-view')).toBeVisible({ timeout: 20_000 });

        await composer(page).send.click();

        // Same validation surface as /chat: the shared Composer owns it.
        const err = page.getByTestId('message-error');
        await expect(err).toBeVisible();
        await expect(err).toContainText(/required/i);
    });
});
