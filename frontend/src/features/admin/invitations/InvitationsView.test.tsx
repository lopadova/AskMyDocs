import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';

/*
 * InvitationsView unit tests — the native host landing for the
 * padosoft/laravel-invitations-admin panel. The view reads live funnel KPIs
 * over the SHARED SPA api client (lib/api.ts — which carries the stateful
 * Sanctum contract: X-Requested-With + withCredentials + the X-Tenant-Id
 * interceptor) from /api/admin/invitations/metrics, and links out to the
 * mounted Blade SPA. R16: each test drives the state it claims. R14: a non-ok
 * response AND a malformed-shape 200 both resolve to the error state, never NaN.
 */

// Mock the shared api client so we assert the metrics read goes THROUGH it
// (i.e. carries the SPA contract) — a regression to an unauthenticated raw
// fetch would no longer call api.get and would fail these tests.
const apiGet = vi.fn();
vi.mock('../../../lib/api', () => ({
    api: { get: (...args: unknown[]) => apiGet(...args) },
}));

import { InvitationsView } from './InvitationsView';

describe('InvitationsView', () => {
    beforeEach(() => {
        apiGet.mockReset();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders the open-panel link to the mounted Blade SPA', () => {
        apiGet.mockReturnValue(new Promise(() => undefined)); // never resolves → stays loading
        render(<InvitationsView />);

        const link = screen.getByTestId('admin-invitations-open-panel');
        expect(link).toHaveAttribute('href', '/admin/invitations');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('reads metrics through the shared SPA api client (carries the Sanctum contract)', () => {
        apiGet.mockReturnValue(new Promise(() => undefined));
        render(<InvitationsView />);

        // The request MUST go through the shared api client (which injects
        // X-Requested-With + withCredentials + X-Tenant-Id), not a raw fetch.
        expect(apiGet).toHaveBeenCalledTimes(1);
        expect(apiGet).toHaveBeenCalledWith('/api/admin/invitations/metrics', expect.any(Object));
    });

    it('shows live KPIs from a valid metrics response (ready state)', async () => {
        apiGet.mockResolvedValue({
            data: {
                data: {
                    codes_issued: 42,
                    redemptions: 7,
                    k_factor: 1.5,
                    // extra keys the view ignores must not break it
                    invites_sent: 10,
                },
            },
        });
        render(<InvitationsView />);

        await waitFor(() =>
            expect(screen.getByTestId('admin-invitations-host')).toHaveAttribute('data-state', 'ready'),
        );
        expect(screen.getByTestId('admin-invitations-kpi-codes')).toHaveTextContent('42');
        expect(screen.getByTestId('admin-invitations-kpi-redemptions')).toHaveTextContent('7');
        expect(screen.getByTestId('admin-invitations-kpi-k-factor')).toHaveTextContent('1.5');
        expect(screen.queryByTestId('admin-invitations-error')).not.toBeInTheDocument();
    });

    it('surfaces the error state when the api client rejects (non-ok / network) (R14)', async () => {
        apiGet.mockRejectedValue(new Error('Request failed with status code 403'));
        render(<InvitationsView />);

        await waitFor(() =>
            expect(screen.getByTestId('admin-invitations-host')).toHaveAttribute('data-state', 'error'),
        );
        expect(screen.getByTestId('admin-invitations-error')).toBeInTheDocument();
        // The open-panel link is still offered as a fallback.
        expect(screen.getByTestId('admin-invitations-open-panel')).toBeInTheDocument();
    });

    it('surfaces the error state on a malformed 200 shape, never NaN KPIs (R14)', async () => {
        // 200 OK but k_factor is a string → must NOT render as a KPI.
        apiGet.mockResolvedValue({ data: { data: { codes_issued: 1, redemptions: 0, k_factor: 'oops' } } });
        render(<InvitationsView />);

        await waitFor(() =>
            expect(screen.getByTestId('admin-invitations-host')).toHaveAttribute('data-state', 'error'),
        );
        expect(screen.queryByTestId('admin-invitations-kpi-codes')).not.toBeInTheDocument();
    });
});
