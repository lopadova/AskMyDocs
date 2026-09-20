import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.37/W3c — Admin KB Review tab (Digitization Review, ADR 0031).
 * Runs against the REAL backend seeded by DemoSeeder, with
 * KB_DIGITIZATION_REVIEW_ENABLED forced on for this suite
 * (playwright.config.ts / tests.yml, mirrors the KB_GAMIFICATION_AI_ENABLED
 * precedent). The default-OFF "disabled" landing is covered instead by
 * ReviewTab.test.tsx (Vitest), per R43 — flipping the flag here would
 * make the OFF-path unreachable in this suite.
 *
 * DemoSeeder's canonical documents (upsertDoc()) don't carry
 * `metadata.converter.page_count`, so every seeded document legitimately
 * hits the "no recorded page count" branch — this is real backend
 * behaviour, not a stub, and it's exactly the state most production
 * documents (never OCR/PDF-converted) are actually in. Per-page
 * navigation against a page-counted document, and the correction-
 * candidate approve/reject flow WITH seeded pending candidates, need a
 * dedicated fixture/seeder this PR does not add — both are covered at
 * the Vitest level (ReviewTab.test.tsx drives the full mutate() call
 * for every action with a mocked hook) and are left as e2e follow-up.
 *
 * R13 compliance: the failure-injection scenario below is the ONLY
 * place this file intercepts an internal route, and it carries the
 * required marker comment.
 */

test.describe('Admin KB Review tab', () => {
    test('happy — review tab reaches ready state and reports no recorded page count', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByTestId('kb-tree-node-policies/remote-work-policy.md').click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });

        await page.getByTestId('kb-tab-review').click();
        await expect(page.getByTestId('kb-review')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // No metadata.converter.page_count on this seeded document —
        // per-page review is genuinely unavailable, not a fake gate.
        await expect(page.getByTestId('kb-review-no-pages')).toBeVisible();
        await expect(page.getByTestId('kb-review-page-nav')).toHaveCount(0);

        // The pending-corrections queue is real too: zero seeded
        // candidates for this document.
        await expect(page.getByTestId('kb-review-corrections-empty')).toBeVisible({ timeout: 10_000 });
    });

    test('happy — Approve document round-trips through the real endpoint', async ({ page }) => {
        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await page.getByTestId('kb-tree-node-policies/remote-work-policy.md').click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });
        await page.getByTestId('kb-tab-review').click();
        await expect(page.getByTestId('kb-review')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        const approveBtn = page.getByTestId('kb-review-approve');
        await expect(approveBtn).toBeEnabled();
        await approveBtn.click();

        // Whether this seeded, already-canonical document ends up
        // approved or refused with a reason is a real decided OUTCOME
        // of KbReviewService::approve() (covered by its own PHPUnit
        // suite) — this scenario proves the round-trip renders SOME
        // result without crashing, not which business outcome fires.
        await expect(page.getByTestId('kb-review-approve-result')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('kb-review-approve-result')).not.toHaveText('');
    });

    test('failure injection — review-summary 500 surfaces kb-review-error', async ({ page }) => {
        // R13: failure injection — the real path is tested in the
        // happy scenario above. request interception against
        // internal GET /review-summary.
        await page.route('**/api/admin/kb/documents/*/review-summary', (route) => {
            if (route.request().method() === 'GET') {
                return route.fulfill({
                    status: 500,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'synthetic review-summary failure' }),
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

        await page.getByTestId('kb-tab-review').click();
        await expect(page.getByTestId('kb-review')).toHaveAttribute('data-state', 'error', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('kb-review-error')).toBeVisible();
    });
});
