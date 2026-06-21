import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { GamificationInsightsPanel } from './GamificationInsightsPanel';
import { api } from '../../../lib/api';
import { useAuthStore } from '../../../lib/auth-store';

afterEach(() => {
    vi.restoreAllMocks();
    // Restore the auth store so a super-admin test cannot leak its role
    // into a sibling test (R16 — restore mutated global state).
    useAuthStore.getState().clear();
});

function asSuperAdmin(): void {
    useAuthStore.getState().setMe({
        user: { id: 1, name: 'Root', email: 'super@demo.local' },
        roles: ['super-admin'],
        permissions: [],
        projects: [],
    });
}

function asAdmin(): void {
    useAuthStore.getState().setMe({
        user: { id: 2, name: 'Admin', email: 'admin@demo.local' },
        roles: ['admin'],
        permissions: [],
        projects: [],
    });
}

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

function readyResponse() {
    return {
        available: true,
        scope: 'tenant',
        scope_id: '',
        insight: {
            scope_type: 'tenant',
            scope_id: '',
            period_label: 'June 2026',
            metrics: { contributors: 5 },
            narrative: {
                headline: 'Healthy knowledge base',
                summary: 'Activity is up this period.',
                actions: ['Review stale runbooks'],
                advice: ['Encourage cross-team authoring'],
            },
            titles: [],
            model: 'claude-x',
            computed_at: '2026-06-19T00:00:00Z',
        },
    };
}

describe('GamificationInsightsPanel', () => {
    beforeEach(() => asAdmin());

    it('shows the loading state before the query resolves', async () => {
        vi.spyOn(api, 'get').mockReturnValue(new Promise(() => {}) as never);
        render(wrapped(<GamificationInsightsPanel />));
        await waitFor(() => expect(screen.getByTestId('admin-gamification-insights')).toHaveAttribute('data-state', 'loading'));
    });

    it('renders the project/tenant health narrative when available', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: readyResponse() } as never);
        render(wrapped(<GamificationInsightsPanel />));

        await waitFor(() => expect(screen.getByTestId('admin-gamification-insights')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('admin-gamification-insights-headline')).toHaveTextContent('Healthy knowledge base');
        expect(screen.getByTestId('admin-gamification-insights-actions')).toHaveTextContent('Review stale runbooks');
        expect(screen.getByTestId('admin-gamification-insights-advice')).toHaveTextContent('Encourage cross-team authoring');
    });

    it('renders an empty state when not available (not null)', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: { available: false, scope: 'tenant', scope_id: '', insight: null } } as never);
        render(wrapped(<GamificationInsightsPanel />));

        // R16 — assert the empty-state testid, not "ready".
        await waitFor(() => expect(screen.getByTestId('admin-gamification-insights')).toHaveAttribute('data-state', 'empty'));
        expect(screen.getByTestId('admin-gamification-insights-empty')).toBeInTheDocument();
    });

    it('surfaces a backend failure with a retry (R14)', async () => {
        vi.spyOn(api, 'get').mockRejectedValue(new Error('500'));
        render(wrapped(<GamificationInsightsPanel />));

        await waitFor(() => expect(screen.getByTestId('admin-gamification-insights')).toHaveAttribute('data-state', 'error'));
        expect(screen.getByTestId('admin-gamification-insights-error')).toBeInTheDocument();
        expect(screen.getByTestId('admin-gamification-insights-retry')).toBeInTheDocument();
    });

    it('hides the Rigenera button for plain admins', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: readyResponse() } as never);
        render(wrapped(<GamificationInsightsPanel />));

        await waitFor(() => expect(screen.getByTestId('admin-gamification-insights')).toHaveAttribute('data-state', 'ready'));
        expect(screen.queryByTestId('admin-gamification-regenerate')).not.toBeInTheDocument();
    });

    it('super-admin can click Rigenera — it POSTs and refetches (R16)', async () => {
        asSuperAdmin();
        const getSpy = vi.spyOn(api, 'get').mockResolvedValue({ data: readyResponse() } as never);
        const postSpy = vi.spyOn(api, 'post').mockResolvedValue({
            data: { regenerated: true, result: { period: '2026-06', users: 3, projects: 2, tenant: 1 } },
        } as never);
        render(wrapped(<GamificationInsightsPanel />));

        await waitFor(() => expect(screen.getByTestId('admin-gamification-insights')).toHaveAttribute('data-state', 'ready'));
        const getCallsBefore = getSpy.mock.calls.length;

        await userEvent.click(screen.getByTestId('admin-gamification-regenerate'));

        // The mutation actually fired the POST...
        await waitFor(() => expect(postSpy).toHaveBeenCalledWith('/api/admin/engagement/insights/regenerate'));
        // ...and the success handler invalidated → refetched the insights query.
        await waitFor(() => expect(getSpy.mock.calls.length).toBeGreaterThan(getCallsBefore));
    });
});
