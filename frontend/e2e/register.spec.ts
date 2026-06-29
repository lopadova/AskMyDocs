import { test, expect, type APIRequestContext } from '@playwright/test';
import { resetDb, seedDb, E2E_BASE_URL } from './setup-helpers';

/*
 * Invite-only registration — POST /api/auth/register + the React /register page.
 *
 * R13 compliance: real backend, real seeders, real Sanctum cookies, real invite
 * engine. NO page.route on any internal route.
 *
 * Uses the bare `@playwright/test` `test` (NOT the `seeded` auto-fixture) on
 * purpose: registration is a GUEST flow, so the browser `page` context must stay
 * unauthenticated — the auto-fixture would log admin in and RedirectIfAuth would
 * bounce /register straight to /app. Each test reseeds explicitly.
 *
 * The happy path mints a REAL code via the admin API on the page-independent
 * `request` fixture (separate cookie jar → the `page` is never authenticated),
 * then drives the actual UI to register + land in the app.
 */

/** Read the current XSRF-TOKEN from an APIRequestContext's own cookie jar. */
async function readXsrf(request: APIRequestContext): Promise<string> {
    const { cookies } = await request.storageState();
    const baseHost = new URL(E2E_BASE_URL).hostname;
    const cookie = cookies.find((c) => c.name === 'XSRF-TOKEN' && c.domain.replace(/^\./, '') === baseHost);
    if (!cookie) {
        throw new Error(
            `XSRF-TOKEN cookie missing for host ${baseHost} — /sanctum/csrf-cookie must be primed first.`,
        );
    }
    return decodeURIComponent(cookie.value);
}

/**
 * Authenticate as the seeded admin on `request` and mint one invite code in the
 * `default` tenant (the same tenant the guest /register flow resolves to, so the
 * code validates). Returns the plaintext code the InviteCodeResource exposes.
 */
async function mintInviteCode(request: APIRequestContext): Promise<string> {
    const csrf = await request.get('/sanctum/csrf-cookie');
    expect(csrf.ok()).toBeTruthy();

    const login = await request.post('/api/auth/login', {
        data: { email: 'admin@demo.local', password: 'password' },
        headers: { 'X-XSRF-TOKEN': await readXsrf(request), Accept: 'application/json' },
    });
    expect(login.ok(), `admin login failed: ${login.status()} ${await login.text()}`).toBeTruthy();

    const minted = await request.post('/api/admin/invitations/codes', {
        data: { count: 1, max_uses: 5 },
        headers: {
            // Mint in `default` (admin has membership there) so the guest
            // register — which resolves the default tenant — finds the code.
            'X-Tenant-Id': 'default',
            'X-XSRF-TOKEN': await readXsrf(request),
            Accept: 'application/json',
        },
    });
    expect(minted.status(), `code mint failed: ${minted.status()} ${await minted.text()}`).toBe(201);

    const body = (await minted.json()) as { data: Array<{ code: string }> };
    const code = body.data?.[0]?.code;
    expect(code, 'admin code-generation response did not include a plaintext code').toBeTruthy();
    return code;
}

test.describe('Invite-only registration', () => {
    test('happy — a guest registers with a freshly-minted code and lands in the app', async ({
        page,
        request,
    }) => {
        await resetDb(request);
        await seedDb(request, 'DemoSeeder');

        const code = await mintInviteCode(request);

        await page.goto('/register');
        await expect(page.getByTestId('register-form')).toBeVisible({ timeout: 15_000 });

        await page.getByTestId('register-name').fill('Nuova Persona');
        await page.getByTestId('register-email').fill('nuova.persona@demo.local');
        await page.getByTestId('register-password').fill('super-secret-pw');
        await page.getByTestId('register-password-confirmation').fill('super-secret-pw');
        await page.getByTestId('register-invite-code').fill(code);
        await page.getByTestId('register-submit').click();

        // Success opens the session and the SPA routes the brand-new viewer into
        // the app shell.
        await expect(page).toHaveURL(/\/app/, { timeout: 15_000 });
        await expect(page.getByTestId('appshell-root')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('register-form-error')).toHaveCount(0);
    });

    test('failure — an invalid invite code is rejected inline and the guest stays on /register', async ({
        page,
        request,
    }) => {
        await resetDb(request);
        await seedDb(request, 'DemoSeeder');

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
