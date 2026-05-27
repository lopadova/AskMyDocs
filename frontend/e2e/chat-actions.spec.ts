import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer, thread, waitForThreadReady } from './helpers';
import { seedDb } from './setup-helpers';

/*
 * v8.6 — the chat "live actions" E2E. These actions used to be dead in the
 * UI (no click handler / never triggered); this spec drives each one for
 * real, end-to-end, with nothing stubbed (R13):
 *
 *   1. Feedback thumbs — clicking 👍/👎 POSTs to the feedback endpoint and
 *      flips the persisted rating.
 *   2. Auto-title — after the first turn settles the BE generates a title
 *      from the transcript; the header stops showing "Conversation #N".
 *   3. Rename pencil — the header title is inline-editable (ChatGPT-style).
 *   4. Citation click — clicking a cited source navigates the admin to the
 *      KB document detail (`/app/admin/kb?doc=<id>`).
 *
 * Determinism: the server runs AI_PROVIDER=fake / AI_EMBEDDINGS_PROVIDER=fake
 * (see playwright.config.ts / the CI workflow). FakeProvider streams a canned
 * answer + constant embedding vector; E2eStreamSeeder ingests one
 * vector-searchable hr-portal doc so the grounded turn always cites it.
 * generateTitle also runs through the fake provider, so the auto-title is
 * deterministic + offline. The `seeded` fixture logs in as admin@demo.local
 * (admin role) so the citation→KB navigation is authorized.
 */

test.describe.configure({ timeout: 60_000 });

test.describe('Chat live actions — feedback, auto-title, rename, citation nav', () => {
    test('a grounded turn exposes working feedback, an auto-title + rename, and a clickable citation', async ({ page }) => {
        await seedDb(page, 'E2eStreamSeeder');

        await page.goto('/app/chat');
        const { input, send } = composer(page);
        await input.fill('How many days per week can I work remotely?');
        await send.click();

        // Wait for the grounded turn to settle (citation chip + ready).
        await expect(page.getByTestId('chat-citations')).toBeVisible({ timeout: 30_000 });
        await expect(page.getByTestId('chat-citation-0')).toBeVisible();
        await waitForThreadReady(page, 30_000);

        // 1) Feedback thumbs appear once the message persists; clicking 👍
        //    flips the rating to positive (POST /feedback round-trip).
        const feedbackUp = page.getByTestId('chat-feedback-up');
        await expect(feedbackUp).toBeVisible({ timeout: 30_000 });
        await feedbackUp.click();
        await expect(page.getByTestId('chat-feedback')).toHaveAttribute('data-rating', 'positive', {
            timeout: 10_000,
        });
        // Second click toggles it back off (server toggle semantics).
        await feedbackUp.click();
        await expect(page.getByTestId('chat-feedback')).toHaveAttribute('data-rating', 'none', {
            timeout: 10_000,
        });

        // 2) Auto-title: onFinish triggers BE generateTitle; the header title
        //    stops being the "Conversation #N" fallback.
        await expect(page.getByTestId('chat-title')).toBeVisible({ timeout: 30_000 });
        await expect
            .poll(async () => (await page.getByTestId('chat-title').innerText()).trim(), { timeout: 30_000 })
            .not.toMatch(/^Conversation #\d+$/);

        // 3) Rename pencil → inline edit → save persists the new title and the
        //    header reflects it (cache → prop re-render).
        await page.getByTestId('chat-title-rename').click();
        const titleInput = page.getByTestId('chat-title-input');
        await expect(titleInput).toBeVisible();
        await titleInput.fill('My renamed thread');
        await page.getByTestId('chat-title-save').click();
        await expect(page.getByTestId('chat-title')).toHaveText('My renamed thread', { timeout: 10_000 });

        // 4) Citation click → navigate to the KB document detail. (admin role
        //    is authorized for /app/admin/kb.)
        await expect(page.getByTestId('chat-citation-0')).toHaveAttribute('data-openable', 'true');
        await page.getByTestId('chat-citation-0').click();
        await expect(page).toHaveURL(/\/app\/admin\/kb\?.*doc=\d+/, { timeout: 15_000 });
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 15_000 });
    });
});
