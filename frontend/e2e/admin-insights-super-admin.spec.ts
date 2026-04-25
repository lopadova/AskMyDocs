import { test, expect } from '@playwright/test';

/*
 * PR14 — Phase I. Admin Insights super-admin recompute flow.
 *
 * The compute endpoint is gated by `permission:commands.destructive`
 * (only super-admin holds that permission). This spec runs under the
 * super-admin storage state so the POST /api/admin/insights/compute
 * round-trips a real 202 + audit row.
 *
 * Running compute is cheap on the DemoSeeder corpus — the AiInsightsService
 * functions walk a tiny dataset, LLM-bearing functions either return []
 * (no candidates) or trip on the unconfigured provider and the command's
 * try/catch writes null to the affected columns. The snapshot row
 * itself is written successfully.
 */

test.describe('Admin Insights — super-admin recompute flow', () => {
    test('happy — compute endpoint returns 202 + audit row', async ({ request }) => {
        // Call the endpoint directly — the UI for the compute button
        // ships in a later polish phase; the backend contract is the
        // load-bearing surface.
        const response = await request.post('/api/admin/insights/compute');
        expect(response.status()).toBe(202);

        const body = await response.json();
        expect(body).toHaveProperty('audit_id');
        expect(typeof body.audit_id).toBe('number');
        expect(body.audit_id).toBeGreaterThan(0);
    });
});
