import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v4.7/W3 — Admin Tabular Reviews scenarios.
 *
 * R13: every API surface is INTERNAL; seeded via DemoSeeder. ZERO
 * route stubs against /api/admin/tabular-reviews — the spec drives
 * the real Laravel controller end-to-end.
 *
 * The seeded admin user has the `admin` role so `viewTabularReviews`
 * is admitted. Cross-role denial scenarios (viewer → 403) belong in
 * `admin-tabular-reviews-viewer.spec.ts` under the chromium-viewer
 * project — out of scope for this W3 minimum-coverage spec.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Tabular Reviews (W3)', () => {
    test('admin lands on /app/admin/tabular-reviews and sees the list view shell', async ({ page }) => {
        await page.goto('/app/admin/tabular-reviews');
        const view = page.getByTestId('admin-tabular-reviews');
        await expect(view).toBeVisible({ timeout: 15_000 });
        // The view always renders in one of these states — never crashes.
        await expect(view).toHaveAttribute('data-state', /loading|ready|empty|error/);
    });

    test('clicking + New review opens the create dialog with proper ARIA', async ({ page }) => {
        await page.goto('/app/admin/tabular-reviews');
        await expect(page.getByTestId('admin-tabular-reviews')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('admin-tabular-reviews-create').click();
        const dialog = page.getByTestId('admin-tabular-review-create-dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('role', 'dialog');
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        // Submit is disabled until title + project are filled.
        await expect(page.getByTestId('admin-tabular-review-create-submit')).toBeDisabled();
    });

    test('full create → list → delete round-trip against the real backend', async ({ page }) => {
        await page.goto('/app/admin/tabular-reviews');
        await expect(page.getByTestId('admin-tabular-reviews')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('admin-tabular-reviews-create').click();
        await page.getByTestId('admin-tabular-review-create-title').fill('E2E review');
        await page.getByTestId('admin-tabular-review-create-project').fill('e2e-tabular');
        await page.getByTestId('admin-tabular-review-create-column-0-name').fill('Status');
        await page.getByTestId('admin-tabular-review-create-column-0-prompt').fill('What is the status?');

        const createPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/tabular-reviews') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-tabular-review-create-submit').click();
        const createResp = await createPost;
        if (!createResp.ok()) {
            throw new Error(
                `POST /api/admin/tabular-reviews returned non-OK: ${createResp.status()} ${await createResp.text()}`,
            );
        }
        const created = await createResp.json();
        const newId = created.data.id as number;

        // After create the FE navigates to the show page.
        const show = page.getByTestId('admin-tabular-review-show');
        await expect(show).toBeVisible({ timeout: 10_000 });
        await expect(show).toHaveAttribute('data-review-id', String(newId));
        // No cells yet — empty-state copy visible.
        await expect(page.getByTestId('admin-tabular-review-show-empty')).toBeVisible();

        // ── Back to list + delete row ─────────────────────────────────
        await page.getByTestId('admin-tabular-review-show-back').click();
        const row = page.getByTestId(`admin-tabular-review-row-${newId}`);
        await expect(row).toBeVisible({ timeout: 10_000 });

        const deleteCall = page.waitForResponse(
            (r) => r.url().endsWith(`/api/admin/tabular-reviews/${newId}`) && r.request().method() === 'DELETE',
            { timeout: 15_000 },
        );
        await page.getByTestId(`admin-tabular-review-row-${newId}-delete`).click();
        const deleteResp = await deleteCall;
        if (!deleteResp.ok()) {
            throw new Error(
                `DELETE /api/admin/tabular-reviews/${newId} returned non-OK: ${deleteResp.status()} ${await deleteResp.text()}`,
            );
        }

        // Row gone from the list.
        await expect(row).toBeHidden({ timeout: 10_000 });
    });

    test('FE submit-disabled guard keeps the dialog open when title is empty', async ({ page }) => {
        // FE-side guard: the Create button is disabled until BOTH
        // title and project_key carry non-empty trimmed strings. This
        // scenario asserts the disabled-button behaviour explicitly —
        // the actual 422 path (BE validation rejection) lands in
        // v4.7.x when the project_key dropdown wires
        // `/api/admin/projects/keys` per R18.
        await page.goto('/app/admin/tabular-reviews');
        await expect(page.getByTestId('admin-tabular-reviews')).toBeVisible({ timeout: 15_000 });
        await page.getByTestId('admin-tabular-reviews-create').click();

        // Fill project but NOT title; submit is disabled (FE guard).
        await page.getByTestId('admin-tabular-review-create-project').fill('e2e');
        await page.getByTestId('admin-tabular-review-create-column-0-name').fill('X');
        await expect(page.getByTestId('admin-tabular-review-create-submit')).toBeDisabled();
        // Dialog stays open.
        await expect(page.getByTestId('admin-tabular-review-create-dialog')).toBeVisible();
    });
});
