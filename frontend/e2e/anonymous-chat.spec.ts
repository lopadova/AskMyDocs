import { test, expect } from './fixtures';

/*
 * v8.8.3 — anonymous (authenticated, NON-persisted) chat E2E.
 *
 * R13: runs against the REAL Laravel app + real DB (DemoSeeder via the
 * `seeded` auto-fixture) + real `/api/kb/chat`. No route interception — the
 * AI provider boundary is stubbed at the SERVER level via `AI_PROVIDER=fake`
 * (playwright.config.ts webServer.env), which streams a canned answer + a
 * constant embedding so retrieval always returns the seeded chunk. The
 * feature flag `KB_ANONYMOUS_CHAT_ENABLED=true` is also set there, so the
 * capability probe resolves enabled. The OFF / 422-reject state is covered
 * by KbChatAnonymousTest (phpunit) + the AnonymousChatView Vitest disabled
 * landing, per R43.
 *
 * Scenarios:
 *   1. Happy path — "New anonymous chat" navigates to the dedicated view,
 *      shows the banner, sends a question, the turn resolves (answer or a
 *      grounded refusal — both prove the real round-trip).
 *   2. Not-saved contract — the thread is in-memory only: a reload clears
 *      it back to the empty state, proving nothing was persisted.
 */

test.describe('Anonymous chat', () => {
    test.describe.configure({ timeout: 60_000 });

    test('starts an anonymous session and the turn resolves end-to-end', async ({ page }) => {
        await page.goto('/app/chat');

        await page.getByTestId('chat-new-anonymous-chat').click();

        await expect(page).toHaveURL(/\/chat\/anonymous$/);
        const view = page.getByTestId('anonymous-chat-view');
        await expect(view).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('anonymous-chat-banner')).toBeVisible();
        await expect(page.getByTestId('anonymous-chat-empty')).toBeVisible();

        await page.getByTestId('anonymous-chat-input').fill('How does the remote work policy apply?');
        await page.getByTestId('anonymous-chat-send').click();

        // The first turn must render the question immediately…
        const turn = page.getByTestId('anonymous-chat-turn-0');
        await expect(turn).toBeVisible();
        await expect(turn.getByTestId('anonymous-chat-turn-0-question')).toContainText(
            'How does the remote work policy apply?',
        );

        // …and then resolve to either a grounded answer or a refusal notice.
        const answer = page.getByTestId('anonymous-chat-turn-0-answer');
        const refusal = page.getByTestId('refusal-notice');
        await expect(answer.or(refusal)).toBeVisible({ timeout: 30_000 });
        // The pending spinner is gone once resolved.
        await expect(turn).toHaveAttribute('data-pending', 'false');
    });

    test('the anonymous thread is not saved — a reload clears it', async ({ page }) => {
        await page.goto('/app/chat/anonymous');
        await expect(page.getByTestId('anonymous-chat-view')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByTestId('anonymous-chat-input').fill('Anything at all?');
        await page.getByTestId('anonymous-chat-send').click();
        await expect(page.getByTestId('anonymous-chat-turn-0')).toBeVisible();

        // Reload — an anonymous turn lives only in component memory, so the
        // thread must come back empty (nothing was persisted server-side).
        await page.reload();
        await expect(page.getByTestId('anonymous-chat-view')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('anonymous-chat-empty')).toBeVisible();
        await expect(page.getByTestId('anonymous-chat-turn-0')).toHaveCount(0);
    });
});
