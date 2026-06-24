import { test, expect, type Page } from '@playwright/test';
import { resetAndSeed } from './setup-helpers';

/*
 * R32 — per-role access-control regression gate (E2E layer).
 *
 * The deterministic authoritative gate lives in PHPUnit
 * (tests/Feature/Security/AdminAuthorizationMatrixTest). THIS spec is the
 * end-to-end complement: it logs in as each of the five seeded roles through
 * the REAL served app (real Sanctum cookie session, real middleware, the real
 * config/*.php — including the package route gates that Testbench can't fully
 * mirror) and asserts that each role reaches EXACTLY the admin APIs it should.
 *
 * Hitting internal /api/admin/* routes from E2E is intentional and allowed
 * under R13 — they are first-party, real-data endpoints, not external
 * services. The assertion is on the authorization decision: a role NOT in the
 * allow-set must get 403; a role IN the allow-set must get anything-but-403.
 *
 * Runs unauthenticated (no storageState); each test logs in inline so the five
 * roles stay isolated. Add a row to ENDPOINTS whenever you add a protected
 * route group (see .claude/skills/rbac-authorization-matrix).
 */

const PASSWORD = 'password';

// Representative no-path-param admin endpoint → exact allow-set of roles.
// Mirrors AdminAuthorizationMatrixTest::matrix().
const ENDPOINTS: ReadonlyArray<{ uri: string; allowed: readonly string[] }> = [
    // core admin group (role:admin|super-admin)
    { uri: '/api/admin/metrics/overview', allowed: ['admin', 'super-admin'] },
    { uri: '/api/admin/users', allowed: ['admin', 'super-admin'] },
    { uri: '/api/admin/projects', allowed: ['admin', 'super-admin'] },
    { uri: '/api/admin/logs/chat', allowed: ['admin', 'super-admin'] },
    { uri: '/api/admin/kb/tree', allowed: ['admin', 'super-admin'] },
    // gate-based groups
    { uri: '/api/admin/connectors', allowed: ['admin', 'super-admin'] },
    { uri: '/api/admin/mcp-servers', allowed: ['super-admin'] },
    { uri: '/api/admin/mcp-tool-call-audit', allowed: ['admin', 'super-admin'] },
    { uri: '/api/admin/pii/strategy', allowed: ['admin', 'dpo', 'super-admin'] },
    { uri: '/api/admin/eval-harness/bootstrap-config', allowed: ['admin', 'dpo', 'editor', 'super-admin'] },
    { uri: '/api/admin/tabular-reviews', allowed: ['admin', 'viewer', 'super-admin'] },
    { uri: '/api/admin/workflows', allowed: ['admin', 'viewer', 'super-admin'] },
    // the route group whose missing gate this whole effort uncovered
    { uri: '/api/admin/ai-act-compliance/overview', allowed: ['admin', 'dpo', 'super-admin'] },
    // v8.13/P11 — evidence-risk-review admin (gate: viewEvidenceRiskReview;
    // route registered because the E2E webServer sets the admin flag ON)
    { uri: '/api/admin/evidence-risk-review/reviews', allowed: ['admin', 'dpo', 'super-admin'] },
    // M6 — widget admin (gate: manageWidgetKeys / viewWidgetSessions)
    { uri: '/api/admin/widget-keys', allowed: ['super-admin'] },
    { uri: '/api/admin/widget-sessions', allowed: ['admin', 'super-admin'] },
    // v8.x — invitations admin (gate: manageInvitations; core API mounted by PR #363)
    { uri: '/api/admin/invitations/metrics', allowed: ['admin', 'super-admin'] },
];

const ALL_ROLES = ['super-admin', 'admin', 'dpo', 'editor', 'viewer'] as const;

// DemoSeeder seeds the super-admin as `super@demo.local` (NOT
// `super-admin@demo.local`); every other role uses `<role>@demo.local`.
function roleEmail(role: string): string {
    return role === 'super-admin' ? 'super@demo.local' : `${role}@demo.local`;
}

async function loginAs(page: Page, email: string): Promise<void> {
    await page.request.get('/sanctum/csrf-cookie');
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrf) {
        throw new Error('XSRF-TOKEN cookie missing after /sanctum/csrf-cookie');
    }
    const res = await page.request.post('/api/auth/login', {
        data: { email, password: PASSWORD },
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'application/json' },
    });
    if (!res.ok()) {
        throw new Error(`Login failed for ${email}: ${res.status()} ${await res.text()}`);
    }
}

test.describe('R32 per-role admin API access control', () => {
    test.beforeEach(async ({ page }) => {
        await resetAndSeed(page);
    });

    for (const role of ALL_ROLES) {
        test(`role [${role}] reaches exactly its allowed admin endpoints`, async ({ page }) => {
            await loginAs(page, roleEmail(role));

            for (const { uri, allowed } of ENDPOINTS) {
                const status = (await page.request.get(uri)).status();
                if (allowed.includes(role)) {
                    expect(
                        status,
                        `[${role}] should PASS the gate for ${uri} (got ${status})`,
                    ).not.toBe(403);
                } else {
                    expect(
                        status,
                        `[${role}] must be DENIED (403) for ${uri} (got ${status})`,
                    ).toBe(403);
                }
            }
        });
    }

    test('guest is rejected (401) on every protected admin endpoint', async ({ page }) => {
        // no login — fresh context from beforeEach reset
        for (const { uri } of ENDPOINTS) {
            const status = (await page.request.get(uri, { headers: { Accept: 'application/json' } })).status();
            expect(status, `guest must be 401 on ${uri} (got ${status})`).toBe(401);
        }
    });
});
