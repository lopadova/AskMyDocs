import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * Per-project membership assignment UX (v8.8 security review).
 *
 * The membership editor lives on the third tab of the user drawer
 * (Profile / Roles / Project memberships). The project picker derives
 * from GET /api/admin/kb/projects (R18) — DemoSeeder seeds
 * knowledge_documents under `hr-portal` and `engineering`, so both keys
 * are offered.
 *
 * R13 compliance: the happy path runs entirely against the REAL backend
 * seeded with DemoSeeder. The only stubbed scenario (the failure path)
 * carries the `R13: failure injection` marker — the membership store
 * endpoint is an UPSERT, so a "duplicate" POST returns 200 not 422 and
 * the UI also removes already-assigned keys from the picker, leaving no
 * organic real-backend 4xx to drive a DOM error; injecting a 500 on the
 * internal route is therefore the faithful way to prove the error toast
 * surfaces.
 *
 * Each scenario creates a throwaway user via the API so the seeded
 * demo accounts (which already carry hr-portal + engineering
 * memberships) are never mutated and the picker starts non-empty.
 */

async function createThrowawayUser(
    request: import('@playwright/test').APIRequestContext,
    email: string,
): Promise<void> {
    const csrf = await request.get('/sanctum/csrf-cookie');
    expect(csrf.ok()).toBeTruthy();
    const create = await request.post('/api/admin/users', {
        data: {
            name: 'Membership Target',
            email,
            password: 'P@ssw0rd-Membership-1',
            roles: ['viewer'],
        },
    });
    expect(create.status()).toBe(201);
}

async function openMembershipsTab(
    page: import('@playwright/test').Page,
    email: string,
): Promise<void> {
    await page.goto('/app/admin/users');
    await expect(page.getByTestId('users-table')).toHaveAttribute('data-state', 'ready', {
        timeout: 15_000,
    });

    await page.getByTestId('users-filter-q').fill(email);
    const row = page.locator('tbody tr', { hasText: email });
    await expect(row).toBeVisible({ timeout: 10_000 });

    await row.locator('[data-testid^="users-row-"][data-testid$="-edit"]').click();
    await expect(page.getByTestId('user-drawer')).toBeVisible();
    await expect(page.getByTestId('user-drawer')).toHaveAttribute('data-mode', 'edit');

    await page.getByTestId('user-drawer-tab-memberships').click();
    await expect(page.getByTestId('membership-editor')).toHaveAttribute('data-state', 'ready', {
        timeout: 10_000,
    });
}

test.describe('Admin Project Membership', () => {
    test('happy — assign two memberships from the real project list, then remove one', async ({
        page,
        request,
    }) => {
        const email = 'membership-happy@demo.local';
        await createThrowawayUser(request, email);
        await openMembershipsTab(page, email);

        // Fresh user: no memberships yet.
        await expect(page.getByTestId('memberships-empty')).toBeVisible();

        // Assign hr-portal.
        await page.getByTestId('membership-add').click();
        await page.getByTestId('membership-add-project').selectOption('hr-portal');
        await page.getByTestId('membership-add-role').selectOption('member');
        await page.getByTestId('membership-add-save').click();
        await expect(page.getByTestId('membership-hr-portal')).toBeVisible({ timeout: 10_000 });

        // Assign engineering — the picker now only offers the remaining key.
        await page.getByTestId('membership-add').click();
        await page.getByTestId('membership-add-project').selectOption('engineering');
        await page.getByTestId('membership-add-role').selectOption('admin');
        await page.getByTestId('membership-add-save').click();
        await expect(page.getByTestId('membership-engineering')).toBeVisible({ timeout: 10_000 });

        // Both rows present.
        await expect(page.getByTestId('membership-hr-portal')).toBeVisible();
        await expect(page.getByTestId('membership-engineering')).toBeVisible();

        // Remove hr-portal; it disappears, engineering stays.
        await page.getByTestId('membership-hr-portal-delete').click();
        await expect(page.getByTestId('membership-hr-portal')).toHaveCount(0, { timeout: 10_000 });
        await expect(page.getByTestId('membership-engineering')).toBeVisible();
    });

    test('failure — backend rejection surfaces the membership error toast', async ({
        page,
        request,
    }) => {
        const email = 'membership-fail@demo.local';
        await createThrowawayUser(request, email);

        /* R13: failure injection — the membership store endpoint upserts
         * (no organic 422 on duplicate) and the picker hides already-assigned
         * keys, so a real-backend error cannot be provoked through the UI.
         * Inject a 500 on the internal POST to prove the error toast
         * surfaces. The happy-path test above exercises the real-data flow. */
        await page.route('**/api/admin/users/*/memberships', (route) => {
            if (route.request().method() === 'POST') {
                return route.fulfill({
                    status: 500,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'Server error' }),
                });
            }
            return route.continue();
        });

        await openMembershipsTab(page, email);
        await expect(page.getByTestId('memberships-empty')).toBeVisible();

        await page.getByTestId('membership-add').click();
        await page.getByTestId('membership-add-project').selectOption('hr-portal');
        await page.getByTestId('membership-add-save').click();

        // The error toast appears with the documented testid; no row added.
        await expect(page.getByTestId('toast-membership-error')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('membership-hr-portal')).toHaveCount(0);
    });
});
