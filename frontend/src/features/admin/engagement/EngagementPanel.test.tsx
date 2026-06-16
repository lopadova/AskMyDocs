import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { EngagementPanel } from './EngagementPanel';
import { api } from '../../../lib/api';

afterEach(() => vi.restoreAllMocks());

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

function mockApi(): void {
    vi.spyOn(api, 'get').mockImplementation((url: string) => {
        if (url.includes('/engagement/summary')) {
            return Promise.resolve({ data: { source: 'live', snapshot_date: null, computed_at: null, metrics: { contributors: 3, new_docs: 2, promoted_docs: 1, canonical_coverage_pct: 60, open_gaps: 4, avg_debt_score: 41 } } } as never);
        }
        if (url.includes('/engagement/leaderboard')) {
            return Promise.resolve({ data: { leaderboard: [{ user_id: 9, name: 'Ada', score: 21, events: 4 }] } } as never);
        }
        // series — empty → deterministic EmptyChart (no recharts mount in jsdom)
        return Promise.resolve({ data: { series: [] } } as never);
    });
}

describe('EngagementPanel', () => {
    it('renders the KPIs, the leaderboard, and an empty trend placeholder', async () => {
        mockApi();
        render(wrapped(<EngagementPanel />));

        await waitFor(() => expect(screen.getByTestId('admin-engagement')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('kpi-card-eng-contributors')).toHaveTextContent('3');
        expect(screen.getByTestId('kpi-card-eng-coverage')).toHaveTextContent('60%');
        expect(screen.getByTestId('admin-engagement-leaderboard-row')).toHaveTextContent('Ada');
        expect(screen.getByTestId('chart-card-engagement-trend')).toHaveAttribute('data-state', 'empty');
    });

    it('shows the leaderboard error when that query fails (R14)', async () => {
        vi.spyOn(api, 'get').mockImplementation((url: string) => {
            if (url.includes('/engagement/leaderboard')) {
                return Promise.reject(new Error('500'));
            }
            if (url.includes('/engagement/summary')) {
                return Promise.resolve({ data: { source: 'live', snapshot_date: null, computed_at: null, metrics: {} } } as never);
            }
            return Promise.resolve({ data: { series: [] } } as never);
        });
        render(wrapped(<EngagementPanel />));

        await waitFor(() => expect(screen.getByTestId('admin-engagement-leaderboard-error')).toBeInTheDocument());
    });
});
