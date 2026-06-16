import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { MeDashboard } from './MeDashboard';
import { api } from '../../lib/api';

const DASH = {
    window_days: 30,
    dashboard: {
        window_days: 30,
        contributions: { score: 13, events: 2, by_event: { created: 1, promoted: 1 }, citations: 4 },
        rank: 1,
        authored_docs: 1,
        questions_asked: 5,
        active_days: 3,
        docs_needing_review: [{ title: 'Old policy', slug: null, debt_score: 88 }],
    },
};

afterEach(() => vi.restoreAllMocks());

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('MeDashboard', () => {
    it('renders the KPI tiles and the review list when loaded', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: DASH } as never);
        render(wrapped(<MeDashboard />));

        await waitFor(() => expect(screen.getByTestId('me-dashboard')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('kpi-card-me-score')).toHaveTextContent('13');
        expect(screen.getByTestId('kpi-card-me-rank')).toBeInTheDocument();
        expect(screen.getByTestId('me-dashboard-review-item-0')).toHaveTextContent('Old policy');
    });

    it('shows the empty review state when nothing needs review', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: { ...DASH, dashboard: { ...DASH.dashboard, docs_needing_review: [] } } } as never);
        render(wrapped(<MeDashboard />));

        await waitFor(() => expect(screen.getByTestId('me-dashboard')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('me-dashboard-review-empty')).toBeInTheDocument();
    });

    it('surfaces an error state (R14)', async () => {
        vi.spyOn(api, 'get').mockRejectedValue(new Error('500'));
        render(wrapped(<MeDashboard />));

        await waitFor(() => expect(screen.getByTestId('me-dashboard')).toHaveAttribute('data-state', 'error'));
        expect(screen.getByTestId('me-dashboard-error')).toBeInTheDocument();
    });
});
