import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { InsightsResponse } from './insights.api';

/*
 * Phase I — InsightsView unit tests. Mocks the insights hook so the
 * loading / empty / error / ready states can be asserted without
 * hitting the backend. Child cards are not stubbed — the view owns
 * the state contract; cards render unconditionally from props.
 */

type HookMock = {
    data: InsightsResponse | undefined;
    isLoading: boolean;
    isError: boolean;
    error: unknown;
};

const hookMock: HookMock = {
    data: undefined,
    isLoading: false,
    isError: false,
    error: null,
};

vi.mock('./insights.api', () => ({
    useInsightsLatest: () => hookMock,
    useInsightsByDate: () => ({ data: undefined, isLoading: false, isError: false }),
    useComputeInsights: () => ({ mutate: vi.fn(), isPending: false }),
    useDocumentAiSuggestions: () => ({ data: undefined, isLoading: false, isError: false }),
}));

vi.mock('../shell/AdminShell', () => ({
    AdminShell: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="admin-shell">{children}</div>
    ),
}));

import { InsightsView } from './InsightsView';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
    hookMock.data = undefined;
    hookMock.isLoading = false;
    hookMock.isError = false;
    hookMock.error = null;
});

describe('InsightsView', () => {
    it('renders the loading state while the query is in flight', () => {
        hookMock.isLoading = true;
        wrap(<InsightsView />);
        expect(screen.getByTestId('insights-view')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('insights-loading')).toBeInTheDocument();
    });

    it('renders the no-snapshot empty state on 404 from the backend', () => {
        hookMock.isError = true;
        // Axios-style error shape — the view dispatches empty vs error.
        hookMock.error = { response: { status: 404 } };
        wrap(<InsightsView />);
        expect(screen.getByTestId('insights-view')).toHaveAttribute('data-state', 'empty');
        expect(screen.getByTestId('insights-no-snapshot')).toBeInTheDocument();
    });

    it('renders the error state on a non-404 query failure', () => {
        hookMock.isError = true;
        hookMock.error = { response: { status: 500 } };
        wrap(<InsightsView />);
        expect(screen.getByTestId('insights-view')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('insights-error')).toBeInTheDocument();
    });

    it('renders all six cards when a snapshot is ready', () => {
        hookMock.data = {
            data: {
                id: 1,
                snapshot_date: '2026-04-24',
                suggest_promotions: [],
                orphan_docs: [],
                suggested_tags: [],
                coverage_gaps: [],
                stale_docs: [],
                quality_report: {
                    chunk_length_distribution: {
                        under_100: 0,
                        h100_500: 0,
                        h500_1000: 0,
                        h1000_2000: 0,
                        over_2000: 0,
                    },
                    outlier_short: 0,
                    outlier_long: 0,
                    missing_frontmatter: 0,
                    total_docs: 0,
                    total_chunks: 0,
                },
                computed_at: '2026-04-24T05:00:00Z',
                computed_duration_ms: 42,
            },
        };
        wrap(<InsightsView />);

        expect(screen.getByTestId('insights-view')).toHaveAttribute('data-state', 'ready');
        expect(screen.getByTestId('insight-card-promotions')).toBeInTheDocument();
        expect(screen.getByTestId('insight-card-orphans')).toBeInTheDocument();
        expect(screen.getByTestId('insight-card-suggested-tags')).toBeInTheDocument();
        expect(screen.getByTestId('insight-card-coverage-gaps')).toBeInTheDocument();
        expect(screen.getByTestId('insight-card-stale-docs')).toBeInTheDocument();
        expect(screen.getByTestId('insight-card-quality')).toBeInTheDocument();
    });

    it('renders the highlight strip with aggregate counts', () => {
        hookMock.data = {
            data: {
                id: 1,
                snapshot_date: '2026-04-24',
                suggest_promotions: [
                    { document_id: 1, project_key: 'p', slug: null, title: null, reason: '', score: 1 },
                    { document_id: 2, project_key: 'p', slug: null, title: null, reason: '', score: 1 },
                ],
                orphan_docs: [
                    { document_id: 3, project_key: 'p', slug: null, title: null, last_used_at: null, chunks_count: 0 },
                ],
                suggested_tags: [],
                coverage_gaps: [],
                stale_docs: [],
                quality_report: null,
                computed_at: null,
                computed_duration_ms: null,
            },
        };
        wrap(<InsightsView />);

        const strip = screen.getByTestId('insights-highlights');
        expect(strip.querySelector('[data-testid="insights-highlight-promotions"]')?.textContent).toContain('2');
        expect(strip.querySelector('[data-testid="insights-highlight-orphans"]')?.textContent).toContain('1');
        expect(strip.querySelector('[data-testid="insights-highlight-tags"]')?.textContent).toContain('0');
    });
});
