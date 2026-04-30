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
    test('user asks question and the assistant reply renders', async ({ page }, testInfo) => {
        // Per-test budget bumped to 60 s. The default 20 s per-test
        // timeout in `playwright.config.ts` is too tight for this
        // specific scenario: the auto-fixture (resetDb + seedDb +
        // login) burns ~10 s on a cold CI runner, page.goto('/app/chat')
        // adds ~3-5 s while the SPA boots, and the inner assertions
        // (waitForThreadReady 45_000, toBeVisible 30_000) advertise
        // budgets that exceed the outer cap. The scenario stubs the
        // AI provider so the wall-clock interaction is sub-second,
        // but the cumulative setup variance pushed the OUTER cap to
        // fire in 2 of the last 4 CI runs (PR #83 commits bb04800
        // and 972b761). 60 s gives 3× headroom over worst-case
        // observed without affecting the assertion semantics.
        testInfo.setTimeout(60_000);

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
        // DemoSeeder already seeds `remote-work-policy`. We stub the
        // AI provider boundary on POST and ALSO the GET — useChatMutation
        // calls invalidateQueries(['messages',...]) on success which
        // triggers a refetch; without stubbing the GET the refetch hits
        // the real backend, which has no record of the mocked POST,
        // and the assistant message (with the wikilink) disappears
        // before hover assertions can land.
        const assistantMessage = {
            id: 999,
            role: 'assistant',
            content: 'See [[remote-work-policy]] for the details.',
            metadata: { provider: 'mock', model: 'mock', citations: [] },
            rating: null,
            created_at: new Date().toISOString(),
        };
        const userMessage = {
            id: 998,
            role: 'user',
            content: 'Show me the remote work policy',
            metadata: null,
            rating: null,
            created_at: new Date().toISOString(),
        };
        await page.route('**/conversations/*/messages', async (route) => {
            const method = route.request().method();
            if (method === 'POST') {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify(assistantMessage),
                });
            } else if (method === 'GET') {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify([userMessage, assistantMessage]),
                });
            } else {
                await route.fallback();
            }
        });

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('Show me the remote work policy');
        await send.click();

        const wikilink = page.getByTestId('wikilink-remote-work-policy').first();
        await expect(wikilink).toBeVisible({ timeout: 30_000 });
        // Dispatch mouseenter via evaluate — Playwright's `hover()`
        // moves a virtual mouse cursor onto a DOM node, but React's
        // re-renders (TanStack Query, animation passes) replace that
        // node so the cursor sits on a detached element. dispatchEvent
        // on the LIVE node lets React's root-attached event delegation
        // catch the bubbled event regardless of mouse position.
        const respPromise = page.waitForResponse(
            (resp) => resp.url().includes('/api/kb/resolve-wikilink'),
            { timeout: 15_000 },
        );
        await wikilink.evaluate((el) => {
            el.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
            el.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
        });
        const resp = await respPromise;
        if (!resp.ok()) {
            throw new Error(
                `GET /api/kb/resolve-wikilink returned non-OK: ${resp.status()} ${await resp.text()}`,
            );
        }
        // After the response lands React rerenders one more time. The
        // popover (gated on `hover === true`) needs another mouseenter
        // on the (potentially new) current node. Re-dispatch.
        await page.locator('[data-testid="wikilink-remote-work-policy"]').first().evaluate((el) => {
            el.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
            el.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
        });
        const preview = page.getByTestId('wikilink-preview');
        await expect(preview).toBeVisible({ timeout: 5_000 });
        await expect(preview).toContainText(/Remote Work Policy/i);
    });

    test('wikilink resolver 500 degrades gracefully', async ({ page }) => {
        /* R13: failure injection — real path tested in "wikilink hover fetches and shows the preview card". */
        await page.route('**/api/kb/resolve-wikilink**', (route) => route.fulfill({ status: 500 }));
        // Stub both POST and GET — see the happy-path test for why the
        // GET stub matters (invalidateQueries refetch would otherwise
        // wipe the wikilink message).
        const assistantMessage = {
            id: 998,
            role: 'assistant',
            content: 'See [[remote-work-policy]].',
            metadata: { provider: 'mock', model: 'mock', citations: [] },
            rating: null,
            created_at: new Date().toISOString(),
        };
        const userMessage = {
            id: 997,
            role: 'user',
            content: 'Show remote policy please',
            metadata: null,
            rating: null,
            created_at: new Date().toISOString(),
        };
        await page.route('**/conversations/*/messages', async (route) => {
            const method = route.request().method();
            if (method === 'POST') {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify(assistantMessage),
                });
            } else if (method === 'GET') {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify([userMessage, assistantMessage]),
                });
            } else {
                await route.fallback();
            }
        });

        await page.goto('/app/chat');
        await composer(page).input.fill('Show remote policy please');
        await composer(page).send.click();
        const wikilink = page.getByTestId('wikilink-remote-work-policy').first();
        await expect(wikilink).toBeVisible({ timeout: 30_000 });
        // Same strategy as the happy-path test: dispatchEvent bypasses
        // Playwright's mouse cursor tracking which loses the React node
        // across re-renders. The route stub returns 500 so the response
        // arrives immediately; wait for it then re-dispatch.
        const respPromise = page.waitForResponse(
            (resp) => resp.url().includes('/api/kb/resolve-wikilink'),
            { timeout: 15_000 },
        );
        await wikilink.evaluate((el) => {
            el.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
            el.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
        });
        await respPromise;
        await page.locator('[data-testid="wikilink-remote-work-policy"]').first().evaluate((el) => {
            el.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
            el.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
        });
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
