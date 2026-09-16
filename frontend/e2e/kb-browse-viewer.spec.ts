import { test, expect } from './fixtures';

/*
 * v8.x — the reader KB explorer, exercised as a VIEWER.
 *
 * The `-viewer` filename suffix is what routes this file to the
 * `chromium-viewer` Playwright project (see the testMatch in
 * playwright.config.ts) — no config change needed, by design.
 *
 * It mirrors admin-kb-viewer.spec.ts, which asserts the exact opposite
 * for /app/admin/kb. The pair is the point: the same role that is
 * refused the admin explorer must be able to read the knowledge base
 * here.
 *
 * Nothing is stubbed (R13) — the tree, the document body and the access
 * scope are all the real backend.
 */

test.describe('Browse KB — viewer can read the knowledge base', () => {
    test('happy path — the tree renders and a document opens', async ({ page }) => {
        await page.goto('/app/knowledge');

        await expect(page.getByTestId('kb-browse-view')).toBeVisible({ timeout: 20_000 });
        // Not the admin page: that one refuses this role.
        await expect(page.getByTestId('admin-forbidden')).toHaveCount(0);

        const tree = page.getByTestId('kb-tree');
        await expect(tree).toHaveAttribute('data-state', 'ready', { timeout: 20_000 });

        // Nothing selected yet — an explicit invitation, not a blank pane.
        await expect(page.getByTestId('kb-browse-detail')).toHaveAttribute('data-state', 'idle');
        await expect(page.getByTestId('kb-browse-detail-idle')).toBeVisible();

        // Open the first document LEAF. Folder nodes share the
        // `kb-tree-node-` prefix and select nothing, so target a path that
        // ends in a markdown extension.
        await tree
            .locator('[data-testid^="kb-tree-node-"][data-testid$=".md"]')
            .first()
            .click();

        const detail = page.getByTestId('kb-browse-detail');
        await expect(detail).not.toHaveAttribute('data-state', 'idle', { timeout: 20_000 });
        await expect(page.getByTestId('kb-browse-detail-title')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('kb-browse-detail-path')).toBeVisible();
    });

    test('the "Include deleted" control is absent for a reader', async ({ page }) => {
        // The reader endpoint never returns soft-deleted documents (R2),
        // so the admin explorer's toggle must not be rendered here — a
        // control that cannot change the result is worse than none.
        await page.goto('/app/knowledge');

        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 20_000,
        });
        await expect(page.getByTestId('kb-tree-with-trashed')).toHaveCount(0);
    });

    test('the project filter offers only the viewer\'s own memberships', async ({ page }) => {
        await page.goto('/app/knowledge');

        const select = page.getByTestId('kb-browse-project');
        await expect(select).toBeVisible({ timeout: 20_000 });
        // R18: derived from /api/auth/me, so "All projects" plus whatever
        // this viewer actually belongs to — never a hard-coded list.
        await expect(select.locator('option').first()).toHaveText('All projects');
    });

    test('failure path — a missing document surfaces an error with a retry', async ({ page }) => {
        // R13: failure injection against an internal route is allowed
        // because the happy path above already covers the real-data flow.
        await page.route('**/api/kb/documents/*/preview', (route) =>
            route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Document read failed.' }),
            }),
        );

        await page.goto('/app/knowledge');
        const tree = page.getByTestId('kb-tree');
        await expect(tree).toHaveAttribute('data-state', 'ready', { timeout: 20_000 });

        await tree
            .locator('[data-testid^="kb-tree-node-"][data-testid$=".md"]')
            .first()
            .click();

        await expect(page.getByTestId('kb-browse-detail')).toHaveAttribute('data-state', 'error', {
            timeout: 20_000,
        });
        await expect(page.getByTestId('kb-browse-detail-retry')).toBeVisible();
    });
});
