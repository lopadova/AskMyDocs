import { test, expect } from '@playwright/test';
import { resetDb, seedDb } from './setup-helpers';

/*
 * Invite-only registration — POST /api/auth/register + the React /register page.
 *
 * R13 compliance: real backend, real seeders, real Sanctum cookies, real invite
 * engine. NO page.route on any internal route.
 *
 * Uses the bare `@playwright/test` `test` (NOT the `seeded` auto-fixture) and
 * clears the project storage cookies after each explicit reset/seed. Registration
 * is a GUEST flow, so no setup-project session may reach the browser page.
 *
 * The testing-only seeder mints deterministic codes through the package's real
 * CodeGenerator. The browser then drives registration, redemption, membership
 * provisioning and onboarding without stubbing any internal request.
 */

test.describe('Invite-only registration', () => {
    test.describe.configure({ mode: 'serial' });

    test('system invite — registration pauses until resumable company onboarding completes', async ({
        page,
        request,
    }) => {
        await resetDb(request);
        await seedDb(request, 'DemoSeeder');
        await seedDb(request, 'RegistrationOnboardingSeeder');
        await page.context().clearCookies();

        await page.goto('/register');
        await expect(page.getByTestId('register-form')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('register-name').fill('Nuova Persona');
        await page.getByTestId('register-email').fill('nuova.persona@demo.local');
        await page.getByTestId('register-password').fill('super-secret-pw');
        await page.getByTestId('register-password-confirmation').fill('super-secret-pw');
        await page.getByTestId('register-invite-code').fill('START123');
        await page.getByTestId('register-submit').click();

        await expect(page).toHaveURL(/\/app\/onboarding$/, { timeout: 15_000 });
        await expect(page.getByTestId('company-onboarding-view')).toHaveAttribute('data-state', 'ready');
        await expect(page.getByTestId('register-form-error')).toHaveCount(0);

        // A dropped connection or closed browser cannot bypass the gate.
        await page.reload();
        await expect(page).toHaveURL(/\/app\/onboarding$/);
        await expect(page.getByTestId('company-onboarding-form')).toBeVisible();

        await page.getByTestId('company-onboarding-name').fill('Nuova Azienda');
        await page.getByTestId('company-onboarding-slug').fill('nuova-azienda');
        await page.getByTestId('company-onboarding-project').fill('azienda-kb');
        await page.getByTestId('company-onboarding-submit').click();

        await expect(page).toHaveURL(/\/app\/[^/]+\/chat$/, { timeout: 15_000 });
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('company-onboarding-view')).toHaveCount(0);
    });

    test('company invite — registration provisions the existing tenant and skips onboarding', async ({
        page,
        request,
    }) => {
        await resetDb(request);
        await seedDb(request, 'DemoSeeder');
        await seedDb(request, 'RegistrationOnboardingSeeder');
        await page.context().clearCookies();

        await page.goto('/register');
        await expect(page.getByTestId('register-form')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('register-name').fill('Persona Invitata');
        await page.getByTestId('register-email').fill('persona.invitata@demo.local');
        await page.getByTestId('register-password').fill('super-secret-pw');
        await page.getByTestId('register-password-confirmation').fill('super-secret-pw');
        await page.getByTestId('register-invite-code').fill('J01NACME');
        await page.getByTestId('register-submit').click();

        await expect(page).toHaveURL(/\/app\/[^/]+\/chat$/, { timeout: 15_000 });
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('company-onboarding-view')).toHaveCount(0);
    });

    test('failure — an invalid invite code is rejected inline and the guest stays on /register', async ({
        page,
        request,
    }) => {
        await resetDb(request);
        await seedDb(request, 'DemoSeeder');
        await seedDb(request, 'RegistrationOnboardingSeeder');
        await page.context().clearCookies();

        await page.goto('/register');
        await expect(page.getByTestId('register-form')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('register-name').fill('Nessuno');
        await page.getByTestId('register-email').fill('nessuno@demo.local');
        await page.getByTestId('register-password').fill('super-secret-pw');
        await page.getByTestId('register-password-confirmation').fill('super-secret-pw');
        // Alphabet-valid but absent from the DB → real 422 from CodeValidator.
        await page.getByTestId('register-invite-code').fill('XXXXXXXX');
        await page.getByTestId('register-submit').click();

        await expect(page.getByTestId('invite_code-error')).toBeVisible({ timeout: 15_000 });
        await expect(page).toHaveURL(/\/register/);
    });
});
