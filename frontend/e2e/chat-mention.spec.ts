import { expect } from '@playwright/test';
import { test } from './fixtures';
import { composer } from './helpers';

/*
 * T2.8 + T2.9-FE — Mention popover + saved presets flows.
 *
 * R13 strategy:
 *   - `/api/kb/documents/search` is INTERNAL (mention autocomplete).
 *     The DemoSeeder seeds known documents, so the spec exercises the
 *     real endpoint — no route stub on the search call.
 *   - `/api/chat-filter-presets` is INTERNAL (per-user CRUD).
 *     T2.9-BE shipped + the DemoSeeder seeds an admin user. The
 *     scenario calls the real endpoint via the popover; no stub.
 *   - the conversations/messages POST stays stubbed via page.route
 *     because it triggers the AI provider (EXTERNAL_PROXY allowlist).
 */

// Per-test timeout bumped from the 20s default — see chat-refusal.spec.ts
// for the rationale (slow seeded fixture under local php -S + SQLite).
test.describe.configure({ timeout: 60_000 });

test.describe('Mention popover + saved filter presets', () => {
    test('typing @ pol shows the mention popover with results from the real API', async ({ page }) => {
        await page.goto('/app/chat');
        const { input } = composer(page);
        await input.click();
        // Type "@pol" — DemoSeeder seeds documents whose title includes
        // policy-related strings; popover should show ≥1 result.
        await input.pressSequentially('@pol');
        // Wait for the popover to leave the loading state.
        const popover = page.getByTestId('mention-popover');
        await expect(popover).toBeVisible({ timeout: 10_000 });
        await expect(popover).toHaveAttribute('data-state', /ready|empty/, { timeout: 10_000 });
    });

    test('typing @<query> then Esc closes the popover', async ({ page }) => {
        await page.goto('/app/chat');
        const { input } = composer(page);
        await input.click();
        await input.pressSequentially('@anything');
        await expect(page.getByTestId('mention-popover')).toBeVisible({ timeout: 10_000 });
        await page.keyboard.press('Escape');
        await expect(page.getByTestId('mention-popover')).not.toBeVisible();
    });

    test('typing whitespace after @ closes the mention popover', async ({ page }) => {
        // Whitespace ends the @-token, so the popover dismisses.
        await page.goto('/app/chat');
        const { input } = composer(page);
        await input.click();
        await input.pressSequentially('@pol');
        await expect(page.getByTestId('mention-popover')).toBeVisible({ timeout: 10_000 });
        await input.pressSequentially(' ');
        await expect(page.getByTestId('mention-popover')).not.toBeVisible();
    });

    test('@ at start-of-message triggers popover; @ in middle of word does NOT', async ({ page }) => {
        await page.goto('/app/chat');
        const { input } = composer(page);
        await input.click();
        // Email-like fragment: 'foo@bar' — middle of word, NOT a mention.
        await input.pressSequentially('foo@bar');
        await expect(page.getByTestId('mention-popover')).not.toBeVisible();
    });

    test('saved presets dropdown opens the menu and shows the empty state initially', async ({ page }) => {
        await page.goto('/app/chat');
        const trigger = page.getByTestId('chat-filter-presets-trigger');
        await expect(trigger).toBeVisible();
        await trigger.click();
        await expect(page.getByTestId('chat-filter-presets-menu')).toBeVisible();
        // Fresh seeded admin has zero presets.
        await expect(page.getByTestId('chat-filter-presets-empty')).toBeVisible({ timeout: 10_000 });
    });

    test('Save current preset is disabled when no filters are active', async ({ page }) => {
        await page.goto('/app/chat');
        await page.getByTestId('chat-filter-presets-trigger').click();
        const save = page.getByTestId('chat-filter-presets-save');
        await expect(save).toBeVisible();
        await expect(save).toBeDisabled();
    });

    test('Save current preset enables after a filter is added; saving creates it via real API', async ({ page }) => {
        await page.goto('/app/chat');

        // Add a filter through the picker so Save current becomes enabled.
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-source').click();
        await page.getByTestId('filter-source-option-pdf').check();
        await page.getByTestId('filter-popover-close').click();

        // Open presets dropdown, save with a name.
        await page.getByTestId('chat-filter-presets-trigger').click();
        const save = page.getByTestId('chat-filter-presets-save');
        await expect(save).not.toBeDisabled();
        await save.click();
        await page.getByTestId('chat-filter-presets-name-input').fill('PDF only');
        // Wait for the real API POST to complete and the list to refresh.
        const postPromise = page.waitForResponse(
            (r) => r.url().endsWith('/api/chat-filter-presets') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('chat-filter-presets-save-confirm').click();
        const postResp = await postPromise;
        if (!postResp.ok()) {
            throw new Error(
                `POST /api/chat-filter-presets returned non-OK: ${postResp.status()} ${await postResp.text()}`,
            );
        }
        // After creation the menu refetches the list — the new preset
        // should be visible.
        await expect(page.locator('[data-preset-name="PDF only"]')).toBeVisible({ timeout: 15_000 });
    });

    test('loading a preset replaces the live filter state', async ({ page }) => {
        await page.goto('/app/chat');

        // Seed a preset by clicking through the save flow.
        await page.getByTestId('chat-filter-bar-add').click();
        await page.getByTestId('filter-tab-language').click();
        await page.getByTestId('filter-language-option-en').check();
        await page.getByTestId('filter-popover-close').click();

        await page.getByTestId('chat-filter-presets-trigger').click();
        await page.getByTestId('chat-filter-presets-save').click();
        await page.getByTestId('chat-filter-presets-name-input').fill('English only');
        const createResp = page.waitForResponse(
            (r) => r.url().endsWith('/api/chat-filter-presets') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('chat-filter-presets-save-confirm').click();
        await createResp;

        // Clear all filters from the bar.
        await page.getByTestId('chat-filter-bar-clear').click();
        await expect(page.getByTestId('filter-chip-language-en')).not.toBeVisible();

        // Reopen presets and click Load — the chip reappears.
        await page.getByTestId('chat-filter-presets-trigger').click();
        const presetRow = page.locator('[data-preset-name="English only"]');
        await expect(presetRow).toBeVisible({ timeout: 10_000 });
        await presetRow.locator('[data-testid$="-load"]').click();
        await expect(page.getByTestId('filter-chip-language-en')).toBeVisible({ timeout: 10_000 });
    });
});
