import { test, expect } from '@playwright/test';

/*
 * Proves the SecurityHeaders middleware + CSP report collector against the REAL
 * app (php artisan serve via the webServer block) — the layer the Testbench
 * feature test cannot reach, because Testbench does not run the host
 * bootstrap/app.php global middleware or CSRF stack.
 *
 * No seeding needed: every endpoint here is unauthenticated. Nothing is stubbed
 * (R13) — these are the actual production-wired responses.
 */

test.describe('security headers (real stack)', () => {
    test('baseline headers are stamped on a real response', async ({ request }) => {
        const res = await request.get('/healthz');
        expect(res.status()).toBe(200);

        const headers = res.headers();
        expect(headers['x-content-type-options']).toBe('nosniff');
        expect(headers['referrer-policy']).toBe('strict-origin-when-cross-origin');
        expect(headers['x-frame-options']).toBe('DENY');
        expect(headers['x-request-id']).toBeTruthy();
    });

    test('CSP report-only header with a nonce is present on the HTML shell', async ({ request }) => {
        const res = await request.get('/login');
        expect(res.status()).toBe(200);

        const csp = res.headers()['content-security-policy-report-only'];
        expect(csp, 'HTML responses must carry the report-only CSP').toBeTruthy();
        expect(csp).toContain("script-src 'self' 'nonce-");
        expect(csp).toContain("frame-ancestors 'none'");
    });

    test('CSP report collector accepts a tokenless browser POST (CSRF-exempt)', async ({ request }) => {
        // A real browser submits CSP reports with no CSRF token. Without the
        // bootstrap exemption this would 419; the exemption makes it 204.
        const res = await request.post('/csp-report', {
            headers: { 'content-type': 'application/csp-report' },
            data: JSON.stringify({
                'csp-report': { 'blocked-uri': 'https://evil.example', 'violated-directive': "script-src 'self'" },
            }),
        });

        expect(res.status(), 'tokenless CSP report must not be rejected by CSRF').toBe(204);
    });
});
