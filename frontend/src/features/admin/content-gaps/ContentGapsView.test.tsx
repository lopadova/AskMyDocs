import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { ContentGapsView } from './ContentGapsView';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPatch = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPatch.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'patch').mockImplementation(mockPatch);
});
afterEach(() => vi.restoreAllMocks());

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

function page(rows: unknown[]) {
    return { data: { data: rows, meta: { current_page: 1, last_page: 1, per_page: 20, total: rows.length } } };
}

const GAPS = [
    { id: 1, project_key: 'eng', query_text: 'most asked', reason: 'no_relevant_context', occurrences: 17, last_seen_at: '2026-06-02T00:00:00Z', resolved_at: null },
    { id: 2, project_key: 'eng', query_text: 'less asked', reason: 'llm_self_refusal', occurrences: 4, last_seen_at: '2026-06-02T00:00:00Z', resolved_at: null },
];

describe('ContentGapsView', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<ContentGapsView />));
        expect(screen.getByTestId('admin-content-gaps-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('renders the empty state when there are no gaps', async () => {
        mockGet.mockResolvedValue(page([]));
        render(withQueryClient(<ContentGapsView />));
        const empty = await screen.findByTestId('admin-content-gaps-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('renders gaps ranked with occurrences and reason label', async () => {
        mockGet.mockResolvedValue(page(GAPS));
        render(withQueryClient(<ContentGapsView />));
        await waitFor(() => expect(screen.getByTestId('admin-content-gap-1')).toBeVisible());
        expect(screen.getByTestId('admin-content-gap-1-count')).toHaveTextContent('17×');
        expect(screen.getByTestId('admin-content-gap-1')).toHaveTextContent('most asked');
        expect(screen.getByTestId('admin-content-gap-2')).toHaveTextContent(/model self-refusal/i);
        expect(screen.getByTestId('admin-content-gaps-count')).toHaveTextContent('2 total');
    });

    it('resolving a gap PATCHes the resolve endpoint', async () => {
        mockGet.mockResolvedValue(page(GAPS));
        mockPatch.mockResolvedValue({ data: { ok: true, id: 1, resolved_at: '2026-06-02T01:00:00Z' } });
        render(withQueryClient(<ContentGapsView />));
        await waitFor(() => expect(screen.getByTestId('admin-content-gap-1-resolve')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-content-gap-1-resolve'));
        await waitFor(() => {
            expect(mockPatch).toHaveBeenCalledWith('/api/admin/kb/content-gaps/1/resolve');
        });
    });

    it('surfaces a resolve error in the DOM (not silent)', async () => {
        mockGet.mockResolvedValue(page(GAPS));
        mockPatch.mockRejectedValue(new Error('resolve boom 500'));
        render(withQueryClient(<ContentGapsView />));
        await waitFor(() => expect(screen.getByTestId('admin-content-gap-1-resolve')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-content-gap-1-resolve'));
        const err = await screen.findByTestId('admin-content-gaps-action-error');
        expect(err).toHaveTextContent('resolve boom 500');
    });

    it('changing the reason filter re-queries with the reason param', async () => {
        mockGet.mockResolvedValue(page(GAPS));
        render(withQueryClient(<ContentGapsView />));
        await waitFor(() => expect(screen.getByTestId('admin-content-gap-1')).toBeVisible());
        await userEvent.selectOptions(screen.getByTestId('admin-content-gaps-reason-filter'), 'llm_self_refusal');
        await waitFor(() => {
            expect(mockGet).toHaveBeenLastCalledWith('/api/admin/kb/content-gaps?reason=llm_self_refusal');
        });
    });

    it('renders an error state (not empty) when the query fails', async () => {
        mockGet.mockRejectedValue(new Error('load boom 500'));
        render(withQueryClient(<ContentGapsView />));
        const err = await screen.findByTestId('admin-content-gaps-error');
        expect(err).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('admin-content-gaps-empty')).not.toBeInTheDocument();
    });
});
