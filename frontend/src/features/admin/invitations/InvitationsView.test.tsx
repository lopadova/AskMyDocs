import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { InvitationsView } from './InvitationsView';

/*
 * InvitationsView unit tests — the native host landing for the
 * padosoft/laravel-invitations-admin panel. The view reads live funnel KPIs
 * over raw fetch from /api/admin/invitations/metrics and links out to the
 * mounted Blade SPA. R16: each test drives the state it claims. R14: a
 * non-ok response AND a malformed-shape 200 both resolve to the error state,
 * never NaN KPIs.
 */

// The view reads useTeamStore.getState().currentTeam to replicate the
// X-Tenant-Id header by hand (the `default` sentinel skip). Default tenant →
// no header; the test only needs getState() to exist.
vi.mock('../../../lib/team-store', () => ({
    useTeamStore: { getState: () => ({ currentTeam: 'default' }) },
    selectCurrentHash: () => 'default',
}));

function mockFetchOnce(impl: () => Promise<Response> | Response): void {
    vi.stubGlobal('fetch', vi.fn(impl));
}

function jsonResponse(body: unknown, ok = true, status = 200): Response {
    return {
        ok,
        status,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

describe('InvitationsView', () => {
    beforeEach(() => {
        vi.useRealTimers();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('renders the open-panel link to the mounted Blade SPA', () => {
        mockFetchOnce(() => new Promise(() => undefined)); // never resolves → stays loading
        render(<InvitationsView />);

        const link = screen.getByTestId('admin-invitations-open-panel');
        expect(link).toHaveAttribute('href', '/admin/invitations');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('shows live KPIs from a valid metrics response (ready state)', async () => {
        mockFetchOnce(() =>
            Promise.resolve(
                jsonResponse({
                    data: {
                        codes_issued: 42,
                        redemptions: 7,
                        k_factor: 1.5,
                        // extra keys the view ignores must not break it
                        invites_sent: 10,
                    },
                }),
            ),
        );
        render(<InvitationsView />);

        await waitFor(() =>
            expect(screen.getByTestId('admin-invitations-host')).toHaveAttribute('data-state', 'ready'),
        );
        expect(screen.getByTestId('admin-invitations-kpi-codes')).toHaveTextContent('42');
        expect(screen.getByTestId('admin-invitations-kpi-redemptions')).toHaveTextContent('7');
        expect(screen.getByTestId('admin-invitations-kpi-k-factor')).toHaveTextContent('1.5');
        // No error banner in the ready state.
        expect(screen.queryByTestId('admin-invitations-error')).not.toBeInTheDocument();
    });

    it('surfaces the error state on a non-ok response (R14)', async () => {
        mockFetchOnce(() => Promise.resolve(jsonResponse({ message: 'Forbidden' }, false, 403)));
        render(<InvitationsView />);

        await waitFor(() =>
            expect(screen.getByTestId('admin-invitations-host')).toHaveAttribute('data-state', 'error'),
        );
        expect(screen.getByTestId('admin-invitations-error')).toBeInTheDocument();
        // The open-panel link is still offered as a fallback.
        expect(screen.getByTestId('admin-invitations-open-panel')).toBeInTheDocument();
    });

    it('surfaces the error state on a malformed 200 shape, never NaN KPIs (R14)', async () => {
        mockFetchOnce(() =>
            // 200 OK but k_factor is a string → must NOT render as a KPI.
            Promise.resolve(jsonResponse({ data: { codes_issued: 1, redemptions: 0, k_factor: 'oops' } })),
        );
        render(<InvitationsView />);

        await waitFor(() =>
            expect(screen.getByTestId('admin-invitations-host')).toHaveAttribute('data-state', 'error'),
        );
        expect(screen.queryByTestId('admin-invitations-kpi-codes')).not.toBeInTheDocument();
    });
});
