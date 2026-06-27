import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { InvitationsView } from './InvitationsView';
import { api } from '../../../lib/api';
import { useAuthStore } from '../../../lib/auth-store';

/*
 * InvitationsView is now an in-app tabbed surface over the core invitations
 * API. These tests cover: the tab shell + switching, and — crucially for R43 —
 * the "Advanced panel" launcher rendering in BOTH flag states (hidden when the
 * package mount is OFF so it never links to a 404, shown when ON).
 */

const mockGet = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    // Overview is the default tab → it fetches metrics + campaigns on mount.
    mockGet.mockImplementation((url: string) => {
        if (url.startsWith('/api/admin/invitations/metrics')) {
            return Promise.resolve({
                data: {
                    data: {
                        codes_issued: 0,
                        redemptions: 0,
                        invites_sent: 0,
                        invites_accepted: 0,
                        referrals_qualified: 0,
                        distinct_referrers: 0,
                        k_factor: 0,
                        acceptance_rate: 0,
                        conversion_rate: 0,
                        ttr_p50_seconds: null,
                        ttr_p90_seconds: null,
                    },
                },
            });
        }
        return Promise.resolve({ data: { data: [] } });
    });
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    // Default: package panel mount OFF (the fresh-deploy state).
    useAuthStore.setState({ features: {} });
});

afterEach(() => {
    vi.restoreAllMocks();
    useAuthStore.setState({ features: {} });
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('InvitationsView', () => {
    it('renders the tab shell with Overview active by default', () => {
        render(withQueryClient(<InvitationsView />));
        expect(screen.getByTestId('admin-invitations')).toBeVisible();
        expect(screen.getByTestId('admin-invitations-tab-overview')).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByTestId('admin-invitations-panel-overview')).toBeVisible();
    });

    it('hides the Advanced panel launcher when the package mount is OFF (R14/R43 — no dead 404 link)', () => {
        useAuthStore.setState({ features: { invitations_admin: false } });
        render(withQueryClient(<InvitationsView />));
        expect(screen.queryByTestId('admin-invitations-open-panel')).not.toBeInTheDocument();
    });

    it('shows the Advanced panel launcher only when the mount is enabled (R43 ON state)', () => {
        useAuthStore.setState({ features: { invitations_admin: true } });
        render(withQueryClient(<InvitationsView />));
        const link = screen.getByTestId('admin-invitations-open-panel');
        expect(link).toHaveAttribute('href', '/admin/invitations');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('switching tabs swaps the active panel (R16 — drives the transition)', async () => {
        render(withQueryClient(<InvitationsView />));
        await userEvent.click(screen.getByTestId('admin-invitations-tab-codes'));
        await waitFor(() => {
            expect(screen.getByTestId('admin-invitations-tab-codes')).toHaveAttribute('aria-selected', 'true');
        });
        expect(screen.getByTestId('admin-invitations-panel-codes')).toBeVisible();
        expect(screen.queryByTestId('admin-invitations-panel-overview')).not.toBeInTheDocument();
    });
});
