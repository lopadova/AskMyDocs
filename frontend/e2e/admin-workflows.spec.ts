import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v4.7/W3 — Admin Workflows scenarios.
 *
 * R13: every API surface is INTERNAL; ZERO route stubs against
 * /api/admin/workflows — the spec drives the real W2 controller
 * end-to-end. The seeded admin user has the `admin` role so
 * `viewWorkflows` is admitted.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Workflows (W3)', () => {
    test('admin lands on /app/admin/workflows and sees the list view', async ({ page }) => {
        await page.goto('/app/admin/workflows');
        const view = page.getByTestId('admin-workflows');
        await expect(view).toBeVisible({ timeout: 15_000 });
        await expect(view).toHaveAttribute('data-state', /loading|ready|empty|error/);
    });

    test('scope tabs are present and toggleable', async ({ page }) => {
        await page.goto('/app/admin/workflows');
        await expect(page.getByTestId('admin-workflows')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-workflows-scope-mine')).toBeVisible();
        await expect(page.getByTestId('admin-workflows-scope-shared')).toBeVisible();
        await expect(page.getByTestId('admin-workflows-scope-system')).toBeVisible();

        await page.getByTestId('admin-workflows-scope-system').click();
        await expect(page.getByTestId('admin-workflows-scope-system')).toHaveAttribute('data-active', 'true');
    });

    test('full create round-trip — assistant workflow', async ({ page }) => {
        await page.goto('/app/admin/workflows');
        await expect(page.getByTestId('admin-workflows')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('admin-workflows-create').click();
        const dialog = page.getByTestId('admin-workflow-create-dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('role', 'dialog');

        await page.getByTestId('admin-workflow-create-title').fill('E2E assistant WF');
        await page.getByTestId('admin-workflow-create-prompt').fill('You are a helpful E2E assistant.');

        const createPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/workflows') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-workflow-create-submit').click();
        const createResp = await createPost;
        if (!createResp.ok()) {
            throw new Error(
                `POST /api/admin/workflows returned non-OK: ${createResp.status()} ${await createResp.text()}`,
            );
        }
        const created = await createResp.json();
        const newId = created.data.id as number;

        // Card appears in the Mine list.
        const card = page.getByTestId(`admin-workflow-card-${newId}`);
        await expect(card).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId(`admin-workflow-card-${newId}-type`)).toHaveText('assistant');
    });

    test('submit is disabled until title + prompt are non-empty', async ({ page }) => {
        await page.goto('/app/admin/workflows');
        await expect(page.getByTestId('admin-workflows')).toBeVisible({ timeout: 15_000 });
        await page.getByTestId('admin-workflows-create').click();
        await expect(page.getByTestId('admin-workflow-create-submit')).toBeDisabled();

        await page.getByTestId('admin-workflow-create-title').fill('Only title');
        await expect(page.getByTestId('admin-workflow-create-submit')).toBeDisabled();

        await page.getByTestId('admin-workflow-create-prompt').fill('Now we have prompt');
        await expect(page.getByTestId('admin-workflow-create-submit')).toBeEnabled();
    });
});
