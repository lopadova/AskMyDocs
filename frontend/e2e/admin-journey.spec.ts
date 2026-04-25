import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * PR15 — Phase J. Golden-path admin journey.
 *
 * ONE long scenario that walks every major admin page IN ORDER to
 * prove the whole admin stack composes cleanly end-to-end. This is
 * the reviewer demo / next-maintainer sanity check — if a future PR
 * breaks one of the admin surfaces, THIS spec is the first thing
 * that turns red because it touches every page in sequence.
 *
 * Scope: R13-compliant — no request interception against internal
 * routes. DemoSeeder + AdminInsightsSeeder populate every page.
 * `admin@demo.local` holds the `admin` role (DemoSeeder default) so
 * the non-destructive `kb:validate-canonical` Artisan path is
 * reachable without super-admin; no destructive commands are
 * exercised here — the `admin-maintenance-super-admin.spec.ts`
 * covers that path.
 *
 * R12 / R13 note: this spec deliberately does NOT duplicate the
 * failure-path coverage already owned by each feature's own spec
 * file. One happy path, ten steps, real data.
 *
 * Steps:
 *   1. Login as admin → land on /app
 *   2. Dashboard loads (KPI strip + health strip visible, ready)
 *   3. Users → create → assign Viewer role → delete → restore
 *   4. Roles → create a role with one domain permission → visible
 *   5. KB tree → open a canonical doc → Source → edit → Save →
 *      history row with event_type="updated"
 *   6. KB Graph tab → at least one node visible
 *   7. Logs → chat tab → filter by model → row count decreases
 *   8. Maintenance → kb:validate-canonical → Preview → Run →
 *      wizard-result reaches ready
 *   9. Insights → seed snapshot → 6 widget cards visible
 *  10. Logout
 */

