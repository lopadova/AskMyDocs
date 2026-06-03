import { expect } from '@playwright/test';
import { test as seededTest } from './fixtures';

/**
 * Regression: the primary sidebar must link the five core admin sections to
 * their REAL views, not to the early-phase `Coming in Phase …` placeholders.
 *
 * `AppShell.SECTION_ROUTES` originally pointed Dashboard / Knowledge /
 * AI Insights / Users / Maintenance at `/app/dashboard`, `/app/kb`,
 * `/app/insights`, `/app/users`, `/app/maintenance` — placeholder stubs —
 * while the fully-built DashboardView / KbView / InsightsView / UsersView /
 * MaintenanceView lived under `/app/admin/*` and were reachable only by
 * typing the URL. The existing e2e suites navigated to `/app/admin/*`
 * directly, so they never caught that the SIDEBAR dead-ended on stubs. This
 * spec drives the real sidebar buttons and asserts each lands on the real
 * view (real data via the seeded DemoSeeder, R13) with no placeholder text.
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
