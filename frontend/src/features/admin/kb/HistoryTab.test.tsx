import { describe, it, expect, vi } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const historyState: {
    pageByRequest: number;
    data:
        | {
              data: Array<{
                  id: number;
                  project_key: string;
                  doc_id: string | null;
                  slug: string | null;
                  event_type: string;
                  actor: string;
                  before_json: Record<string, unknown> | null;
                  after_json: Record<string, unknown> | null;
                  metadata_json: Record<string, unknown> | null;
                  created_at: string | null;
              }>;
              meta: { current_page: number; per_page: number; total: number; last_page: number };
          }
        | undefined;
    isLoading: boolean;
    isError: boolean;
} = {
    pageByRequest: 1,
    data: undefined,
    isLoading: false,
    isError: false,
};

vi.mock('./kb-document.api', () => ({
    useKbHistory: (_id: number | null, page: number) => {
        historyState.pageByRequest = page;
        return {
            data: historyState.data,
            isLoading: historyState.isLoading,
            isError: historyState.isError,
        };
    },
}));

import { HistoryTab } from './HistoryTab';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

function seedHistory() {
    historyState.data = {
        data: [
            {
                id: 11,
                project_key: 'hr-portal',
                doc_id: 'dec-x',
                slug: 'remote-work',
                event_type: 'promoted',
                actor: 'admin@demo.local',
                before_json: null,
                after_json: { status: 'accepted' },
                metadata_json: null,
                created_at: '2026-04-24T10:00:00Z',
            },
            {
                id: 12,
                project_key: 'hr-portal',
                doc_id: 'dec-x',
                slug: 'remote-work',
                event_type: 'updated',
                actor: 'admin@demo.local',
                before_json: { status: 'draft' },
                after_json: { status: 'accepted' },
                metadata_json: null,
                created_at: '2026-04-24T11:00:00Z',
            },
        ],
        meta: { current_page: 1, per_page: 20, total: 40, last_page: 2 },
    };
}

describe('HistoryTab', () => {
    it('renders rows with stable testids', () => {
        seedHistory();
        wrap(<HistoryTab documentId={7} />);
        expect(screen.getByTestId('kb-history-11')).toBeInTheDocument();
        expect(screen.getByTestId('kb-history-12')).toBeInTheDocument();
    });

    it('renders the empty state when no rows come back', () => {
        historyState.data = {
            data: [],
            meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 },
        };
        wrap(<HistoryTab documentId={7} />);
        expect(screen.getByTestId('kb-history-empty')).toBeInTheDocument();
    });

    it('advances the page when Next is clicked', async () => {
        seedHistory();
        wrap(<HistoryTab documentId={7} />);

        // Sanity: Next must be enabled because last_page > current_page.
        const next = screen.getByTestId('kb-history-next');
        expect(next).not.toBeDisabled();
        await act(async () => {
            await userEvent.click(next);
        });
        expect(historyState.pageByRequest).toBe(2);
    });

    it('disables Prev on page 1', () => {
        seedHistory();
        wrap(<HistoryTab documentId={7} />);
        expect(screen.getByTestId('kb-history-prev')).toBeDisabled();
    });
});