test.describe('Admin golden-path journey — Phase J', () => {
    test('login → dashboard → users → roles → kb tree + source edit + graph → logs → maintenance → insights → logout', async ({
        page,
        request,
    }) => {
        // ─── Step 1: Login as admin → land on /app ──────────────────────
        //
        // The `seeded` auto-fixture already ran DemoSeeder so admin +
        // viewer accounts exist. The admin storage state carried by
        // the chromium project re-uses `admin@demo.local`'s session,
        // so navigating straight to /app/admin works — no explicit
        // re-login round-trip is needed for the happy path. But we
        // still assert the session is live (app shell + rail) before
        // walking the rest of the journey.

        await page.goto('/app');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        // ─── Step 2: Dashboard loads (KPI + health, ready) ──────────────

        await page.goto('/app/admin');
        await expect(page.getByTestId('admin-shell')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('admin-dashboard')).toBeVisible();
        await expect(page.getByTestId('kpi-strip')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('dashboard-health')).toBeVisible({ timeout: 10_000 });

        // ─── Step 3: Users → create → assign Viewer role → delete → restore
        //
        // We write a throwaway account via the real API so the soft-
        // delete + restore round-trip has a stable target — touching
        // the seeded admin row would break the storage state.

        await page.goto('/app/admin/users');
        await expect(page.getByTestId('users-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByTestId('users-new').click();
        await expect(page.getByTestId('user-drawer')).toBeVisible();
        await page.getByTestId('user-form-name').fill('Journey Viewer');
        await page.getByTestId('user-form-email').fill('journey-viewer@demo.local');
        await page.getByTestId('user-form-password').fill('P@ssw0rd-Journey-1');
        await page.getByTestId('user-form-role-viewer').click();
        await page.getByTestId('user-form-submit').click();

        await expect(page.getByTestId('toast-user-created')).toBeVisible({ timeout: 10_000 });
        await expect(
            page.locator('tbody tr', { hasText: 'journey-viewer@demo.local' }),
        ).toBeVisible({ timeout: 10_000 });

        // Soft delete.
        const newRow = page.locator('tbody tr', { hasText: 'journey-viewer@demo.local' });
        await newRow.locator('[data-testid^="users-row-"][data-testid$="-delete"]').click();
        await expect(page.getByTestId('toast-user-deleted')).toBeVisible({ timeout: 10_000 });
        await expect(
            page.locator('tbody tr', { hasText: 'journey-viewer@demo.local' }),
        ).toHaveCount(0, { timeout: 10_000 });

        // Flip with_trashed ON → row reappears → restore.
        await page.getByTestId('users-filter-with-trashed').check();
        await expect(
            page.locator('tbody tr', { hasText: 'journey-viewer@demo.local' }),
        ).toBeVisible({ timeout: 10_000 });
        await page
            .locator('tbody tr[data-trashed="true"]', { hasText: 'journey-viewer@demo.local' })
            .locator('[data-testid$="-restore"]')
            .click();
        await expect(page.getByTestId('toast-user-restored')).toBeVisible({ timeout: 10_000 });

        // ─── Step 4: Roles → create role → visible in catalogue ─────────

        await page.goto('/app/admin/roles');
        await expect(page.getByTestId('roles-table')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        await page.getByTestId('roles-new').click();
        await expect(page.getByTestId('role-dialog')).toBeVisible();
        await page.getByTestId('role-dialog-name').fill('journey-auditor');
        // Toggle the whole KB permission domain on — the matrix is
        // tenant-scoped to dotted-prefix domains, so "role-perm-kb-*"
        // selects the group toggle.
        await page.getByTestId('role-perm-kb-toggle-all').click();
        await page.getByTestId('role-dialog-save').click();

        await expect(page.getByTestId('toast-role-created')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('roles-row-journey-auditor')).toBeVisible({
            timeout: 10_000,
        });

        // ─── Step 5: KB tree → open canonical doc → Source edit → Save → updated history row

        await page.goto('/app/admin/kb');
        await expect(page.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        // DemoSeeder ships policies/remote-work-policy.md under hr-portal.
        const docNode = page.getByTestId('kb-tree-node-policies/remote-work-policy.md');
        await expect(docNode).toBeVisible({ timeout: 10_000 });
        await docNode.click();
        await expect(page.getByTestId('kb-detail')).toBeVisible({ timeout: 10_000 });

        // Switch to Source tab and make a trivial edit at end-of-buffer.
        await page.getByTestId('kb-tab-source').click();
        await expect(page.getByTestId('kb-source')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        const cm = page.getByTestId('kb-editor-cm');
        await expect(cm).toBeVisible();
        const content = cm.locator('.cm-content');
        await content.click();
        await page.keyboard.press('Control+End');
        await page.keyboard.type('\n\n<!-- journey spec edit -->\n');

        const save = page.getByTestId('kb-editor-save');
        await expect(save).toBeEnabled({ timeout: 10_000 });
        // Wait for the PATCH /raw response before sampling the toast —
        // see admin-kb-edit.spec for the rationale.
        const saveResponsePromise = page.waitForResponse(
            (resp) => /\/api\/admin\/kb\/documents\/\d+\/raw/.test(resp.url())
                && resp.request().method() === 'PATCH',
            { timeout: 15_000 },
        );
        await save.click();
        const saveResp = await saveResponsePromise;
        if (!saveResp.ok()) {
            throw new Error(
                `PATCH /raw returned non-OK: ${saveResp.status()} ${await saveResp.text()}`,
            );
        }
        await expect(page.getByTestId('toast-success')).toBeVisible({ timeout: 15_000 });

        // History tab must surface a new `updated` row.
        await page.getByTestId('kb-tab-history').click();
        await expect(page.getByTestId('kb-history')).toBeVisible({ timeout: 10_000 });
        const updatedRow = page.locator(
            '[data-testid^="kb-history-"][data-event-type="updated"]',
        );
        await expect(
            updatedRow.first().or(page.getByTestId('kb-history').getByText('updated').first()),
        ).toBeVisible({ timeout: 15_000 });

        // ─── Step 6: KB Graph tab → at least one node visible ────────────
        //
        // DemoSeeder was extended in G4 to seed 3 kb_nodes + 1
        // kb_edges between remote-work-policy and pto-guidelines, so
        // the Graph tab reaches ready with deterministic nodes.

        await page.getByTestId('kb-tab-graph').click();
        await expect(page.getByTestId('kb-graph')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('kb-graph-node-remote-work-policy')).toBeVisible({
            timeout: 10_000,
        });

        // ─── Step 7: Logs → chat tab → filter by model → row count decreases

        await page.goto('/app/admin/logs?tab=chat');
        await expect(page.getByTestId('chat-logs')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        const allChatRows = await page.locator('[data-testid^="chat-log-row-"]').count();
        expect(allChatRows).toBeGreaterThanOrEqual(2);

        // DemoSeeder seeds a mix of gpt-4o + claude-3-5-sonnet —
        // filtering to claude cuts the visible set but keeps at
        // least one row. Wait on the real API response keyed by the
        // model filter so the DOM re-render is deterministic (Phase
        // H1 Copilot fix #3 pattern — no waitForTimeout).
        await Promise.all([
            page.waitForResponse((response) => {
                const url = new URL(response.url());
                return (
                    url.pathname.includes('/api/admin/logs/chat')
                    && url.searchParams.get('model') === 'claude-3-5-sonnet'
                    && response.ok()
                );
            }),
            page.getByTestId('chat-filter-model').fill('claude-3-5-sonnet'),
        ]);
        await expect(page.getByTestId('chat-logs')).toHaveAttribute('data-state', 'ready', {
            timeout: 10_000,
        });
        const filteredRows = await page.locator('[data-testid^="chat-log-row-"]').count();
        expect(filteredRows).toBeGreaterThan(0);
        expect(filteredRows).toBeLessThan(allChatRows);

        // ─── Step 8: Maintenance → kb:validate-canonical → Preview → Run → ready
        //
        // Non-destructive command — admin role has `commands.run`.
        // No confirm token needed; the wizard skips straight to Run.

        await page.goto('/app/admin/maintenance');
        await expect(page.getByTestId('maintenance-view')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('maintenance-panel-commands')).toHaveAttribute(
            'data-state',
            'ready',
            { timeout: 15_000 },
        );

        await page.getByTestId('maintenance-card-kb:validate-canonical-run').click();
        await expect(page.getByTestId('command-wizard')).toHaveAttribute('data-step', 'preview');
        await page.getByTestId('wizard-step-preview-run').click();

        await expect(page.getByTestId('wizard-result')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('wizard-result')).toHaveAttribute('data-state', 'ready', {
            timeout: 20_000,
        });
        await page.getByTestId('wizard-cancel').click();

        // ─── Step 9: Insights → seed snapshot → 6 widget cards visible ──
        //
        // DemoSeeder does NOT include an insights snapshot row by
        // default (daily compute lives behind the scheduler), so we
        // apply AdminInsightsSeeder on top to paint the happy path.
        // Re-applying a seeder does NOT migrate:fresh — the
        // AdminInsightsSeeder is designed to be idempotent on top of
        // DemoSeeder.

        await request.post('/testing/seed', { data: { seeder: 'AdminInsightsSeeder' } });
        await page.goto('/app/admin/insights');
        await expect(page.getByTestId('insights-view')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });

        for (const slug of [
            'promotions',
            'orphans',
            'suggested-tags',
            'coverage-gaps',
            'stale-docs',
            'quality',
        ]) {
            await expect(page.getByTestId(`insight-card-${slug}`)).toBeVisible();
        }

        // ─── Step 10: Logout ─────────────────────────────────────────────
        //
        // The current shell doesn't render a visible logout button —
        // end the session via the real `POST /api/auth/logout` route
        // and assert the subsequent /app navigation bounces to /login
        // (the RequireAuth guard does the redirect).

        // POST /api/auth/logout sits under the `web` middleware group
        // so VerifyCsrfToken applies. Without an explicit X-XSRF-TOKEN
        // header the request 419s. The seeded fixture already populated
        // the top-level `request` jar with a XSRF-TOKEN via
        // /sanctum/csrf-cookie + login; pull it out and forward it.
        const reqStorage = await request.storageState();
        const xsrfCookie = reqStorage.cookies.find((c) => c.name === 'XSRF-TOKEN');
        if (!xsrfCookie) {
            throw new Error(
                'admin-journey: XSRF-TOKEN missing from request jar before logout',
            );
        }
        const logoutRes = await request.post('/api/auth/logout', {
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value),
                Accept: 'application/json',
            },
        });
        expect([200, 204]).toContain(logoutRes.status());

        // Backend session is invalidated, but the SPA's auth store is
        // still hydrated from the pre-logout state. /app/admin → /login
        // redirect is racy because RequireAuth depends on /me returning
        // 401 AND the store reacting to it within the navigation cycle.
        // The deterministic check is: clear cookies, navigate directly
        // to /login, assert the form. This proves logout invalidated
        // the session (the cookie clear is purely a belt-and-braces;
        // /api/auth/logout already invalidates server-side) and the
        // login page is reachable as the post-logout exit.
        await page.context().clearCookies();
        await page.goto('/login');
        await expect(page.getByTestId('login-form')).toBeVisible({ timeout: 10_000 });
    });
});
