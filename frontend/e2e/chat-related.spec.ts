import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * v8.8/W6 — chat-side "Related" graph panel.
 *
 * R13: the happy path runs against the REAL `/api/kb/related` endpoint backed
 * by the real DB. `KbChatGraphSeeder` persists a conversation (owned by the
 * authenticated admin) whose assistant message cites a canonical doc, plus
 * that doc's 1-hop graph neighbour. Opening the conversation renders the
 * persisted (non-streaming) message → the Related panel mounts → expanding it
 * fetches the real neighbours. The failure path injects a 503 on the endpoint.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Chat — Related graph panel', () => {
    test('expands to show the cited doc\'s graph neighbour (real data)', async ({ page, request }) => {
        await seedDb(request, 'KbChatGraphSeeder');

        await page.goto('/app/chat');
        // Open the seeded conversation from the sidebar by its title.
        await page.getByText('Cache architecture (graph demo)').first().click();

        // The assistant message renders the (collapsed) Related panel.
        const toggle = page.getByTestId('chat-related-toggle');
        await expect(toggle).toBeVisible({ timeout: 15_000 });
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');

        // Expand → real /api/kb/related → the graph neighbour surfaces.
        await toggle.click();
        const neighbour = page.getByTestId('chat-related-item-dec-redis-graph');
        await expect(neighbour).toBeVisible({ timeout: 15_000 });
        await expect(neighbour).toContainText('Redis decision (graph)');
        await expect(neighbour).toHaveAttribute('data-direction', 'outgoing');
    });

    // R13: failure injection — stubs the related endpoint to 503 so the
    // error-state branch renders deterministically. The happy path above
    // exercises real data.
    test('shows the error state when the related endpoint returns 503', async ({ page, request }) => {
        await seedDb(request, 'KbChatGraphSeeder');

        // R13: failure injection
        await page.route('**/api/kb/related**', (route) =>
            route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Service unavailable' }) }),
        );

        await page.goto('/app/chat');
        await page.getByText('Cache architecture (graph demo)').first().click();

        const toggle = page.getByTestId('chat-related-toggle');
        await expect(toggle).toBeVisible({ timeout: 15_000 });
        await toggle.click();
        await expect(page.getByTestId('chat-related-error')).toBeVisible({ timeout: 15_000 });
    });
});
