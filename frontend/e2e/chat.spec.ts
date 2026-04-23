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
        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('How does the remote work stipend apply?');
        await send.click();
        await waitForThreadReady(page, 45_000);
        await expect(thread(page)).toHaveAttribute('data-state', 'ready');
        // At least one assistant message is present after send.
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
        // Seed a message that the assistant would cite. Go direct to a
        // fresh conversation, inject a preformatted assistant turn via
        // the feedback-free path by mocking the network: simpler is to
        // render the Markdown through our own route mocking and assert
        // the WikilinkHover component hooks up to /api/kb/resolve-wikilink.
        await page.route('**/api/kb/resolve-wikilink**', async (route) => {
            const url = new URL(route.request().url());
            if (url.searchParams.get('slug') === 'remote-work-policy') {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        document_id: 1,
                        title: 'Remote Work Policy',
                        source_path: 'policies/remote-work-policy.md',
                        canonical_type: 'policy',
                        canonical_status: 'accepted',
                        is_canonical: true,
                        preview: 'ACME employees may work remotely up to 3 days per week.',
                    }),
                });
                return;
            }
            await route.fallback();
        });

        // Install a stub that replaces /conversations/*/messages POST so
        // the assistant reply renders a [[remote-work-policy]] link
        // synchronously. The DemoSeeder content is already present in
        // the DB, and the default controller goes through the real AI
        // manager — which we do not want to hit.
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
        await wikilink.hover();
        const preview = page.getByTestId('wikilink-preview');
        await expect(preview).toBeVisible();
        await expect(preview).toContainText(/Remote Work Policy/i);
    });

    test('wikilink resolver 500 degrades gracefully', async ({ page }) => {
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
        await wikilink.hover();
        // The preview tooltip appears even on 500, but the error surface is the
        // testid-tagged element so Playwright can assert graceful degradation.
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
