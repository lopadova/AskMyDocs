import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.9 — Admin Projects (project registry) CRUD scenarios.
 *
 * R13: every API surface is INTERNAL and seeded by DemoSeeder + the
 * test's own steps. ZERO route stubs — real Laravel app end-to-end.
 *
 * DemoSeeder seeds two default-tenant projects (hr-portal, engineering);
 * hr-portal carries documents, so it cannot be deleted (delete-in-use
 * 422). The admin user has the `admin` role; viewer denial lives in
 * admin-projects-viewer.spec.ts under the chromium-viewer project.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Projects CRUD', () => {
    test('admin lands on the projects page and sees the seeded rows', async ({ page }) => {
        await page.goto('/app/admin/projects');
        await expect(page.getByTestId('admin-projects-view')).toBeVisible({ timeout: 15_000 });
        // The URL carries the team hash (per-team routing).
        await expect(page).toHaveURL(/\/admin\/projects$/);
        const table = page.getByTestId('admin-projects-table');
        await expect(table).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-project-key="hr-portal"]')).toBeVisible();
        await expect(page.locator('[data-project-key="engineering"]')).toBeVisible();
    });

    test('the create dialog auto-slugs the key from the name', async ({ page }) => {
        await page.goto('/app/admin/projects');
        await page.getByTestId('admin-projects-create').click();

        const dialog = page.getByTestId('admin-project-form');
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('data-mode', 'create');
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        await expect(dialog).toHaveAttribute('role', 'dialog');

        await page.getByTestId('admin-project-form-name').fill('Surface KB');
        await expect(page.getByTestId('admin-project-form-key')).toHaveValue('surface-kb');
    });

    test('full create → edit → delete round-trip against the real backend', async ({ page }) => {
        await page.goto('/app/admin/projects');

        // ─── CREATE ────────────────────────────────────────────────
        await page.getByTestId('admin-projects-create').click();
        await page.getByTestId('admin-project-form-name').fill('E2E Project');
        // Key auto-fills to 'e2e-project'.
        await page.getByTestId('admin-project-form-description').fill('Created by E2E.');

        const createPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/projects') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-project-form-submit').click();
        const createResp = await createPost;
        if (!createResp.ok()) {
            throw new Error(
                `POST /api/admin/projects returned non-OK: ${createResp.status()} ${await createResp.text()}`,
            );
        }
        const created = await createResp.json();
        const newId = created.data.id as number;
        expect(created.data.project_key).toBe('e2e-project');

        const newRow = page.getByTestId(`admin-project-row-${newId}`);
        await expect(newRow).toBeVisible({ timeout: 10_000 });
        await expect(newRow).toHaveAttribute('data-project-key', 'e2e-project');

        // ─── EDIT ──────────────────────────────────────────────────
        await page.getByTestId(`admin-project-row-${newId}-edit`).click();
        const nameField = page.getByTestId('admin-project-form-name');
        await nameField.clear();
        await nameField.fill('E2E Project (renamed)');
        // The key field is read-only in edit mode.
        await expect(page.getByTestId('admin-project-form-key')).toHaveAttribute('readonly', '');

        const editPatch = page.waitForResponse(
            (r) => r.url().endsWith(`/api/admin/projects/${newId}`) && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-project-form-submit').click();
        const editResp = await editPatch;
        if (!editResp.ok()) {
            throw new Error(
                `PATCH /api/admin/projects/${newId} returned non-OK: ${editResp.status()} ${await editResp.text()}`,
            );
        }
        await expect(newRow).toContainText('E2E Project (renamed)', { timeout: 10_000 });

        // ─── DELETE (unused → succeeds) ───────────────────────────
        await page.getByTestId(`admin-project-row-${newId}-delete`).click();
        await expect(page.getByTestId(`admin-project-row-${newId}-delete-confirm`)).toBeVisible();

        const deleteCall = page.waitForResponse(
            (r) => r.url().endsWith(`/api/admin/projects/${newId}`) && r.request().method() === 'DELETE',
            { timeout: 15_000 },
        );
        await page.getByTestId(`admin-project-row-${newId}-delete-confirm`).click();
        const deleteResp = await deleteCall;
        if (!deleteResp.ok()) {
            throw new Error(
                `DELETE /api/admin/projects/${newId} returned non-OK: ${deleteResp.status()} ${await deleteResp.text()}`,
            );
        }
        await expect(newRow).not.toBeVisible({ timeout: 10_000 });
    });

    test('deleting a project that still has documents is blocked with a 422 error', async ({ page }) => {
        await page.goto('/app/admin/projects');

        // hr-portal is seeded WITH documents → the BE refuses the delete.
        const row = page.locator('[data-project-key="hr-portal"]');
        await expect(row).toBeVisible({ timeout: 15_000 });

        await row.getByRole('button', { name: /Delete project/i }).click();
        const deleteCall = page.waitForResponse(
            (r) => /\/api\/admin\/projects\/\d+$/.test(r.url()) && r.request().method() === 'DELETE',
            { timeout: 15_000 },
        );
        await row.getByTestId(/delete-confirm$/).click();
        const resp = await deleteCall;
        expect(resp.status()).toBe(422);

        // The row stays and an inline error explains why.
        await expect(row).toBeVisible();
        await expect(row.getByTestId(/-error$/)).toContainText(/Cannot delete/i, { timeout: 10_000 });
    });

    test('filter input narrows the visible rows by free-text match', async ({ page }) => {
        await page.goto('/app/admin/projects');
        await expect(page.locator('[data-project-key="hr-portal"]')).toBeVisible({ timeout: 15_000 });
        await expect(page.locator('[data-project-key="engineering"]')).toBeVisible();

        await page.getByTestId('admin-projects-filter').fill('engineering');
        await expect(page.locator('[data-project-key="engineering"]')).toBeVisible();
        await expect(page.locator('[data-project-key="hr-portal"]')).not.toBeVisible();
    });
});
