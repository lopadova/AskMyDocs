import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, newConversationButton, thread, waitForThreadReady } from './helpers';
import {
    buildAssistantMessage,
    stubChatAssistantReply,
    stubWikilinkResolveError,
} from './helpers/stub-chat';

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
    // The "user asks question..." scenario lives in a nested describe
    // so we can bump its per-test budget to 60 s without affecting the
    // 4 fast scenarios in this file. Playwright's per-test `timeout`
    // option is NOT exposed on the `TestDetails` object passed as the
    // second arg to `test()` — only `tag` / `annotation` are. The
    // documented surfaces that DO override the per-test timeout are
    // `test.setTimeout()` (only covers the body, not fixtures) and
    // `test.describe.configure({ timeout })` (covers fixtures + body
    // + after-each for every test in the describe). Since the
    // 60-second budget is needed to cover the `seeded` auto-fixture
    // on cold CI runners (where resetDb + seedDb + login already
    // consumes ~10 s and the SPA boot adds another 3–5 s before
    // page.goto('/app/chat') even returns), `describe.configure` is
    // the correct knob.
    //
    // Earlier iterations of this fix tried `testInfo.setTimeout()`
    // inside the body (commit 2c4a640) and `{ timeout: 60_000 }` in
    // the test-options object (commit 9b0eb3e); both were silently
    // ineffective — TypeScript accepted the options object via
    // structural typing, but Playwright ignored the unknown property
    // and the test kept firing the global 20 s cap. The flake
    // surfaced again on 9b0eb3e CI, confirming the documented
    // describe-scoped pattern below is the only working knob.
    test.describe('AI-stubbed chat round-trip', () => {
        test.describe.configure({ timeout: 60_000 });

        test('user asks question and the assistant reply renders', async ({ page }) => {
            // Copilot #12 fix: stub the assistant reply instead of
            // calling the real AI provider. Hitting OpenRouter in CI
            // is flaky (missing API credentials) and makes the
            // Playwright gate non-deterministic. The goal of this
            // scenario is to verify the UI round-trip — composer →
            // message render — not the provider integration, which
            // is covered by PHPUnit feature tests on
            // MessageController.
            await stubChatAssistantReply(page, {
                assistant: buildAssistantMessage({
                    id: 1001,
                    content: 'The remote work stipend applies to full-time employees after 90 days.',
                }),
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
        await stubChatAssistantReply(page, {
            assistant: assistantMessage,
            list: [userMessage, assistantMessage],
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
        await stubWikilinkResolveError(page, 500);
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
        await stubChatAssistantReply(page, {
            assistant: assistantMessage,
            list: [userMessage, assistantMessage],
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
