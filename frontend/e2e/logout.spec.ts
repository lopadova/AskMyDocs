import { expect } from '@playwright/test';
import { test } from './fixtures';

/*
 * Sign-out (logout) E2E.
 *
 * R12 — the user-facing UserMenu ships Playwright coverage in the same
 * PR. R13 — the happy path runs against the REAL backend (real Sanctum
 * session invalidation); the failure scenario carries the marker comment
 * + an explicit internal-route injection.
 *
 * Scenarios:
 *   1. Open the account menu → "Sign out" → the real POST /api/auth/logout
 *      invalidates the session and the SPA lands the user on /login.
 *   2. R13: failure injection — force POST /api/auth/logout to 500 and
 *      assert the error banner surfaces AND the user stays inside the app
 *      (R14 — a failed sign-out must NOT pretend success and dump the
 *      user out client-side while the server cookie is still live).
 */

test.describe('Sign out', () => {
    test('signs the user out and returns them to the login screen', async ({ page }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('user-menu-trigger').click();
        await expect(page.getByTestId('user-menu')).toBeVisible();

        await page.getByTestId('user-menu-logout').click();

        // Hard redirect to /login after the 204 — the login form is the
        // proof the guarded shell let go of the session.
        await expect(page.getByTestId('login-form')).toBeVisible({ timeout: 15_000 });
        await expect(page).toHaveURL(/\/login$/);
    });

    // R13: failure injection — internal route intercept is permitted here
    // because the happy-path variant above already exercises the real
    // logout flow end-to-end against the live backend.
    test('surfaces an error and keeps the user signed in when logout fails', async ({ page }) => {
        await page.goto('/app/chat');
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });

        await page.route('**/api/auth/logout', async (route) => {
            await route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Server error' }),
            });
        });

        await page.getByTestId('user-menu-trigger').click();
        await page.getByTestId('user-menu-logout').click();

        // The failure is shown loudly, and the user is still in the app.
        await expect(page.getByTestId('user-menu-error')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('appshell-root')).toBeVisible();
        await expect(page).not.toHaveURL(/\/login$/);
    });
});
