import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * PR7 — Phase F2. Admin Roles E2E scenarios.
 *
 * Runs under the admin storage state with the `seeded` auto-fixture.
 * Every scenario talks to the real backend — no internal-endpoint
 * interception is used.
 */

test.describe('Admin Roles', () => {
    test('happy — create role with permissions; user count is 0', async ({ page }) => {
        await page.goto('/app/admin/roles');

        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-roles')).toBeVisible();
        await expect(page.getByTestId('roles-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // Open the create dialog.
        await page.getByTestId('roles-new').click();
        await expect(page.getByTestId('role-dialog')).toBeVisible();
        await expect(page.getByTestId('role-dialog')).toHaveAttribute('data-mode', 'create');

        // Name + toggle one domain's permissions on.
        await page.getByTestId('role-dialog-name').fill('auditor');
        await page.getByTestId('role-perm-kb-toggle-all').click();

        // Save.
        await page.getByTestId('role-dialog-save').click();
        await expect(page.getByTestId('toast-role-created')).toBeVisible({ timeout: 10_000 });

        // Row lands in the table with user-count 0.
        await expect(page.getByTestId('roles-row-auditor')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('roles-row-auditor-user-count')).toHaveText('0');
    });

    test('happy — edit permission matrix persists across dialog reopen', async ({ page }) => {
        await page.goto('/app/admin/roles');
        await expect(page.getByTestId('roles-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // The editor role ships via RbacSeeder; open its dialog.
        await page.getByTestId('roles-row-editor-edit').click();
        await expect(page.getByTestId('role-dialog')).toHaveAttribute('data-mode', 'edit');

        // Toggle every kb.* permission ON (seeder already grants
        // kb.read.any + kb.edit.any + kb.promote.any but not
        // kb.delete.any — toggle-all normalises to "all on").
        const toggleAll = page.getByTestId('role-perm-kb-toggle-all');
        const initiallyAll = await toggleAll.getAttribute('data-active');
        // If the button already says "all on", force an off/on cycle so
        // the save diff is non-empty and the reopen assertion is
        // meaningful. Two clicks: off, back on.
        if (initiallyAll === 'true') {
            await toggleAll.click();
        }
        await toggleAll.click();

        await expect(toggleAll).toHaveAttribute('data-active', 'true');

        await page.getByTestId('role-dialog-save').click();
        await expect(page.getByTestId('toast-role-updated')).toBeVisible({ timeout: 10_000 });

        // Reopen — matrix reflects the saved state.
        await page.getByTestId('roles-row-editor-edit').click();
        await expect(page.getByTestId('role-perm-kb.read.any')).toHaveAttribute('data-active', 'true');
        await expect(page.getByTestId('role-perm-kb.edit.any')).toHaveAttribute('data-active', 'true');
        await expect(page.getByTestId('role-perm-kb.delete.any')).toHaveAttribute('data-active', 'true');
    });

    test('failure — delete super-admin blocked with system-role guard', async ({ page }) => {
        await page.goto('/app/admin/roles');
        await expect(page.getByTestId('roles-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // The delete button for super-admin is disabled by the UI, which
        // mirrors the 409 the backend would return. The assertion gates
        // on the guard surfacing to the operator.
        const superAdminDelete = page.getByTestId('roles-row-super-admin-delete');
        await expect(superAdminDelete).toBeDisabled();

        // The row carries the `data-protected` marker.
        await expect(page.getByTestId('roles-row-super-admin')).toHaveAttribute(
            'data-protected',
            'true',
        );
    });
});
