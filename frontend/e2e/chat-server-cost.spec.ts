import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import { stubChatAssistantReply } from './helpers/stub-chat';

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

    async function sendAndSettle(page: import('@playwright/test').Page): Promise<void> {
        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('What does the remote work policy cover?');
        await send.click();
        await waitForThreadReady(page, 45_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
        await expect(
            page.locator('[data-testid^="chat-message-"][data-role="assistant"]').first(),
        ).toBeVisible({ timeout: 30_000 });
    }

    test('renders the server USD cost from message metadata', async ({ page }) => {
        const assistant = {
            id: 2101,
            role: 'assistant' as const,
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
            rating: null,
            created_at: new Date().toISOString(),
        };
        const user = {
            id: 2100,
            role: 'user' as const,
            content: 'What does the remote work policy cover?',
            metadata: null,
            rating: null,
            created_at: new Date().toISOString(),
        };

        await stubChatAssistantReply(page, { assistant, list: [user, assistant] });
        await sendAndSettle(page);

        // The pill is marked cost-available and the amount is the SERVER value,
        // formatted by formatCost (USD >= 1 → 2 decimals → "$1.23").
        const pill = page.getByTestId('chat-token-cost');
        await expect(pill).toHaveAttribute('data-cost-available', 'true', { timeout: 30_000 });
        await expect(page.getByTestId('chat-token-cost-amount')).toContainText('$1.23');
    });

    test('renders a non-USD server cost with the trailing ISO code', async ({ page }) => {
        const assistant = {
            id: 2103,
            role: 'assistant' as const,
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
            rating: null,
            created_at: new Date().toISOString(),
        };
        const user = {
            id: 2102,
            role: 'user' as const,
            content: 'How do I request equipment?',
            metadata: null,
            rating: null,
            created_at: new Date().toISOString(),
        };

        await stubChatAssistantReply(page, { assistant, list: [user, assistant] });
        await sendAndSettle(page);

        // Non-USD → trailing ISO code ("2.50 EUR"), proving the server currency
        // (not a hard-coded $) drives the meter.
        await expect(page.getByTestId('chat-token-cost')).toHaveAttribute(
            'data-cost-available',
            'true',
            { timeout: 30_000 },
        );
        await expect(page.getByTestId('chat-token-cost-amount')).toContainText('EUR');
    });
});
