import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.28 — Admin Teams (= tenants) create + rename scenarios.
 *
 * R13: every API surface is INTERNAL and seeded by DemoSeeder + the test's
 * own steps. ZERO route stubs — real Laravel app end-to-end.
 *
 * DemoSeeder seeds two operational tenants: `a-demo` ("Demo Company") and
 * `acme` ("Acme Corp"); admin@demo.local is a member of BOTH, so the
 * Teams page lists both and both are manageable.
 * The create attaches the acting admin as a member, so a fresh team appears
 * both in the table AND in the topbar team switcher.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Teams create + rename', () => {
    test('admin lands on the teams page and sees the seeded teams', async ({ page }) => {
        await page.goto('/app/admin/teams');
        await expect(page.getByTestId('admin-teams-view')).toBeVisible({ timeout: 15_000 });
        await expect(page).toHaveURL(/\/admin\/teams$/);

        await expect(page.getByTestId('admin-teams-table')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-team-row-acme')).toBeVisible();
        await expect(page.getByTestId('admin-team-row-a-demo')).toBeVisible();

        // Both rows represent real memberships, so both expose Rename.
        await expect(page.getByTestId('admin-team-row-acme-edit')).toBeVisible();
        await expect(page.getByTestId('admin-team-row-a-demo-edit')).toBeVisible();
        await expect(page.getByTestId('admin-team-row-default')).toHaveCount(0);
    });

    test('the create dialog auto-slugs the slug from the name', async ({ page }) => {
        await page.goto('/app/admin/teams');
        await page.getByTestId('admin-teams-create').click();

        const dialog = page.getByTestId('admin-team-form');
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('data-mode', 'create');
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        await expect(dialog).toHaveAttribute('role', 'dialog');

        await page.getByTestId('admin-team-form-name').fill('E2E Team');
        await expect(page.getByTestId('admin-team-form-slug')).toHaveValue('e2e-team');
    });

    test('create → rename round-trip, and the new team appears in the switcher', async ({ page }) => {
        await page.goto('/app/admin/teams');

        // ─── CREATE ────────────────────────────────────────────────
        await page.getByTestId('admin-teams-create').click();
        await page.getByTestId('admin-team-form-name').fill('E2E Team');
        // Slug auto-fills to 'e2e-team'.

        const createPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/teams') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-team-form-submit').click();
        const createResp = await createPost;
        if (!createResp.ok()) {
            throw new Error(
                `POST /api/admin/teams returned non-OK: ${createResp.status()} ${await createResp.text()}`,
            );
        }
        const created = await createResp.json();
        expect(created.data.slug).toBe('e2e-team');

        const newRow = page.getByTestId('admin-team-row-e2e-team');
        await expect(newRow).toBeVisible({ timeout: 10_000 });

        // ─── SWITCHER re-syncs (the acting admin got a membership) ──
        await page.getByTestId('team-switcher-trigger').click();
        await expect(page.getByTestId('team-switcher-item-e2e-team')).toBeVisible({ timeout: 10_000 });
        // Close the menu (Escape returns focus to the trigger).
        await page.keyboard.press('Escape');

        // ─── RENAME ────────────────────────────────────────────────
        await newRow.getByTestId('admin-team-row-e2e-team-edit').click();
        const nameField = page.getByTestId('admin-team-form-name');
        await nameField.clear();
        await nameField.fill('E2E Team Renamed');
        // The slug field is read-only in rename mode.
        await expect(page.getByTestId('admin-team-form-slug')).toHaveAttribute('readonly', '');

        const editPatch = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/teams/e2e-team') && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-team-form-submit').click();
        const editResp = await editPatch;
        if (!editResp.ok()) {
            throw new Error(
                `PATCH /api/admin/teams/e2e-team returned non-OK: ${editResp.status()} ${await editResp.text()}`,
            );
        }
        await expect(newRow).toContainText('E2E Team Renamed', { timeout: 10_000 });
    });

    test('creating a team whose slug already exists is blocked with a 422', async ({ page }) => {
        await page.goto('/app/admin/teams');

        await page.getByTestId('admin-teams-create').click();
        await page.getByTestId('admin-team-form-name').fill('Acme Two');
        // Force a collision with the seeded `acme` tenant.
        const slug = page.getByTestId('admin-team-form-slug');
        await slug.fill('acme');

        const createPost = page.waitForResponse(
            (r) => r.url().endsWith('/api/admin/teams') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('admin-team-form-submit').click();
        const resp = await createPost;
        expect(resp.status()).toBe(422);

        // The dialog stays open and an inline slug error explains why.
        await expect(page.getByTestId('admin-team-form')).toBeVisible();
        await expect(page.getByTestId('admin-team-form-slug-error')).toContainText(/already exists/i, {
            timeout: 10_000,
        });
    });

    test('filter input narrows the visible teams by free-text match', async ({ page }) => {
        await page.goto('/app/admin/teams');
        await expect(page.getByTestId('admin-team-row-acme')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-team-row-a-demo')).toBeVisible();

        await page.getByTestId('admin-teams-filter').fill('acme');
        await expect(page.getByTestId('admin-team-row-acme')).toBeVisible();
        await expect(page.getByTestId('admin-team-row-a-demo')).toHaveCount(0);
    });
});
