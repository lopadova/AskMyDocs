import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, newConversationButton, thread, waitForThreadReady } from './helpers';

/*
 * Chat E2E scenarios. Every test runs against the authed storage state
 * produced by auth.setup.ts (see playwright.config.ts), and the
 * `seeded` auto-fixture resets + re-seeds the DemoSeeder between tests.
 *
 * Scenarios cover:
 *   1. Happy path — user sends a message, assistant reply + citations
 *      render, thread reaches data-state=ready.
 *   2. 422 validation — empty message surfaces data-testid=message-error.
 *   3. Wikilink hover — resolving a seeded slug shows the preview card.
 *   4. Wikilink 500 fallback — mocked network error degrades to plain
 *      [[slug]] text (no crash, no popover error shown to the user
 *      outside the hover preview).
 *   5. New conversation → empty state surfaces the suggested prompts.
 */

test.describe('Chat', () => {
    test('user asks question and the assistant reply renders', async ({ page }) => {
        // Copilot #12 fix: stub the assistant reply instead of calling
        // the real AI provider. Hitting OpenRouter in CI is flaky
        // (missing API credentials) and makes the Playwright gate
        // non-deterministic. The goal of this scenario is to verify
        // the UI round-trip — composer → message render — not the
        // provider integration, which is covered by PHPUnit feature
        // tests on MessageController.
        await page.route('**/conversations/*/messages', async (route) => {
            if (route.request().method() !== 'POST') {
                await route.fallback();
                return;
            }
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    id: 1001,
                    role: 'assistant',
                    content: 'The remote work stipend applies to full-time employees after 90 days.',
                    metadata: { provider: 'mock', model: 'mock', citations: [] },
                    rating: null,
                    created_at: new Date().toISOString(),
                }),
            });
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('How does the remote work stipend apply?');
        await send.click();
        await waitForThreadReady(page, 45_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
        const firstAssistant = page.locator('[data-testid^="chat-message-"][data-role="assistant"]').first();
        await expect(firstAssistant).toBeVisible({ timeout: 30_000 });
    });

    test('empty message surfaces a 422-style validation error', async ({ page }) => {
        await page.goto('/app/chat');
        await composer(page).send.click();
        const err = page.getByTestId('message-error');
        await expect(err).toBeVisible();
        await expect(err).toContainText(/required/i);
    });

    test('wikilink hover fetches and shows the preview card', async ({ page }) => {
        // R13: the wikilink resolver talks only to the local DB and
        // DemoSeeder already seeds `remote-work-policy`. We stub ONLY
        // the AI provider boundary (POST /conversations/*/messages
        // invokes OpenRouter in the controller) and let the real
        // resolver endpoint run against real data.
        await page.route('**/conversations/*/messages', async (route) => {
            if (route.request().method() !== 'POST') {
                await route.fallback();
                return;
            }
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    id: 999,
                    role: 'assistant',
                    content: 'See [[remote-work-policy]] for the details.',
                    metadata: { provider: 'mock', model: 'mock', citations: [] },
                    rating: null,
                    created_at: new Date().toISOString(),
                }),
            });
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Show me the remote work policy');
        await send.click();

        const wikilink = page.getByTestId('wikilink-remote-work-policy').first();
        await expect(wikilink).toBeVisible({ timeout: 30_000 });
        // Strategy: wait for `data-state=ready` on the wikilink wrapper
        // — this is a STABLE signal that survives React re-renders,
        // unlike the volatile `hover` state which can flip back to
        // false when a re-render swaps the DOM node out from under
        // Playwright's mouse position.
        // First hover triggers `enabled: hover` → fetch → data → ready.
        // After ready, we re-hover deliberately so the popover (which
        // is gated on `hover === true`) is mounted, then assert.
        await wikilink.hover({ force: true });
        await expect(wikilink).toHaveAttribute('data-state', 'ready', {
            timeout: 10_000,
        });
        await wikilink.hover({ force: true });
        const preview = page.getByTestId('wikilink-preview');
        await expect(preview).toBeVisible({ timeout: 5_000 });
        await expect(preview).toContainText(/Remote Work Policy/i);
    });

    test('wikilink resolver 500 degrades gracefully', async ({ page }) => {
        /* R13: failure injection — real path tested in "wikilink hover fetches and shows the preview card". */
        await page.route('**/api/kb/resolve-wikilink**', (route) => route.fulfill({ status: 500 }));
        await page.route('**/conversations/*/messages', async (route) => {
            if (route.request().method() !== 'POST') {
                await route.fallback();
                return;
            }
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    id: 998,
                    role: 'assistant',
                    content: 'See [[remote-work-policy]].',
                    metadata: { provider: 'mock', model: 'mock', citations: [] },
                    rating: null,
                    created_at: new Date().toISOString(),
                }),
            });
        });

        await page.goto('/app/chat');
        await composer(page).input.fill('Show remote policy please');
        await composer(page).send.click();
        const wikilink = page.getByTestId('wikilink-remote-work-policy').first();
        await expect(wikilink).toBeVisible({ timeout: 30_000 });
        // Same strategy as the happy-path test: wait for `data-state=error`
        // (set when the query rejects on 500) as a stable signal, then
        // re-hover to mount the popover, then assert the error surface.
        await wikilink.hover({ force: true });
        await expect(wikilink).toHaveAttribute('data-state', 'error', {
            timeout: 10_000,
        });
        await wikilink.hover({ force: true });
        await expect(page.getByTestId('wikilink-preview-error')).toBeVisible({ timeout: 5_000 });
    });

    test('new conversation shows the empty-state suggested prompts', async ({ page }) => {
        await page.goto('/app/chat');
        await newConversationButton(page).click();
        const t = thread(page);
        await expect(t).toHaveAttribute('data-state', 'empty', { timeout: 15_000 });
        await expect(page.getByTestId('chat-suggested-prompt-0')).toBeVisible();
    });
});
