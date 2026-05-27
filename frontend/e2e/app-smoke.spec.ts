import { expect, type Page } from '@playwright/test';
import { test } from './fixtures';

/*
 * v8.6 — boot + navigation smoke. Answers the plain question "can I open
 * AskMyDocs and click around the main screens without anything blowing up?".
 *
 * For every main admin-accessible route it: navigates, waits for the app
 * shell to mount, and accumulates BOTH uncaught exceptions (`pageerror`) and
 * fatal-looking `console.error`s across the whole walk. A single uncaught
 * exception on any screen fails the test — that is the "boom on click" class
 * the rest of the suite (feature specs) can miss when a page renders but
 * throws asynchronously.
 *
 * Nothing is stubbed (R13): the real SPA against the real seeded back-end,
 * logged in as admin@demo.local via the `seeded` fixture. AI calls don't fire
 * here (no chat turn is sent), so the provider is irrelevant.
 *
 * super-admin-only routes (connectors, mcp-tools) are intentionally excluded:
 * the admin role hits the RequireRole denial UI there — not an error, but not
 * a clean "page mounted" signal either.
 */

const FATAL_CONSOLE = /Type validation failed|Cannot read|is not a function|Maximum update depth|Minified React error|The above error occurred/i;

// Admin-accessible main screens. Each renders the shell + its own feature.
const ROUTES: string[] = [
    '/app/chat',
    '/app/admin',
    '/app/admin/kb',
    '/app/admin/kb/health',
    '/app/admin/users',
    '/app/admin/roles',
    '/app/admin/logs',
    '/app/admin/maintenance',
    '/app/admin/insights',
    '/app/admin/tabular-reviews',
    '/app/admin/workflows',
];

test.describe('App smoke — navigate the main screens with no errors', () => {
    test('every main screen mounts without an uncaught exception', async ({ page }) => {
        const pageErrors: string[] = [];
        const consoleErrors: string[] = [];
        page.on('pageerror', (e) => pageErrors.push(`pageerror: ${e.message}`));
        page.on('console', (m) => {
            if (m.type() === 'error' && FATAL_CONSOLE.test(m.text())) {
                consoleErrors.push(`console.error: ${m.text()}`);
            }
        });

        for (const route of ROUTES) {
            await visitAndSettle(page, route);
        }

        const fatal = [...pageErrors, ...consoleErrors];
        expect(fatal, `Errors during navigation:\n${fatal.join('\n')}`).toEqual([]);
    });
});

async function visitAndSettle(page: Page, route: string): Promise<void> {
    await page.goto(route);
    // The SPA shell wraps every /app screen; once it's visible the route's
    // feature component has mounted (or its RequireRole gate has rendered).
    await expect(page.getByTestId('appshell-root'), `shell never mounted at ${route}`).toBeVisible({
        timeout: 15_000,
    });
    await expect(page.getByTestId('sidebar-nav')).toBeVisible();
    // Give async data-fetching effects a beat to run so a render-time throw
    // surfaces as a pageerror before we move on. Network-idle is the right
    // signal here (we are not asserting a specific data-state per screen).
    await page.waitForLoadState('networkidle');
}
