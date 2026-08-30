import { expect } from '@playwright/test';
import { test } from './fixtures';
import { seedDb } from './setup-helpers';

/*
 * v8.33 / ADR 0028 phase 2 — Source Permissions admin screen.
 *
 * R13: the happy path runs against the REAL `/api/admin/kb/source-acl`
 * endpoint backed by the real DB (`SourceAclTriageSeeder` marks a document as
 * governed by its source and queues three principals it names that cannot be
 * matched to an internal subject). The dismiss flow exercises the real PATCH.
 * The failure path injects a 503 on the GET.
 */

test.describe.configure({ timeout: 90_000 });

test.describe('Admin Source Permissions', () => {
    test('lists unrecognised principals and dismisses one (real data)', async ({ page, request }) => {
        await seedDb(request, 'SourceAclTriageSeeder');

        await page.goto('/app/admin/kb/source-acl');
        await expect(page.getByTestId('admin-source-acl-view')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // The count of documents whose readers the source dictates is the
        // headline fact of the screen, and is independent of the queue.
        await expect(page.getByTestId('admin-source-acl-restricted')).toHaveText('1', { timeout: 15_000 });

        const table = page.getByTestId('admin-source-acl-table');
        await expect(table).toContainText('contractor@agency.example');
        await expect(table).toContainText('board-members');
        // The source's own vocabulary is preserved rather than mapped onto an
        // internal subject type, because no such mapping was found.
        await expect(table).toContainText('Group');

        const patchResp = page.waitForResponse(
            (r) => /\/api\/admin\/kb\/source-acl\/\d+$/.test(r.url()) && r.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await page.getByRole('button', { name: /dismiss/i }).first().click();
        const resp = await patchResp;
        expect(resp.ok()).toBeTruthy();

        // The dismissed principal leaves the pending queue.
        await expect(page.getByTestId('admin-source-acl-pending')).toHaveText('2', { timeout: 15_000 });
    });

    test('keeps dismissed entries reachable rather than deleting the decision', async ({ page, request }) => {
        // A decision taken once must stay visible and reversible; otherwise an
        // operator has no way to check or undo what was dismissed.
        await seedDb(request, 'SourceAclTriageSeeder');

        await page.goto('/app/admin/kb/source-acl');
        await expect(page.getByTestId('admin-source-acl-view')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByRole('button', { name: /dismiss/i }).first().click();

        await page.getByTestId('admin-source-acl-status-filter').selectOption('ignored');

        await expect(page.getByTestId('admin-source-acl-table')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByRole('button', { name: /reopen/i }).first()).toBeVisible();
    });

    // R13: failure injection — stubs the GET to 503 so the error branch
    // renders deterministically. The happy path above exercises real data.
    test('shows the error state when the endpoint returns 503', async ({ page }) => {
        await page.route('**/api/admin/kb/source-acl*', (route) =>
            route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"down"}' }),
        );

        await page.goto('/app/admin/kb/source-acl');

        await expect(page.getByTestId('admin-source-acl-view')).toHaveAttribute('data-state', 'error', {
            timeout: 15_000,
        });
        // An outage must not read as "no permission problems" (R14).
        await expect(page.getByTestId('admin-source-acl-empty')).toHaveCount(0);
    });
});
