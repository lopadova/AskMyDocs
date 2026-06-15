import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * v8.13/P11 — Evidence & Risk Review admin screen (native cross-mount of the
 * padosoft/laravel-evidence-risk-review HTTP API).
 *
 * R13: the happy path runs against the REAL /api/admin/evidence-risk-review/*
 * endpoints backed by the real DB (`EvidenceRiskReviewSeeder` inserts two review
 * rows for the `default` tenant). It exercises the data probe → mount, the
 * reviews list + detail drill-down, the profiles list, and the taxonomy table.
 * The failure path injects a 503 on the data probe and asserts the clean
 * "unavailable" landing (R14 — degrade loudly but clean, never a crash).
 *
 * The default-OFF flag state (clean unavailable landing when
 * EVIDENCE_RISK_REVIEW_ADMIN_ENABLED is unset) is covered by the
 * EvidenceRiskReviewView Vitest (R43 both states). Here the webServer boots with
 * the flag ON so the enabled surface is exercised end-to-end.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Evidence & Risk Review', () => {
    test('mounts from real data and drills into a review, profiles and taxonomy', async ({ page, request }) => {
        await seedDb(request, 'EvidenceRiskReviewSeeder');

        await page.goto('/app/admin/evidence-risk-review');

        const host = page.getByTestId('admin-evidence-risk-review-host');
        await expect(host).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('admin-evidence-risk-review-app')).toBeVisible();

        // Reviews list renders the seeded rows.
        const reviews = page.getByTestId('admin-evidence-risk-review-reviews');
        await expect(reviews).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        const table = page.getByTestId('admin-evidence-risk-review-reviews-table');
        await expect(table).toContainText('doc-medical-claim');
        await expect(table).toContainText('flag for human review');

        // Drill into the flagged review — its finding + reason load from the real
        // detail endpoint.
        await page.getByTestId('admin-evidence-risk-review-row-e2e-review-flag-0002-open').click();
        const detail = page.getByTestId('admin-evidence-risk-review-detail');
        await expect(detail).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('admin-evidence-risk-review-detail-body')).toContainText('low-tier source');
        await page.getByTestId('admin-evidence-risk-review-detail-close').click();

        // Profiles tab — derived from the real /profiles API (R18).
        await page.getByTestId('admin-evidence-risk-review-nav-profiles').click();
        const profiles = page.getByTestId('admin-evidence-risk-review-profiles');
        await expect(profiles).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('admin-evidence-risk-review-profile-default')).toBeVisible();

        // Taxonomy tab — evidence tiers table from the real /taxonomy API.
        await page.getByTestId('admin-evidence-risk-review-nav-taxonomy').click();
        const taxonomy = page.getByTestId('admin-evidence-risk-review-taxonomy');
        await expect(taxonomy).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        await expect(page.getByTestId('admin-evidence-risk-review-taxonomy-tiers')).toBeVisible();
    });

    // R13: failure injection — stub the gated data probe to 503 so the host's
    // clean "unavailable" landing renders deterministically (R14: a downstream
    // outage degrades cleanly, never a crash). The happy path above exercises
    // real data.
    test('shows a clean unavailable landing when the data probe returns 503', async ({ page }) => {
        // R13: failure injection
        await page.route('**/api/admin/evidence-risk-review/reviews**', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({
                    status: 503,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'Service unavailable' }),
                });
            }
            return route.continue();
        });

        await page.goto('/app/admin/evidence-risk-review');

        const host = page.getByTestId('admin-evidence-risk-review-host');
        await expect(host).toHaveAttribute('data-state', 'unavailable', { timeout: 15_000 });
        await expect(page.getByTestId('admin-evidence-risk-review-unavailable')).toBeVisible();
        await expect(page.getByTestId('admin-evidence-risk-review-app')).toHaveCount(0);
    });
});
