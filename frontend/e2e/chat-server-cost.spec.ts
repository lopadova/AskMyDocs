import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import { buildAssistantMessage, buildUserMessage, stubChatAssistantReply } from './helpers/stub-chat';

/*
 * v8.18/W1.1 (W3.3.C) — the chat cost meter renders the SERVER-resolved
 * per-turn cost (`metadata.cost` / `metadata.cost_currency`, shipped v8.16/W3),
 * NOT the client-side estimate.
 *
 * R13: the ONLY thing stubbed is the external AI boundary (POST
 * /conversations/{id}/messages[/stream]); the rest is the real app. The assistant
 * message in the GET refetch (`list`) carries the server cost, exactly as a real
 * persisted turn does once `ChatTurnCostResolver` has written `chat_logs.cost`.
 * The unit logic (server-cost preference, whitespace guard, currency formatting)
 * is already covered by `TokenCostMeter.test.tsx`; this is additive E2E proof
 * that the value reaches the rendered meter end-to-end.
 */

test.describe('Chat — server cost meter', () => {
    test.describe.configure({ timeout: 60_000 });

    async function sendAndSettle(
        page: import('@playwright/test').Page,
        prompt: string,
    ): Promise<void> {
        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill(prompt);
        await send.click();
        await waitForThreadReady(page, 45_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
        await expect(
            page.locator('[data-testid^="chat-message-"][data-role="assistant"]').first(),
        ).toBeVisible({ timeout: 30_000 });
    }

    test('renders the server USD cost from message metadata', async ({ page }) => {
        const question = 'What does the remote work policy cover?';
        // Helpers own the default shape (role/rating/created_at + base metadata);
        // the spec only overrides the fields it asserts on — the SERVER cost.
        const assistant = buildAssistantMessage({
            id: 2101,
            content: 'The remote work policy covers home-office stipends and equipment.',
            metadata: {
                provider: 'mock',
                model: 'mock',
                prompt_tokens: 812,
                completion_tokens: 143,
                total_tokens: 955,
                cost: '1.23000000',
                cost_currency: 'USD',
                citations: [],
            },
        });
        const user = buildUserMessage({ id: 2100, content: question });

        await stubChatAssistantReply(page, { assistant, list: [user, assistant] });
        await sendAndSettle(page, question);

        // The pill is marked cost-available and the amount is the SERVER value,
        // formatted by formatCost (USD >= 1 → 2 decimals → "$1.23").
        const pill = page.getByTestId('chat-token-cost');
        await expect(pill).toHaveAttribute('data-cost-available', 'true', { timeout: 30_000 });
        await expect(page.getByTestId('chat-token-cost-amount')).toContainText('$1.23');
    });

    test('renders a non-USD server cost with the trailing ISO code', async ({ page }) => {
        const question = 'How do I request equipment?';
        const assistant = buildAssistantMessage({
            id: 2103,
            content: 'Equipment requests go through the IT portal.',
            metadata: {
                provider: 'mock',
                model: 'mock',
                prompt_tokens: 400,
                completion_tokens: 60,
                total_tokens: 460,
                cost: '2.50000000',
                cost_currency: 'EUR',
                citations: [],
            },
        });
        const user = buildUserMessage({ id: 2102, content: question });

        await stubChatAssistantReply(page, { assistant, list: [user, assistant] });
        await sendAndSettle(page, question);

        // Non-USD → the FULL formatted value with the trailing ISO code
        // ("2.50 EUR": ≥1 → 2 decimals + " EUR"), proving the SERVER currency +
        // amount (not a hard-coded "$") drive the meter.
        await expect(page.getByTestId('chat-token-cost')).toHaveAttribute(
            'data-cost-available',
            'true',
            { timeout: 30_000 },
        );
        await expect(page.getByTestId('chat-token-cost-amount')).toContainText('2.50 EUR');
    });
});
