import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { KbInsightsView } from './KbInsightsView';
import { api } from '../../../lib/api';

const mockGet = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
});
afterEach(() => vi.restoreAllMocks());

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

function page(rows: unknown[]) {
    return { data: { data: rows, meta: { current_page: 1, last_page: 1, per_page: 20, total: rows.length } } };
}

const COMPLETED = {
    id: 1,
    project_key: 'eng',
    knowledge_document_id: 7,
    document_title: 'Caching strategy',
    doc_slug: 'caching',
    trigger: 'modified',
    analysis_json: {
        enhancement_suggestions: ['Add a TTL rationale'],
        cross_references: [{ slug: 'dec', title: 'Decision', why: 'related' }],
        impacted_docs: [{ slug: 'old', title: 'Old cache', impact: 'superseded', suggested_action: 'deprecate' }],
    },
    suggestion_count: 1,
    impacted_count: 1,
    status: 'completed',
    provider: 'test',
    model: 'm',
    error: null,
    created_at: '2026-06-02T00:00:00Z',
};

describe('KbInsightsView', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<KbInsightsView />));
        expect(screen.getByTestId('admin-kb-insights-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('renders the empty state when there are no analyses', async () => {
        mockGet.mockResolvedValue(page([]));
        render(withQueryClient(<KbInsightsView />));
        const empty = await screen.findByTestId('admin-kb-insights-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('renders a completed analysis card with suggestions + impacted docs', async () => {
        mockGet.mockResolvedValue(page([COMPLETED]));
        render(withQueryClient(<KbInsightsView />));
        await waitFor(() => expect(screen.getByTestId('admin-kb-insight-1')).toBeVisible());
        expect(screen.getByTestId('admin-kb-insight-1')).toHaveAttribute('data-analysis-status', 'completed');
        expect(screen.getByTestId('admin-kb-insight-1-suggestions')).toHaveTextContent('Add a TTL rationale');
        expect(screen.getByTestId('admin-kb-insight-1-impacted')).toHaveTextContent('deprecate');
        expect(screen.getByTestId('admin-kb-insights-count')).toHaveTextContent('1 total');
    });

    it('renders a failed analysis with its error and no suggestions', async () => {
        const failed = { ...COMPLETED, id: 2, status: 'failed', error: 'provider down', analysis_json: { enhancement_suggestions: [], cross_references: [], impacted_docs: [] } };
        mockGet.mockResolvedValue(page([failed]));
        render(withQueryClient(<KbInsightsView />));
        await waitFor(() => expect(screen.getByTestId('admin-kb-insight-2')).toBeVisible());
        expect(screen.getByTestId('admin-kb-insight-2-error')).toHaveTextContent('provider down');
        expect(screen.queryByTestId('admin-kb-insight-2-suggestions')).not.toBeInTheDocument();
    });

    it('renders a deleted-trigger card with impacted docs and no suggestions', async () => {
        const deleted = {
            ...COMPLETED,
            id: 3,
            trigger: 'deleted',
            suggestion_count: 0,
            analysis_json: {
                enhancement_suggestions: [],
                cross_references: [{ slug: 'runbook', title: 'Cache runbook', why: 'linked the decision' }],
                impacted_docs: [{ slug: 'runbook', title: 'Cache runbook', impact: 'dangling link', suggested_action: 'update: drop the reference' }],
            },
        };
        mockGet.mockResolvedValue(page([deleted]));
        render(withQueryClient(<KbInsightsView />));
        await waitFor(() => expect(screen.getByTestId('admin-kb-insight-3')).toBeVisible());
        // The trigger label surfaces the deletion (rendered verbatim, uppercased).
        expect(screen.getByTestId('admin-kb-insight-3')).toHaveTextContent(/deleted/i);
        expect(screen.getByTestId('admin-kb-insight-3-impacted')).toHaveTextContent('drop the reference');
        // A deletion never has enhancement suggestions.
        expect(screen.queryByTestId('admin-kb-insight-3-suggestions')).not.toBeInTheDocument();
    });

    it('renders an error state (not empty) when the query fails', async () => {
        mockGet.mockRejectedValue(new Error('boom 500'));
        render(withQueryClient(<KbInsightsView />));
        const err = await screen.findByTestId('admin-kb-insights-error');
        expect(err).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('admin-kb-insights-empty')).not.toBeInTheDocument();
    });

    it('changing the status filter re-queries with the status param', async () => {
        mockGet.mockResolvedValue(page([COMPLETED]));
        render(withQueryClient(<KbInsightsView />));
        await waitFor(() => expect(screen.getByTestId('admin-kb-insight-1')).toBeVisible());
        await userEvent.selectOptions(screen.getByTestId('admin-kb-insights-status-filter'), 'failed');
        await waitFor(() => {
            expect(mockGet).toHaveBeenLastCalledWith('/api/admin/kb/analyses?status=failed');
        });
    });
});
