import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * PR11 — Phase G4. Admin KB Graph tab + Export PDF scenarios.
 *
 * Runs against the REAL backend seeded by DemoSeeder. The seeder
 * was extended in G4 to populate kb_nodes + a single kb_edges row
 * between hr-portal's `remote-work-policy` and `pto-guidelines` so
 * the Graph tab renders a real subgraph without waiting for
 * CanonicalIndexerJob to drain a real queue. The Export-PDF
 * scenario asserts the 501 envelope under the default
 * ADMIN_PDF_ENGINE=disabled config — operators see an actionable
 * toast when the engine has not been wired up yet.
 *
 * R13 compliance: the failure-injection scenario below is the ONLY
 * place we use request interception against an internal route, and
 * it carries the required marker comment on the preceding lines.
 */

test.describe('Admin KB Graph + Export PDF', () => {
    test('happy — canonical graph renders with ready state + nodes', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const node = page.getByTestId('kb-tree-node-policies/remote-work-policy.md');
        await expect(node).toBeVisible({ timeout: 10_000 });
        await node.click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });

        // Switch to the Graph tab — the subgraph query fires once the
        // tab becomes active.
        await page.getByTestId('kb-tab-graph').click();

        // The seeded hr-portal canonical doc has a kb_nodes row + one
        // edge to pto-guidelines, so data-state flips to "ready" and
        // at least one kb-graph-node-* testid is visible.
        await expect(page.getByTestId('kb-graph')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Center node (the seed) — deterministic uid from the seeder.
        await expect(
            page.getByTestId('kb-graph-node-remote-work-policy'),
        ).toBeVisible({ timeout: 10_000 });
        await expect(
            page.getByTestId('kb-graph-node-remote-work-policy'),
        ).toHaveAttribute('data-role', 'center');

        // Neighbor — the seeded related_to edge.
        await expect(page.getByTestId('kb-graph-node-pto-guidelines')).toBeVisible();
        await expect(page.getByTestId('kb-graph-node-pto-guidelines')).toHaveAttribute(
            'data-role',
            'neighbor',
        );

        // Edge testid carries data-edge-type so a future filter UI can
        // key off the DOM without re-fetching.
        // SVG `<g>` elements with a single `<line>` child report a
        // bounding box equal to the line; when nodes overlap on the
        // initial layout pass the line collapses to 0×0 and Playwright's
        // visibility check fails. The DOM-attachment check is the
        // honest contract here — the edge IS in the SVG, with the right
        // edge-type — so assert presence + attribute, not visibility.
        const edge = page.getByTestId('kb-graph-edge-demo-edge-remote-pto');
        await expect(edge).toBeAttached();
        await expect(edge).toHaveAttribute('data-edge-type', 'related_to');
    });

    test('engineering runbook — graph ready with only the center node (no edges)', async ({ page }) => {
        // Copilot #1 fix: this scenario was originally mislabeled as
        // "empty state" but it actually asserts `data-state="ready"`
        // with a seeded center node visible — DemoSeeder's engineering
        // runbook has one `kb_nodes` row and zero `kb_edges`, so the
        // wrapper never enters `empty`. Renamed + repurposed to what
        // the body actually verifies: the Graph tab handles the
        // "ready with a singleton subgraph" case correctly. True
        // empty-state (no seed node found) is exercised by the
        // Vitest `GraphTab empty` scenario where we can stub the
        // hook deterministically.
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        // Switch to the engineering project so the runbook appears.
        await page.getByTestId('kb-project-select').selectOption('engineering');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const node = page.getByTestId('kb-tree-node-runbooks/incident-response.md');
        await expect(node).toBeVisible({ timeout: 10_000 });
        await node.click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });

        await page.getByTestId('kb-tab-graph').click();

        // The engineering runbook has a kb_node but no edges — the
        // wrapper is "ready" with just the center node visible and
        // no `kb-graph-empty` surface (the latter renders only when
        // the endpoint returns zero nodes).
        await expect(page.getByTestId('kb-graph')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('kb-graph-empty')).toHaveCount(0);
        await expect(
            page.getByTestId('kb-graph-node-incident-response'),
        ).toHaveAttribute('data-role', 'center');
    });

    test('failure injection — graph 500 surfaces kb-graph-error', async ({ page }) => {
        // R13: failure injection — real path is tested in the happy
        // "canonical graph renders" scenario above. The spy here is
        // the only way to assert the 5xx UX without poisoning the
        // seeded graph rows.
        // request interception against internal GET /graph.
        await page.route('**/api/admin/kb/documents/*/graph', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({
                    status: 500,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'synthetic graph failure' }),
                });
            }
            return route.fallback();
        });

        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await page.getByTestId('kb-tree-node-policies/remote-work-policy.md').click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });

        await page.getByTestId('kb-tab-graph').click();
        await expect(page.getByTestId('kb-graph')).toHaveAttribute('data-state', 'error', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('kb-graph-error')).toBeVisible();
    });

    test('export PDF — 501 by default surfaces toast-error with enable hint', async ({ page }) => {
        // Default ADMIN_PDF_ENGINE=disabled on the admin env — no
        // interception needed. The server returns 501 and the
        // mutation onError parses the JSON body out of the Blob
        // response and feeds the message into a toast.
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await page.getByTestId('kb-tree-node-policies/remote-work-policy.md').click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });

        const exportBtn = page.getByTestId('kb-action-export-pdf');
        await expect(exportBtn).toBeVisible();
        await exportBtn.click();

        await expect(page.getByTestId('toast-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('toast-error')).toContainText('PDF export disabled');
    });
});
