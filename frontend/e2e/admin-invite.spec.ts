import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * Admin Invite scenarios — campaigns, codes, metrics, invitations.
 *
 * R13: every API surface here is INTERNAL and seeded by DemoSeeder + the
 * test's own steps. ZERO route stubs — the spec drives the real Laravel app
 * end-to-end. The seeded admin user has the `admin` role, so the route guard
 * passes (cross-role denial lives in the role-access matrix specs).
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Invite', () => {
    test('admin lands on /app/admin/invite and the view renders (empty or table)', async ({ page }) => {
        await page.goto('/app/admin/invite');
        await expect(page.getByTestId('admin-invite-view')).toBeVisible({ timeout: 15_000 });

        const empty = page.getByTestId('admin-invite-empty');
        const table = page.getByTestId('admin-invite-table');
        await expect(empty.or(table)).toBeVisible({ timeout: 15_000 });
    });

    test('+ New campaign opens the create dialog with proper ARIA + role', async ({ page }) => {
        await page.goto('/app/admin/invite');
        await page.getByTestId('admin-invite-create').click();

        const dialog = page.getByTestId('admin-invite-form');
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('data-mode', 'create');
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        await expect(dialog).toHaveAttribute('role', 'dialog');
    });

    test('create → edit campaign round-trip against the real backend', async ({ page }) => {
        await page.goto('/app/admin/invite');

        // CREATE
        await page.getByTestId('admin-invite-create').click();
        await page.getByTestId('admin-invite-form-key').fill('e2e-camp');
        await page.getByTestId('admin-invite-form-name').fill('E2E Campaign');

        const createPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/invite/campaigns') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-invite-form-submit').click();
        const createResp = await createPost;
        if (!createResp.ok()) {
            throw new Error(`POST campaigns failed: ${createResp.status()} ${await createResp.text()}`);
        }
        const id = (await createResp.json()).data.id as number;

        const row = page.getByTestId(`admin-invite-campaign-row-${id}`);
        await expect(row).toBeVisible({ timeout: 10_000 });
        await expect(row).toContainText('E2E Campaign');

        // EDIT — flip status to active
        await page.getByTestId(`admin-invite-campaign-row-${id}-edit`).click();
        await page.getByTestId('admin-invite-form-status').selectOption('active');

        const editPatch = page.waitForResponse(
            (r) => r.url().endsWith(`/api/admin/invite/campaigns/${id}`) && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-invite-form-submit').click();
        const editResp = await editPatch;
        if (!editResp.ok()) {
            throw new Error(`PATCH campaign failed: ${editResp.status()} ${await editResp.text()}`);
        }

        await expect(page.getByTestId(`admin-invite-campaign-row-${id}-status`)).toHaveText('active', { timeout: 10_000 });
    });

    test('generate codes → minted codes appear and list refreshes', async ({ page }) => {
        await page.goto('/app/admin/invite');
        await page.getByTestId('admin-invite-tab-codes').click();
        await expect(page.getByTestId('admin-invite-codes')).toBeVisible();

        await page.getByTestId('admin-invite-codes-generate').click();
        await page.getByTestId('admin-invite-codes-form-count').fill('3');

        const genPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/invite/codes') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-invite-codes-form-submit').click();
        const genResp = await genPost;
        if (!genResp.ok()) {
            throw new Error(`POST codes failed: ${genResp.status()} ${await genResp.text()}`);
        }

        // Minted codes shown in the dialog, then the underlying list has rows.
        await expect(page.getByTestId('admin-invite-codes-form-result')).toHaveAttribute('data-state', 'ready');
        await page.getByTestId('admin-invite-codes-form-close').click();
        await expect(page.getByTestId('admin-invite-codes-table')).toHaveAttribute('data-state', 'ready', { timeout: 10_000 });
    });

    // R13: failure injection against an INTERNAL route is permitted because the
    // happy-path variants above already cover the real-data flow. This one
    // provokes a REAL 422 (duplicate campaign key) — no stub.
    test('duplicate campaign key surfaces the server validation error', async ({ page }) => {
        await page.goto('/app/admin/invite');

        // First create succeeds.
        await page.getByTestId('admin-invite-create').click();
        await page.getByTestId('admin-invite-form-key').fill('dup-key');
        await page.getByTestId('admin-invite-form-name').fill('First');
        await page.getByTestId('admin-invite-form-submit').click();
        await expect(page.getByTestId('admin-invite-form')).toBeHidden({ timeout: 10_000 });

        // Second create with the SAME key → 422 → inline error, dialog stays open.
        await page.getByTestId('admin-invite-create').click();
        await page.getByTestId('admin-invite-form-key').fill('dup-key');
        await page.getByTestId('admin-invite-form-name').fill('Second');
        await page.getByTestId('admin-invite-form-submit').click();

        await expect(page.getByTestId('admin-invite-form-error')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('admin-invite-form')).toBeVisible();
    });
});
