import { expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/**
 * Primary sidebar navigation — unified rail (v8.8/W1).
 *
 * Before v8.8 there were TWO overlapping navs: the primary `Sidebar` and a
 * secondary `AdminShell` rail, so admin pages showed eight sections twice.
 * The v8.8 unified-admin branch merged them into ONE grouped, collapsible
 * sidebar (nav-config.ts). This spec covers:
 *
 *  1. Legacy regression — the five core sections still link to real views
 *     (not the old "Coming in Phase …" placeholders).
 *  2. Redirect regression — old placeholder paths still redirect to real views.
 *  3. Unified sidebar — newly-surfaced sections (e.g. Roles, which only lived
 *     in the secondary AdminShell rail) are reachable from the primary sidebar.
 *  4. Collapsible groups — failure path: collapsing a group hides its entries;
 *     the active section's group cannot be collapsed.
 *
 * All tests hit the real backend with seeded DemoSeeder data (R13 — no
 * internal-route interception).
 */
const SECTIONS = [
    { testid: 'sidebar-nav-dashboard', url: /\/app\/admin$/, heading: 'Dashboard' },
    { testid: 'sidebar-nav-kb', url: /\/app\/admin\/kb/, heading: 'Knowledge Base' },
    { testid: 'sidebar-nav-insights', url: /\/app\/admin\/insights$/, heading: 'AI Insights' },
    { testid: 'sidebar-nav-users', url: /\/app\/admin\/users$/, heading: 'Users' },
    { testid: 'sidebar-nav-maintenance', url: /\/app\/admin\/maintenance$/, heading: 'Maintenance' },
] as const;

seededTest.describe('Primary sidebar links to the real admin views', () => {
    seededTest('each core section opens its real view, not a placeholder', async ({ page }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('sidebar-nav-dashboard')).toBeVisible({ timeout: 15_000 });

        for (const section of SECTIONS) {
            await page.getByTestId(section.testid).click();
            await expect(page).toHaveURL(section.url);

            // The real view renders its h1; the placeholder rendered a static
            // "Coming in Phase …" card with no admin rail.
            await expect(page.getByRole('heading', { name: section.heading, level: 1 })).toBeVisible({
                timeout: 15_000,
            });
            await expect(page.getByText(/Coming in Phase/i)).toHaveCount(0);

            // The clicked entry is the active one in the primary rail.
            await expect(page.getByTestId(section.testid)).toHaveAttribute('aria-current', 'page');
        }
    });

    seededTest('the old placeholder paths redirect to the real admin views', async ({ page }) => {
        for (const [from, expected] of [
            ['/app/dashboard', /\/app\/admin$/],
            ['/app/kb', /\/app\/admin\/kb/],
            ['/app/insights', /\/app\/admin\/insights$/],
            ['/app/users', /\/app\/admin\/users$/],
            ['/app/maintenance', /\/app\/admin\/maintenance$/],
        ] as const) {
            await page.goto(from);
            await expect(page).toHaveURL(expected, { timeout: 15_000 });
            await expect(page.getByText(/Coming in Phase/i)).toHaveCount(0);
        }
    });
});

seededTest.describe('Unified sidebar — newly-surfaced sections', () => {
    seededTest(
        'Roles (previously only in the secondary AdminShell rail) is reachable from the primary sidebar',
        async ({ page }) => {
            await page.goto('/app/admin');
            await expect(page.getByTestId('sidebar-nav-roles')).toBeVisible({ timeout: 15_000 });

            await page.getByTestId('sidebar-nav-roles').click();
            await expect(page).toHaveURL(/\/app\/admin\/roles$/);
            await expect(page.getByTestId('sidebar-nav-roles')).toHaveAttribute('aria-current', 'page');
            await expect(page.getByTestId('admin-shell')).toBeVisible();
        },
    );
});

seededTest.describe('Unified sidebar — collapsible groups', () => {
    seededTest(
        'failure — collapsing a non-active group hides its entries; re-expanding restores them',
        async ({ page }) => {
            await page.goto('/app/chat');
            // The Operations group is NOT the active group (chat lives in Workspace).
            // All groups default to expanded, so sidebar-nav-flows must be visible.
            await expect(page.getByTestId('sidebar-nav-flows')).toBeVisible({ timeout: 15_000 });

            // Collapse the Operations group → its entries are removed from the DOM
            // (the sidebar conditionally renders items, it does not just hide them),
            // so assert detachment explicitly rather than the weaker not-visible.
            await page.getByTestId('sidebar-group-operations').click();
            await expect(page.getByTestId('sidebar-nav-flows')).toHaveCount(0);

            // Re-expand → entries reappear and are clickable again.
            await page.getByTestId('sidebar-group-operations').click();
            await expect(page.getByTestId('sidebar-nav-flows')).toBeVisible();
        },
    );

    seededTest(
        'failure — the active section\'s group cannot be collapsed (entry stays visible + highlighted)',
        async ({ page }) => {
            // Navigate directly to a URL inside the Operations group so that group
            // is forced open by the active-section constraint in the sidebar.
            await page.goto('/app/admin/flows');
            await expect(page.getByTestId('sidebar-nav-flows')).toBeVisible({ timeout: 15_000 });
            await expect(page.getByTestId('sidebar-nav-flows')).toHaveAttribute('aria-current', 'page');

            // Attempt to collapse the active group — the sidebar MUST keep it open.
            await page.getByTestId('sidebar-group-operations').click();
            await expect(page.getByTestId('sidebar-nav-flows')).toBeVisible();
            await expect(page.getByTestId('sidebar-nav-flows')).toHaveAttribute('aria-current', 'page');
        },
    );
});
