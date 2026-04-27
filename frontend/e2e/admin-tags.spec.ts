import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * T2.10 — Admin Tags CRUD scenarios.
 *
 * R13: every API surface this scenario hits is INTERNAL and seeded by
 * the DemoSeeder + the test's own setup steps. ZERO route stubs;
 * the spec exercises the real Laravel app end-to-end.
 *
 * The seeded admin user has the `admin` role, so the route guard
 * passes. Cross-role denial scenarios (viewer → 403) belong in
 * admin-tags-viewer.spec.ts and run under the chromium-viewer project.
 */

// Per-test timeout bumped from 20s default — slow seeded fixture
// under local php -S + SQLite, fast under CI's Postgres. Admin Tags
// scenarios run a multi-step CRUD flow that compounds the latency.
test.describe.configure({ timeout: 90_000 });

test.describe('Admin Tags CRUD', () => {
    test('admin lands on /app/admin/kb/tags and sees the empty state on a fresh seed', async ({ page }) => {
        await page.goto('/app/admin/kb/tags');
        await expect(page.getByTestId('admin-tags-view')).toBeVisible({ timeout: 15_000 });
        // A fresh DemoSeeder may or may not seed tags depending on the
        // build; either branch is acceptable. Assert on the view, not
        // the data shape.
        const empty = page.getByTestId('admin-tags-empty');
        const table = page.getByTestId('admin-tags-table');
        // One of these must be visible — the view never crashes.
        await expect(empty.or(table)).toBeVisible({ timeout: 15_000 });
    });

    test('clicking + New tag opens the create dialog with proper ARIA + role', async ({ page }) => {
        await page.goto('/app/admin/kb/tags');
        await page.getByTestId('admin-tags-create').click();
        const dialog = page.getByTestId('admin-tag-form');
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('data-mode', 'create');
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        await expect(dialog).toHaveAttribute('role', 'dialog');
    });

    test('full create → edit → delete round-trip against the real backend', async ({ page }) => {
        await page.goto('/app/admin/kb/tags');

        // ─── CREATE ────────────────────────────────────────────────
        await page.getByTestId('admin-tags-create').click();
        await page.getByTestId('admin-tag-form-project').fill('e2e-project');
        await page.getByTestId('admin-tag-form-slug').fill('e2e-slug');
        await page.getByTestId('admin-tag-form-label').fill('E2E Label');
        await page.getByTestId('admin-tag-form-color-text').fill('#abcdef');

        const createPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/kb/tags') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-tag-form-submit').click();
        const createResp = await createPost;
        if (!createResp.ok()) {
            throw new Error(
                `POST /api/admin/kb/tags returned non-OK: ${createResp.status()} ${await createResp.text()}`,
            );
        }
        const created = await createResp.json();
        const newId = created.data.id as number;

        // Row appears in the list.
        const newRow = page.getByTestId(`admin-tag-row-${newId}`);
        await expect(newRow).toBeVisible({ timeout: 10_000 });
        await expect(newRow).toHaveAttribute('data-tag-slug', 'e2e-slug');
        await expect(newRow).toHaveAttribute('data-tag-project', 'e2e-project');

        // ─── EDIT ──────────────────────────────────────────────────
        await page.getByTestId(`admin-tag-row-${newId}-edit`).click();
        const labelField = page.getByTestId('admin-tag-form-label');
        await labelField.clear();
        await labelField.fill('E2E Label (renamed)');
        const editPut = page.waitForResponse(
            (r) => r.url().endsWith(`/api/admin/kb/tags/${newId}`) && r.request().method() === 'PUT',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-tag-form-submit').click();
        const editResp = await editPut;
        if (!editResp.ok()) {
            throw new Error(
                `PUT /api/admin/kb/tags/${newId} returned non-OK: ${editResp.status()} ${await editResp.text()}`,
            );
        }

        // Row reflects the new label after the cache invalidates.
        await expect(newRow).toContainText('E2E Label (renamed)', { timeout: 10_000 });

        // ─── DELETE (with confirm step) ───────────────────────────
        await page.getByTestId(`admin-tag-row-${newId}-delete`).click();
        // Confirm + cancel buttons appear inline; no DELETE issued yet.
        await expect(page.getByTestId(`admin-tag-row-${newId}-delete-confirm`)).toBeVisible();

        const deleteCall = page.waitForResponse(
            (r) => r.url().endsWith(`/api/admin/kb/tags/${newId}`) && r.request().method() === 'DELETE',
            { timeout: 15_000 },
        );
        await page.getByTestId(`admin-tag-row-${newId}-delete-confirm`).click();
        const deleteResp = await deleteCall;
        if (!deleteResp.ok()) {
            throw new Error(
                `DELETE /api/admin/kb/tags/${newId} returned non-OK: ${deleteResp.status()} ${await deleteResp.text()}`,
            );
        }

        // Row gone after the list refetches.
        await expect(newRow).not.toBeVisible({ timeout: 10_000 });
    });

    test('cancelling a delete confirmation reverts the row to its non-confirming state', async ({ page }) => {
        await page.goto('/app/admin/kb/tags');

        // Seed a tag inline so we have something to delete.
        await page.getByTestId('admin-tags-create').click();
        await page.getByTestId('admin-tag-form-project').fill('cancel-test');
        await page.getByTestId('admin-tag-form-slug').fill('cancel-slug');
        await page.getByTestId('admin-tag-form-label').fill('Cancel Test');

        const createPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/kb/tags') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-tag-form-submit').click();
        const created = await (await createPost).json();
        const id = created.data.id as number;

        // Click delete then cancel — DELETE must NOT fire.
        await page.getByTestId(`admin-tag-row-${id}-delete`).click();
        await page.getByTestId(`admin-tag-row-${id}-delete-cancel`).click();
        // Edit + Delete buttons are visible again; Confirm is gone.
        await expect(page.getByTestId(`admin-tag-row-${id}-delete`)).toBeVisible();
        await expect(page.getByTestId(`admin-tag-row-${id}-delete-confirm`)).not.toBeVisible();
    });

    test('filter input narrows the visible rows by free-text match', async ({ page }) => {
        await page.goto('/app/admin/kb/tags');

        // Seed two tags to filter between.
        for (const [slug, label] of [['filter-alpha', 'Filter Alpha'], ['filter-beta', 'Filter Beta']]) {
            await page.getByTestId('admin-tags-create').click();
            await page.getByTestId('admin-tag-form-project').fill('filter-test');
            await page.getByTestId('admin-tag-form-slug').fill(slug);
            await page.getByTestId('admin-tag-form-label').fill(label);
            const create = page.waitForResponse(
                (r) => r.url().endsWith('/api/admin/kb/tags') && r.request().method() === 'POST',
                { timeout: 15_000 },
            );
            await page.getByTestId('admin-tag-form-submit').click();
            await create;
        }

        // Both rows visible by slug match — wait for refetch to populate.
        await expect(page.locator('[data-tag-slug="filter-alpha"]')).toBeVisible({ timeout: 10_000 });
        await expect(page.locator('[data-tag-slug="filter-beta"]')).toBeVisible();

        // Filter narrows to alpha only.
        await page.getByTestId('admin-tags-filter').fill('alpha');
        await expect(page.locator('[data-tag-slug="filter-alpha"]')).toBeVisible();
        await expect(page.locator('[data-tag-slug="filter-beta"]')).not.toBeVisible();
    });
});
