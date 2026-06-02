import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.7/W1 — Admin Synonyms CRUD scenarios.
 *
 * R13: every API surface this scenario hits is INTERNAL and exercised
 * against the real Laravel app (DemoSeeder + the test's own create
 * steps). ZERO route stubs. The 422 duplicate-term case is a real-data
 * validation assertion (it hits the real validator), NOT failure
 * injection — so no R13 marker is needed.
 *
 * The seeded admin user has the `admin` role, so the route guard passes.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Synonyms CRUD', () => {
    test('admin lands on /app/admin/kb/synonyms and the view never crashes', async ({ page }) => {
        await page.goto('/app/admin/kb/synonyms');
        await expect(page.getByTestId('admin-synonyms-view')).toBeVisible({ timeout: 15_000 });
        const empty = page.getByTestId('admin-synonyms-empty');
        const table = page.getByTestId('admin-synonyms-table');
        await expect(empty.or(table)).toBeVisible({ timeout: 15_000 });
    });

    test('clicking + New group opens the create dialog with proper ARIA + role', async ({ page }) => {
        await page.goto('/app/admin/kb/synonyms');
        await page.getByTestId('admin-synonyms-create').click();
        const dialog = page.getByTestId('admin-synonym-form');
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('data-mode', 'create');
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        await expect(dialog).toHaveAttribute('role', 'dialog');
    });

    test('full create → edit → delete round-trip against the real backend', async ({ page }) => {
        await page.goto('/app/admin/kb/synonyms');

        // ─── CREATE ────────────────────────────────────────────────
        await page.getByTestId('admin-synonyms-create').click();
        await page.getByTestId('admin-synonym-form-project').fill('e2e-syn-project');
        await page.getByTestId('admin-synonym-form-term').fill('k8s');
        await page.getByTestId('admin-synonym-form-synonyms').fill('kubernetes\ncontainer orchestration');

        const createPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/kb/synonyms') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-synonym-form-submit').click();
        const createResp = await createPost;
        if (!createResp.ok()) {
            throw new Error(
                `POST /api/admin/kb/synonyms returned non-OK: ${createResp.status()} ${await createResp.text()}`,
            );
        }
        const created = await createResp.json();
        const newId = created.data.id as number;

        const newRow = page.getByTestId(`admin-synonym-row-${newId}`);
        await expect(newRow).toBeVisible({ timeout: 10_000 });
        await expect(newRow).toHaveAttribute('data-synonym-term', 'k8s');
        await expect(newRow).toContainText('kubernetes');

        // ─── EDIT (append a synonym) ───────────────────────────────
        await page.getByTestId(`admin-synonym-row-${newId}-edit`).click();
        await page.getByTestId('admin-synonym-form-synonyms').fill('kubernetes\ncontainer orchestration\nk8s cluster');
        const editPut = page.waitForResponse(
            (r) => r.url().endsWith(`/api/admin/kb/synonyms/${newId}`) && r.request().method() === 'PUT',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-synonym-form-submit').click();
        const editResp = await editPut;
        if (!editResp.ok()) {
            throw new Error(
                `PUT /api/admin/kb/synonyms/${newId} returned non-OK: ${editResp.status()} ${await editResp.text()}`,
            );
        }
        await expect(newRow).toContainText('k8s cluster', { timeout: 10_000 });

        // ─── DELETE (with confirm step) ───────────────────────────
        await page.getByTestId(`admin-synonym-row-${newId}-delete`).click();
        await expect(page.getByTestId(`admin-synonym-row-${newId}-delete-confirm`)).toBeVisible();

        const deleteCall = page.waitForResponse(
            (r) => r.url().endsWith(`/api/admin/kb/synonyms/${newId}`) && r.request().method() === 'DELETE',
            { timeout: 15_000 },
        );
        await page.getByTestId(`admin-synonym-row-${newId}-delete-confirm`).click();
        const deleteResp = await deleteCall;
        if (!deleteResp.ok()) {
            throw new Error(
                `DELETE /api/admin/kb/synonyms/${newId} returned non-OK: ${deleteResp.status()} ${await deleteResp.text()}`,
            );
        }
        await expect(newRow).not.toBeVisible({ timeout: 10_000 });
    });

    test('duplicate term in the same project surfaces a 422 error in the dialog', async ({ page }) => {
        await page.goto('/app/admin/kb/synonyms');

        // First create succeeds.
        await page.getByTestId('admin-synonyms-create').click();
        await page.getByTestId('admin-synonym-form-project').fill('dup-syn-project');
        await page.getByTestId('admin-synonym-form-term').fill('ci');
        await page.getByTestId('admin-synonym-form-synonyms').fill('continuous integration');
        const firstPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/kb/synonyms') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-synonym-form-submit').click();
        await firstPost;

        // Second create with the same (project, term) must 422 and the
        // error must surface in the dialog (R14 — no silent failure).
        await page.getByTestId('admin-synonyms-create').click();
        await page.getByTestId('admin-synonym-form-project').fill('dup-syn-project');
        await page.getByTestId('admin-synonym-form-term').fill('ci');
        await page.getByTestId('admin-synonym-form-synonyms').fill('build pipeline');
        const dupPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/kb/synonyms') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-synonym-form-submit').click();
        const dupResp = await dupPost;
        expect(dupResp.status()).toBe(422);
        await expect(page.getByTestId('admin-synonym-form-error')).toBeVisible({ timeout: 10_000 });
    });
});
