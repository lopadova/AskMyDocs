import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * PR8 — Phase G1. Admin KB tree explorer scenarios.
 *
 * Runs against the REAL backend seeded with DemoSeeder — the seeder
 * ships three canonical docs across two projects, so the tree has
 * non-trivial structure on first load.
 *
 * R13 compliance: no request interception against internal routes.
 * If a failure-path scenario needs one, it must carry a preceding
 * `R13: failure injection` marker comment (see verify-e2e-real-data.sh).
 */

test.describe('Admin KB Tree', () => {
    test('happy — browse tree renders canonical doc node', async ({ page }) => {
        await page.goto('/app/admin/kb');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-view')).toBeVisible();
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // DemoSeeder ships `policies/remote-work-policy.md` under
        // hr-portal. The node testid follows the `kb-tree-node-<path>`
        // convention — path segments are literal in the DOM.
        const node = page.getByTestId('kb-tree-node-policies/remote-work-policy.md');
        await expect(node).toBeVisible({ timeout: 10_000 });
        await expect(node).toHaveAttribute('data-canonical', 'true');

        // Detail panel placeholder is visible before any selection.
        await expect(page.getByTestId('kb-detail-placeholder')).toBeVisible();

        // Selecting a doc updates the right-hand summary.
        await node.click();
        await expect(page.getByTestId('kb-detail-summary')).toBeVisible({ timeout: 10_000 });
    });

    test('failure — mode=canonical hides non-canonical docs', async ({ page, request }) => {
        // Seed a non-canonical doc on top of the DemoSeeder canonical set
        // so the "mode=canonical hides it" assertion has something to
        // actually hide.
        const csrfOk = await request.get('/sanctum/csrf-cookie');
        expect(csrfOk.ok()).toBeTruthy();
        // Copilot #1 fix: KbIngestController expects `project_key` +
        // `content` inside each document; the top-level `project` +
        // `markdown` shape would 422 against the controller contract.
        const ingest = await request.post('/api/kb/ingest', {
            data: {
                documents: [
                    {
                        project_key: 'hr-portal',
                        source_path: 'drafts/non-canonical-draft.md',
                        title: 'Non-canonical draft',
                        content: '# Draft\n\nNo frontmatter — not canonical.',
                    },
                ],
            },
        });
        // Accept 202 (queued) or 200/201 depending on stack.
        expect([200, 201, 202]).toContain(ingest.status());

        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Scope to hr-portal so the Engineering runbook doesn't skew
        // the counts.
        await page.getByTestId('kb-project-select').selectOption('hr-portal');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Flip to canonical-only mode. The non-canonical draft (if it
        // ingested) must not appear; the seeded canonical policy must.
        await page.getByTestId('kb-tree-mode').selectOption('canonical');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const canonicalDocs = page.locator('[data-testid^="kb-tree-node-"][data-type="doc"]');
        const count = await canonicalDocs.count();
        for (let i = 0; i < count; i++) {
            await expect(canonicalDocs.nth(i)).toHaveAttribute('data-canonical', 'true');
        }
        expect(count).toBeGreaterThan(0);
    });
});
