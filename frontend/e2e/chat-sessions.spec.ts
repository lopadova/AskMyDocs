import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.x — the Sessions workspace, end-to-end against real data (R13).
 *
 * Nothing is stubbed here: the sessions, the folder, the pin/archive/
 * importance writes and the deletes all go through the real
 * /conversations and /api/chat-folders endpoints against the seeded
 * DemoSeeder state (one unfiled session, one pinned, one filed +
 * critical, one archived).
 *
 * This spec covers ORGANISATION only. That the new panel drives a real
 * chat turn on the SAME engine as /chat is a separate scenario
 * (chat-sessions-turn.spec.ts) so a failure tells you which half broke.
 */

test.describe.configure({ timeout: 60_000 });

const SIDEBAR = 'chat-sessions-sidebar';

test.describe('Sessions workspace — organising chat sessions', () => {
    test('happy path — the seeded sessions arrive grouped, pinned first', async ({ page }) => {
        await page.goto('/app/sessions');

        const sidebar = page.getByTestId(SIDEBAR);
        await expect(sidebar).toHaveAttribute('data-state', 'ready', { timeout: 20_000 });

        // The knowledge base sits at the top of the rail, above the list.
        await expect(page.getByTestId('chat-sessions-kb-entry')).toBeVisible();

        // Pinned group exists and holds the seeded pinned session.
        const pinned = page.getByTestId('chat-sessions-group-pinned');
        await expect(pinned).toContainText('Pinned — expense policy');

        // The seeded folder is rendered with its one filed session, and
        // that session carries the critical flag.
        const folder = sidebar.locator('section[data-testid^="chat-sessions-folder-"]').first();
        await expect(folder).toContainText('Issue 42 — onboarding');
        await expect(folder).toContainText('Filed — onboarding checklist');
        await expect(
            sidebar.locator('[data-importance="critical"]'),
        ).toContainText('Filed — onboarding checklist');

        // Unfiled group holds the plain session.
        await expect(page.getByTestId('chat-sessions-group-unfiled')).toContainText(
            'Welcome — remote work questions',
        );

        // The archived session is NOT in the default listing — that is a
        // server-side exclusion, so its absence is the real assertion.
        await expect(sidebar).not.toContainText('Archived — old laptop request');
    });

    test('the archived drawer fetches on demand and restores a session', async ({ page }) => {
        await page.goto('/app/sessions');
        const sidebar = page.getByTestId(SIDEBAR);
        await expect(sidebar).toHaveAttribute('data-state', 'ready', { timeout: 20_000 });

        const toggle = page.getByTestId('chat-sessions-archived-toggle');
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-pressed', 'true');

        const archivedRow = sidebar
            .locator('[data-testid^="chat-sessions-row-"][data-archived="true"]')
            .first();
        await expect(archivedRow).toContainText('Archived — old laptop request', {
            timeout: 15_000,
        });

        // Restore it and assert it reappears in the MAIN list — proving the
        // prefix invalidation reached both cached lists.
        const rowId = (await archivedRow.getAttribute('data-testid'))?.replace(
            'chat-sessions-row-',
            '',
        );
        await page.getByTestId(`chat-sessions-row-${rowId}-menu`).click();
        await page.getByTestId(`chat-sessions-row-${rowId}-archive`).click();

        await expect(
            page.getByTestId(`chat-sessions-row-${rowId}`).first(),
        ).toHaveAttribute('data-archived', 'false', { timeout: 15_000 });
    });

    test('creates a folder, files a session into it and unfiles it again', async ({ page }) => {
        await page.goto('/app/sessions');
        const sidebar = page.getByTestId(SIDEBAR);
        await expect(sidebar).toHaveAttribute('data-state', 'ready', { timeout: 20_000 });

        await page.getByTestId('chat-sessions-new-folder').click();
        const dialog = page.getByTestId('chat-sessions-folder-dialog');
        await expect(dialog).toBeVisible();
        await page.getByTestId('chat-sessions-folder-dialog-input').fill('Refactor auth');
        await page.getByTestId('chat-sessions-folder-dialog-submit').click();
        await expect(dialog).toBeHidden();

        // `section[...]` and not a bare prefix match: the folder GROUPS are
        // sections, and a looser selector also catches the toggle button
        // nested inside each one.
        const newFolder = sidebar.locator('section[data-testid^="chat-sessions-folder-"]', {
            hasText: 'Refactor auth',
        });
        await expect(newFolder).toBeVisible({ timeout: 15_000 });
        await expect(newFolder).toHaveAttribute('data-count', '0');

        // File the unfiled session into it.
        const row = page
            .getByTestId('chat-sessions-group-unfiled')
            .locator('[data-testid^="chat-sessions-row-"]')
            .first();
        const rowId = (await row.getAttribute('data-testid'))?.replace('chat-sessions-row-', '');
        const folderId = (await newFolder.getAttribute('data-testid'))?.replace(
            'chat-sessions-folder-',
            '',
        );

        await page.getByTestId(`chat-sessions-row-${rowId}-menu`).click();
        await page.getByTestId(`chat-sessions-row-${rowId}-move-${folderId}`).click();

        await expect(newFolder).toHaveAttribute('data-count', '1', { timeout: 15_000 });

        // Unfile: the session returns to Unfiled, the folder empties.
        await page.getByTestId(`chat-sessions-row-${rowId}-menu`).click();
        await page.getByTestId(`chat-sessions-row-${rowId}-move-none`).click();

        await expect(newFolder).toHaveAttribute('data-count', '0', { timeout: 15_000 });
    });

    test('pins, flags and archives a session through the row menu', async ({ page }) => {
        await page.goto('/app/sessions');
        const sidebar = page.getByTestId(SIDEBAR);
        await expect(sidebar).toHaveAttribute('data-state', 'ready', { timeout: 20_000 });

        const row = page
            .getByTestId('chat-sessions-group-unfiled')
            .locator('[data-testid^="chat-sessions-row-"]')
            .first();
        const rowId = (await row.getAttribute('data-testid'))?.replace('chat-sessions-row-', '');
        const byId = page.getByTestId(`chat-sessions-row-${rowId}`);

        await expect(byId).toHaveAttribute('data-pinned', 'false');
        await page.getByTestId(`chat-sessions-row-${rowId}-menu`).click();
        await page.getByTestId(`chat-sessions-row-${rowId}-pin`).click();
        await expect(byId).toHaveAttribute('data-pinned', 'true', { timeout: 15_000 });
        // Pinning moves it into the Pinned group.
        await expect(page.getByTestId('chat-sessions-group-pinned')).toContainText(
            await byId.locator('.conv-row-title').innerText(),
        );

        await page.getByTestId(`chat-sessions-row-${rowId}-menu`).click();
        await page.getByTestId(`chat-sessions-row-${rowId}-importance-critical`).click();
        await expect(byId).toHaveAttribute('data-importance', 'critical', { timeout: 15_000 });

        await page.getByTestId(`chat-sessions-row-${rowId}-menu`).click();
        await page.getByTestId(`chat-sessions-row-${rowId}-archive`).click();
        // Archiving removes it from the default listing entirely.
        await expect(byId).toBeHidden({ timeout: 15_000 });
    });

    test('deleting a folder unfiles its sessions instead of deleting them', async ({ page }) => {
        await page.goto('/app/sessions');
        const sidebar = page.getByTestId(SIDEBAR);
        await expect(sidebar).toHaveAttribute('data-state', 'ready', { timeout: 20_000 });

        const folder = sidebar.locator('section[data-testid^="chat-sessions-folder-"]', {
            hasText: 'Issue 42 — onboarding',
        });
        const folderId = (await folder.getAttribute('data-testid'))?.replace(
            'chat-sessions-folder-',
            '',
        );

        await page.getByTestId(`chat-sessions-folder-${folderId}-delete`).click();
        // Deleting a folder is irreversible and its effect on the filed
        // sessions is not obvious from a bin icon, so it asks first.
        await expect(
            page.getByTestId(`chat-sessions-folder-${folderId}-delete-confirm-prompt`),
        ).toContainText(/not deleted/i);
        await page.getByTestId(`chat-sessions-folder-${folderId}-delete-confirm`).click();

        await expect(folder).toBeHidden({ timeout: 15_000 });
        // The session survives, merely unfiled — the whole point of
        // nullOnDelete rather than a cascade.
        await expect(page.getByTestId('chat-sessions-group-unfiled')).toContainText(
            'Filed — onboarding checklist',
            { timeout: 15_000 },
        );
    });

    test('a session delete asks for confirmation first', async ({ page }) => {
        await page.goto('/app/sessions');
        const sidebar = page.getByTestId(SIDEBAR);
        await expect(sidebar).toHaveAttribute('data-state', 'ready', { timeout: 20_000 });

        const row = page
            .getByTestId('chat-sessions-group-unfiled')
            .locator('[data-testid^="chat-sessions-row-"]')
            .first();
        const rowId = (await row.getAttribute('data-testid'))?.replace('chat-sessions-row-', '');

        await page.getByTestId(`chat-sessions-row-${rowId}-menu`).click();
        await page.getByTestId(`chat-sessions-row-${rowId}-delete`).click();

        // Still present: the first click only asked.
        await expect(page.getByTestId(`chat-sessions-row-${rowId}`)).toBeVisible();
        await expect(page.getByTestId(`chat-sessions-row-${rowId}-delete-cancel`)).toBeVisible();

        await page.getByTestId(`chat-sessions-row-${rowId}-delete-confirm`).click();
        await expect(page.getByTestId(`chat-sessions-row-${rowId}`)).toBeHidden({
            timeout: 15_000,
        });
    });

    test('failure path — a duplicate folder name surfaces the server error', async ({ page }) => {
        await page.goto('/app/sessions');
        await expect(page.getByTestId(SIDEBAR)).toHaveAttribute('data-state', 'ready', {
            timeout: 20_000,
        });

        // The seeded folder already holds this name for this user, so the
        // real BE answers 422 — no interception needed.
        await page.getByTestId('chat-sessions-new-folder').click();
        await page
            .getByTestId('chat-sessions-folder-dialog-input')
            .fill('Issue 42 — onboarding');
        await page.getByTestId('chat-sessions-folder-dialog-submit').click();

        await expect(page.getByTestId('chat-sessions-folder-dialog-error')).toBeVisible({
            timeout: 15_000,
        });
        // The dialog stays open so the typed name is not lost.
        await expect(page.getByTestId('chat-sessions-folder-dialog')).toBeVisible();
    });

    test('the knowledge-base entry opens the reader KB explorer', async ({ page }) => {
        await page.goto('/app/sessions');
        await expect(page.getByTestId(SIDEBAR)).toHaveAttribute('data-state', 'ready', {
            timeout: 20_000,
        });

        await page.getByTestId('chat-sessions-kb-entry').click();

        await expect(page).toHaveURL(/\/knowledge$/, { timeout: 15_000 });
        await expect(page.getByTestId('kb-browse-view')).toBeVisible();
    });
});
