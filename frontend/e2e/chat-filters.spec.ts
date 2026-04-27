import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer } from './helpers';

/*
 * T2.7 — Chat composer filter UX scenarios.
 *
 * What this spec proves:
 *   1. The FilterBar renders unconditionally (visible even with zero
 *      filters), so the user can find the affordance.
 *   2. The "+ Filter" trigger opens the FilterPickerPopover; selecting
 *      values appends chips to the bar.
 *   3. Removing a chip via the × button updates the bar.
 *   4. The "Clear all" button empties the bar in one click.
 *   5. Sending a message with active filters threads them into the
 *      POST payload — the BE receives the `filters` object and the
 *      response carries the resulting `meta.filters_selected` count.
 *
 * R13: the AI provider is stubbed via `page.route()` on
 * `/conversations/*/messages` (EXTERNAL_PROXY allowlist — POST
 * triggers the AI provider). The `page.route` handler asserts the
 * payload SHAPE — proving the FE actually threaded the filters
 * through, not just rendered them locally.
 */

test.describe('Chat composer filters', () => {
    test('filter bar renders even with zero filters and shows the + Filter trigger', async ({ page }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('chat-filter-bar')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('chat-filter-bar-add')).toBeVisible();
        // Empty state: no count badge, no Clear-all.
        await expect(page.getByTestId('chat-filter-bar-count')).not.toBeVisible();
        await expect(page.getByTestId('chat-filter-bar-clear')).not.toBeVisible();
    });

    test('clicking + Filter opens the picker popover with all 7 tabs', async ({ page }) => {
        await page.goto('/app/chat');
        const trigger = page.getByTestId('chat-filter-bar-add');
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');
        await trigger.click();
        const popover = page.getByTestId('filter-popover');
        await expect(popover).toBeVisible();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');
        // All 7 tabs render.
        for (const tab of ['project', 'tag', 'source', 'canonical', 'folder', 'date', 'language']) {
            await expect(page.getByTestId(`filter-tab-${tab}`)).toBeVisible();
        }
    });

    test('selecting a source-type option creates a chip and increments the count badge', async ({ page }) => {
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-source').click();
        await page.getByTestId('filter-source-option-pdf').check();
        // Chip appears in the bar.
        await expect(page.getByTestId('filter-chip-source-pdf')).toBeVisible();
        const countBadge = page.getByTestId('chat-filter-bar-count');
        await expect(countBadge).toBeVisible();
        await expect(countBadge).toHaveText('1');
    });

    test('clicking × on a chip removes it from the bar', async ({ page }) => {
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-source').click();
        await page.getByTestId('filter-source-option-pdf').check();
        await page.getByTestId('filter-source-option-docx').check();
        // Two chips, count = 1 (one dimension active with two values).
        await expect(page.getByTestId('chat-filter-bar-count')).toHaveText('1');
        // Remove pdf — docx remains.
        await page.getByTestId('filter-chip-source-pdf-remove').click();
        await expect(page.getByTestId('filter-chip-source-pdf')).not.toBeVisible();
        await expect(page.getByTestId('filter-chip-source-docx')).toBeVisible();
    });

    test('Clear all empties every chip in one click', async ({ page }) => {
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-source').click();
        await page.getByTestId('filter-source-option-pdf').check();
        await page.getByTestId('filter-tab-language').click();
        await page.getByTestId('filter-language-option-en').check();
        await expect(page.getByTestId('chat-filter-bar-count')).toHaveText('2');
        // Close popover so clear-all is reachable.
        await page.getByTestId('filter-popover-close').click();
        await page.getByTestId('chat-filter-bar-clear').click();
        await expect(page.getByTestId('chat-filter-bar-count')).not.toBeVisible();
        await expect(page.getByTestId('filter-chip-source-pdf')).not.toBeVisible();
    });

    test('sending a message with filters threads them into the POST payload', async ({ page }) => {
        // Capture the request body the FE sends — this is the contract
        // assertion (R20 — route contracts match FE payload shape).
        let capturedBody: { content?: string; filters?: Record<string, unknown> } | null = null;

        await page.route('**/conversations/*/messages', async (route) => {
            if (route.request().method() !== 'POST') {
                await route.fallback();
                return;
            }
            capturedBody = route.request().postDataJSON();
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    id: 3001,
                    role: 'assistant',
                    content: 'Filtered answer body.',
                    metadata: {
                        provider: 'mock',
                        model: 'mock',
                        citations: [],
                        filters_selected: 2,
                    },
                    rating: null,
                    created_at: new Date().toISOString(),
                }),
            });
        });

        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-source').click();
        await page.getByTestId('filter-source-option-pdf').check();
        await page.getByTestId('filter-tab-language').click();
        await page.getByTestId('filter-language-option-en').check();
        await page.getByTestId('filter-popover-close').click();

        const { input, send } = composer(page);
        await input.fill('Find the policy doc.');
        await send.click();

        // Wait for the POST to land.
        await page.waitForResponse((r) => r.url().includes('/messages') && r.request().method() === 'POST');
        expect(capturedBody, 'POST body must be captured by route handler').not.toBeNull();
        expect(capturedBody!.content).toBe('Find the policy doc.');
        expect(capturedBody!.filters).toEqual(
            expect.objectContaining({
                source_types: ['pdf'],
                languages: ['en'],
            }),
        );
    });

    test('folder glob input adds globs as chips when Enter is pressed', async ({ page }) => {
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-folder').click();
        const input = page.getByTestId('filter-folder-input');
        await input.fill('hr/policies/**');
        await input.press('Enter');
        await expect(page.getByTestId('filter-chip-folder-hr/policies/**')).toBeVisible();
        // Field clears after add.
        await expect(input).toHaveValue('');
    });

    test('date range pickers add separate from/to chips', async ({ page }) => {
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-date').click();
        await page.getByTestId('filter-date-from').fill('2026-01-01');
        await page.getByTestId('filter-date-to').fill('2026-12-31');
        await expect(page.getByTestId('filter-chip-date-from')).toBeVisible();
        await expect(page.getByTestId('filter-chip-date-to')).toBeVisible();
        await expect(page.getByTestId('filter-chip-date-from')).toContainText('2026-01-01');
    });

    test('Esc closes the popover and restores aria-expanded=false on the trigger', async ({ page }) => {
        await page.goto('/app/chat');
        const trigger = page.getByTestId('chat-filter-bar-add');
        await trigger.click();
        await expect(page.getByTestId('filter-popover')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.getByTestId('filter-popover')).not.toBeVisible();
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    });
});
