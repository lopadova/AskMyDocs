import { test, expect } from '@playwright/test';
import { resetAndSeed } from './setup-helpers';

/*
 * Public invite-gated registration (/register).
 *
 * R13: drives the REAL served app end-to-end — real Sanctum session, real
 * RegisterController, real DB (seeded via resetAndSeed). No route stubs. Runs
 * unauthenticated (clean storageState in the chromium-auth-public project).
 *
 * The happy path (a genuinely redeemable code → 201 + signed in) depends on a
 * seeded active code, which the DemoSeeder does not guarantee; the deterministic
 * happy path lives in PHPUnit (RegisterTest). Here we cover the gate + the real
 * server-side invalid-code failure, which need no seed.
 */

test.describe('Public registration (invite-gated)', () => {
    test.beforeEach(async ({ page }) => {
        await resetAndSeed(page);
    });

    test('the invite code is a required field on the registration form', async ({ page }) => {
        await page.goto('/register');

        await expect(page.getByTestId('register-form')).toBeVisible();
        await expect(page.getByTestId('register-code')).toBeVisible();

        // Empty submit → client-side constraint fires on the invite code.
        await page.getByTestId('register-submit').click();
        await expect(page.getByText(/invite code is required/i)).toBeVisible();
    });

    test('an unknown invite code is rejected by the server (real 422)', async ({ page }) => {
        await page.goto('/register');

        await page.getByTestId('register-code').fill('NOPE0000');
        await page.getByTestId('register-name').fill('Mallory');
        await page.getByTestId('register-email').fill('mallory@example.com');
        await page.getByTestId('register-password').fill('Sup3r-secret!');
        await page.getByTestId('register-password-confirmation').fill('Sup3r-secret!');

        const post = page.waitForResponse(
            (r) => r.url().endsWith('/api/auth/register') && r.request().method() === 'POST',
            { timeout: 15_000 },
        );
        await page.getByTestId('register-submit').click();
        const resp = await post;
        expect(resp.status()).toBe(422);

        // The server message surfaces next to the code input, and no redirect
        // to /app happened (registration did not succeed).
        await expect(page.getByText(/not valid/i)).toBeVisible();
        await expect(page).toHaveURL(/\/register$/);
    });

    test('the login page links to registration for invite holders', async ({ page }) => {
        await page.goto('/login');
        await page.getByTestId('login-to-register').click();
        await expect(page).toHaveURL(/\/register$/);
        await expect(page.getByTestId('register-form')).toBeVisible();
    });
});
