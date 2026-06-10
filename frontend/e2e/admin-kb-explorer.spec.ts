import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * KB filesystem explorer — multi-select + preview + bulk scenarios.
 *
 * Runs against the REAL backend seeded with DemoSeeder (R13 — no
 * internal route interception). The seeder writes the markdown bodies
 * to the kb disk, so the preview pane and the ZIP export both operate
 * on real files. DemoSeeder ships, under hr-portal:
 *   policies/remote-work-policy.md  ("ACME employees may work remotely…")
 *   policies/pto-guidelines.md
 * and under engineering: runbooks/incident-response.md.
 */

const POLICY_DOC = '[data-testid^="kb-explorer-doc-"][data-type="doc"]';

async function openExplorer(page: import('@playwright/test').Page) {
    await page.goto('/app/admin/kb');
    await expect(page.getByTestId('kb-view')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
        timeout: 15_000,
    });
    await page.getByTestId('kb-view-toggle-explorer').click();
    await expect(page.getByTestId('kb-explorer')).toBeVisible({ timeout: 10_000 });
}

test.describe('Admin KB Explorer', () => {
    test('happy — navigate, preview a doc, open full detail', async ({ page }) => {
        await openExplorer(page);

        // Root shows the virtual `policies` folder. Navigate into it.
        const folder = page.getByTestId('kb-explorer-folder-policies');
        await expect(folder).toBeVisible({ timeout: 10_000 });
        await folder.click();

        // Breadcrumb gained the policies crumb; the two policy docs render.
        await expect(page.getByTestId('kb-explorer-crumb-policies')).toBeVisible();
        await expect(page.locator(POLICY_DOC)).toHaveCount(2, { timeout: 10_000 });

        // Double-click the Remote Work Policy card → preview pane shows
        // the real markdown body written by the seeder.
        const remoteCard = page
            .locator(POLICY_DOC)
            .filter({ hasText: 'Remote Work Policy' });
        await remoteCard.dblclick();

        const preview = page.getByTestId('kb-explorer-preview');
        await expect(preview).toBeVisible({ timeout: 10_000 });
        await expect(preview).toContainText('ACME employees may work remotely', {
            timeout: 10_000,
        });

        // "Open full detail" hands off to the tree+detail view.
        await page.getByTestId('kb-explorer-preview-open-detail').click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });
        // Handoff switched back to tree view (view param dropped) with
        // the doc deep-linked.
        await expect(page).toHaveURL(/doc=\d+/);
        await expect(page).not.toHaveURL(/view=explorer/);
    });

    test('bulk — select two docs and download a ZIP', async ({ page }) => {
        await openExplorer(page);
        await page.getByTestId('kb-explorer-folder-policies').click();
        await expect(page.locator(POLICY_DOC)).toHaveCount(2, { timeout: 10_000 });

        // Click the first card, ctrl/cmd-click the second → 2 selected.
        const cards = page.locator(POLICY_DOC);
        await cards.nth(0).click();
        await cards.nth(1).click({ modifiers: ['ControlOrMeta'] });

        const toolbar = page.getByTestId('kb-explorer-bulk-toolbar');
        await expect(toolbar).toBeVisible();
        await expect(page.getByTestId('kb-explorer-bulk-count')).toHaveText('2 selected');

        const downloadPromise = page.waitForEvent('download');
        await page.getByTestId('kb-explorer-bulk-zip').click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toMatch(/^kb-export-.*\.zip$/);
    });

    test('bulk — soft-delete then restore round-trips against real data', async ({ page }) => {
        await openExplorer(page);
        await page.getByTestId('kb-explorer-folder-policies').click();
        await expect(page.locator(POLICY_DOC)).toHaveCount(2, { timeout: 10_000 });

        // Select one doc and soft-delete it.
        const ptoCard = page.locator(POLICY_DOC).filter({ hasText: 'PTO Guidelines' });
        await ptoCard.click();
        await page.getByTestId('kb-explorer-bulk-delete').click();
        await page.getByTestId('kb-explorer-confirm-submit').click();

        await expect(page.getByTestId('kb-explorer-bulk-toast')).toBeVisible({ timeout: 10_000 });
        // The default tree mode hides trashed docs → one card left.
        await expect(page.locator(POLICY_DOC)).toHaveCount(1, { timeout: 10_000 });

        // Enable "Include deleted" so the trashed doc reappears, then
        // restore it.
        await page.getByTestId('kb-tree-with-trashed').check();
        await expect(page.locator(POLICY_DOC)).toHaveCount(2, { timeout: 10_000 });

        const trashedCard = page
            .locator(`${POLICY_DOC}[data-trashed="true"]`)
            .first();
        await trashedCard.click();
        await page.getByTestId('kb-explorer-bulk-restore').click();
        await page.getByTestId('kb-explorer-confirm-submit').click();

        await expect(page.getByTestId('kb-explorer-bulk-toast')).toBeVisible({ timeout: 10_000 });
        // After restore no card in this folder is trashed anymore.
        await expect(page.locator(`${POLICY_DOC}[data-trashed="true"]`)).toHaveCount(0, {
            timeout: 10_000,
        });
    });

    test('failure — no toolbar without a selection; unknown deep-link path falls back to root', async ({
        page,
    }) => {
        // Deep-link straight into a path that does not exist.
        await page.goto('/app/admin/kb?view=explorer&path=does/not/exist');
        await expect(page.getByTestId('kb-explorer')).toBeVisible({ timeout: 15_000 });

        // R17 fallback — the explorer snaps to root, so the bad crumb is
        // absent and the root view (policies folder) renders.
        await expect(page.getByTestId('kb-explorer-crumb-does/not/exist')).toHaveCount(0);
        await expect(page.getByTestId('kb-explorer-folder-policies')).toBeVisible({
            timeout: 10_000,
        });

        // With nothing selected the bulk toolbar is not in the DOM.
        await expect(page.getByTestId('kb-explorer-bulk-toolbar')).toHaveCount(0);
    });
});
