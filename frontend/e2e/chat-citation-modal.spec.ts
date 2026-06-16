import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * Chat "open source" modal — clicking a citation opens the cited document in a
 * modal with its full source text.
 *
 * R13: the happy path runs against the REAL `/api/kb/documents/{id}/preview`
 * endpoint backed by the real DB. `KbCitationDocumentSeeder` persists a
 * conversation (owned by the authenticated admin) whose assistant message cites
 * a canonical doc that HAS chunks; opening the conversation renders the
 * persisted citation chip → clicking it fetches and renders the real content.
 * The failure path injects a 503 on the preview endpoint.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Chat — open cited document in a modal', () => {
    test('opens the cited source document with its real content (real data)', async ({ page, request }) => {
        await seedDb(request, 'KbCitationDocumentSeeder');

        await page.goto('/app/chat');
        // Open the seeded conversation from the sidebar by its title.
        await page.getByText('Source modal demo').first().click();

        // The persisted assistant message renders the citation chip.
        const chip = page.getByTestId('chat-citation-0');
        await expect(chip).toBeVisible({ timeout: 15_000 });

        // Click → modal opens and fetches the real document content.
        await chip.click();
        const modal = page.getByTestId('chat-citation-modal');
        await expect(modal).toBeVisible();
        await expect(modal).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('chat-citation-modal-title')).toHaveText('Cache backend decision');

        const body = page.getByTestId('chat-citation-modal-content');
        await expect(body).toContainText('Redis');
        await expect(body).toContainText('1 hour TTL');

        // Close → the modal unmounts.
        await page.getByTestId('chat-citation-modal-close').click();
        await expect(page.getByTestId('chat-citation-modal')).toBeHidden();
    });

    test('shows the error state when the preview endpoint returns 503', async ({ page, request }) => {
        await seedDb(request, 'KbCitationDocumentSeeder');

        // R13: failure injection — the happy-path test above covers the real
        // data flow; here we stub the preview endpoint to 503 so the error
        // branch renders deterministically.
        await page.route('**/api/kb/documents/*/preview', (route) =>
            route.fulfill({
                status: 503,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Service unavailable' }),
            }),
        );

        await page.goto('/app/chat');
        await page.getByText('Source modal demo').first().click();

        await page.getByTestId('chat-citation-0').click();
        await expect(page.getByTestId('chat-citation-modal')).toBeVisible();
        await expect(page.getByTestId('chat-citation-modal-error')).toBeVisible({ timeout: 15_000 });
        // The error is surfaced, not a silent blank (R14).
        await expect(page.getByTestId('chat-citation-modal-retry')).toBeVisible();
    });
});
