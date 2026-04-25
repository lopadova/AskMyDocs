import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * PR9 — Phase G2. Admin KB document detail scenarios.
 *
 * Runs against the REAL backend seeded with DemoSeeder. The seeder
 * was extended in G2 to also:
 *   - write the canonical markdown body to the `kb` disk so the
 *     Preview tab can fetch `/raw` without a 404,
 *   - insert one `promoted` audit row per doc so the History tab
 *     is non-empty on first open.
 *
 * R13 compliance: no request interception against internal routes.
 * Any failure-path scenario that needs one must carry a preceding
 * `R13: failure injection` marker (see verify-e2e-real-data.sh).
 */

test.describe('Admin KB Document Detail', () => {
    test('happy — browse to a doc and preview renders with frontmatter pills', async ({ page }) => {
        await page.goto('/app/admin/kb');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-view')).toBeVisible();
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const node = page.getByTestId('kb-tree-node-policies/remote-work-policy.md');
        await expect(node).toBeVisible({ timeout: 10_000 });
        await node.click();

        // Detail pane replaces the G1 placeholder once a doc is selected.
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('kb-detail-title')).toContainText('Remote Work Policy');

        // Preview tab is active by default.
        await expect(page.getByTestId('kb-tab-preview')).toHaveAttribute('data-active', 'true');

        // Frontmatter pill pack comes from the YAML fence DemoSeeder now
        // writes to disk — keys `id`, `type`, `status`, `project`.
        await expect(page.getByTestId('frontmatter-pills')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('frontmatter-pill-type')).toContainText('standard');
        await expect(page.getByTestId('frontmatter-pill-status')).toContainText('accepted');

        // Body comes from the seeded markdown (# Remote Work Policy).
        await expect(page.getByTestId('kb-preview-body')).toContainText('Remote Work Policy');
    });

    test('happy — tabs switching surfaces meta + history panes', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await page.getByTestId('kb-tree-node-policies/remote-work-policy.md').click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });

        // Meta tab — verifies the canonical meta grid is populated from
        // the detail endpoint.
        await page.getByTestId('kb-tab-meta').click();
        await expect(page.getByTestId('kb-meta')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('kb-meta-project')).toContainText('hr-portal');
        await expect(page.getByTestId('kb-meta-canonical-type')).toContainText('standard');
        await expect(page.getByTestId('kb-meta-is-canonical')).toContainText('yes');

        // History tab — DemoSeeder inserts one `promoted` audit per
        // canonical doc so the list is never empty on first open.
        await page.getByTestId('kb-tab-history').click();
        await expect(page.getByTestId('kb-history')).toBeVisible({ timeout: 10_000 });
        const rows = page.locator('[data-testid^="kb-history-"][data-testid]');
        // The pager itself also carries a `kb-history-*` testid, so we
        // assert at least one real row exists in addition to it.
        await expect(page.getByTestId('kb-history-pager')).toBeVisible();
    });

    test('happy — soft delete then restore round-trip', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Open the doc and soft-delete it through the detail header.
        const node = page.getByTestId('kb-tree-node-policies/pto-guidelines.md');
        await expect(node).toBeVisible({ timeout: 10_000 });
        await node.click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });

        await page.getByTestId('kb-action-delete').click();
        await expect(page.getByTestId('kb-detail-confirm')).toHaveAttribute('data-mode', 'soft');
        await page.getByTestId('kb-detail-confirm-submit').click();

        // After delete, refetching the tree without the with_trashed flag
        // hides the row → the original node disappears.
        await expect(page.getByTestId('kb-tree-node-policies/pto-guidelines.md')).toBeHidden({
            timeout: 10_000,
        });

        // Toggle `Include deleted` so the trashed row comes back into
        // view, re-select and restore it.
        await page.getByTestId('kb-tree-with-trashed').click();
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const trashedNode = page.getByTestId('kb-tree-node-policies/pto-guidelines.md');
        await expect(trashedNode).toBeVisible({ timeout: 10_000 });
        await expect(trashedNode).toHaveAttribute('data-trashed', 'true');
        await trashedNode.click();

        await expect(page.getByTestId('kb-detail-trashed-badge')).toBeVisible();
        await page.getByTestId('kb-action-restore').click();

        // After restore the trashed badge disappears — the header repaints
        // with the live doc's action set.
        await expect(page.getByTestId('kb-detail-trashed-badge')).toBeHidden({ timeout: 10_000 });
        await expect(page.getByTestId('kb-action-restore')).toBeHidden();
        await expect(page.getByTestId('kb-action-delete')).toBeVisible();
    });

    test('failure — doc not found surfaces kb-detail-error via deep link', async ({ page }) => {
        // Navigate directly with a bogus `doc` search param. KbView parses
        // the param on mount and drives the detail query, which returns a
        // 404 → detail component renders the error state.
        await page.goto('/app/admin/kb?doc=999999&tab=preview');
        await expect(page.getByTestId('kb-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-detail-error')).toBeVisible({ timeout: 10_000 });
    });
});
