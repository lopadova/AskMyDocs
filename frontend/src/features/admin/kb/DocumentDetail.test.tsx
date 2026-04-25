import { describe, it, expect, vi } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

// Hold the hook stubs in a mutable object so each test can vary the
// surface a DocumentDetail sees without remounting the mock graph.
// Importing the KbDocument type widens `deleted_at` to `string | null`
// so the trashed-state test can flip it to an ISO string without TS
// narrowing the literal back to `null`.
import type { KbDocument } from '../admin.api';

const mockState: { doc: KbDocument; isLoading: boolean; isError: boolean } = {
    doc: {
        id: 7,
        project_key: 'hr-portal',
        source_type: 'md',
        title: 'Remote Work Policy',
        source_path: 'policies/remote-work-policy.md',
        mime_type: 'text/markdown',
        language: 'en',
        access_scope: 'project',
        status: 'indexed',
        document_hash: 'aaaa',
        version_hash: 'bbbb',
        doc_id: 'dec-remote-work',
        slug: 'remote-work-policy',
        canonical_type: 'policy',
        canonical_status: 'accepted',
        is_canonical: true,
        retrieval_priority: 80,
        source_of_truth: true,
        frontmatter: null,
        source_updated_at: null,
        indexed_at: '2026-04-24T09:00:00Z',
        created_at: '2026-04-24T08:00:00Z',
        updated_at: '2026-04-24T09:00:00Z',
        deleted_at: null,
        metadata_tags: [],
        tags: [],
        chunks_count: 2,
        audits_count: 0,
        recent_audits: [],
    },
    isLoading: false,
    isError: false,
};

vi.mock('./kb-document.api', () => ({
    useKbDocument: () => ({
        data: mockState.isError ? undefined : mockState.doc,
        isLoading: mockState.isLoading,
        isError: mockState.isError,
    }),
    // PreviewTab + HistoryTab still get their own lightweight stubs so
    // a tab switch doesn't trigger a real fetch.
    useKbRaw: () => ({ data: undefined, isLoading: true, isError: false }),
    useKbHistory: () => ({
        data: { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } },
        isLoading: false,
        isError: false,
    }),
    useRestoreKbDocument: () => ({ mutate: vi.fn(), isPending: false }),
    useDeleteKbDocument: () => ({ mutate: vi.fn(), isPending: false }),
    // Phase G4 — export-pdf mutation is read by DocumentDetail via the
    // header action. The stub keeps it non-pending so the button is
    // enabled for interaction assertions.
    useExportPdf: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { DocumentDetail } from './DocumentDetail';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('DocumentDetail', () => {
    it('renders loading state', () => {
        mockState.isLoading = true;
        mockState.isError = false;
        wrap(
            <DocumentDetail
                documentId={7}
                activeTab="preview"
                onTabChange={vi.fn()}
            />,
        );
        expect(screen.getByTestId('kb-detail-loading')).toBeInTheDocument();
        mockState.isLoading = false;
    });

    it('renders error state when the query fails', () => {
        mockState.isError = true;
        wrap(
            <DocumentDetail
                documentId={99}
                activeTab="preview"
                onTabChange={vi.fn()}
            />,
        );
        expect(screen.getByTestId('kb-detail-error')).toBeInTheDocument();
        mockState.isError = false;
    });

    it('renders title + canonical pills + header actions', () => {
        wrap(
            <DocumentDetail
                documentId={7}
                activeTab="preview"
                onTabChange={vi.fn()}
            />,
        );
        expect(screen.getByTestId('kb-detail-title')).toHaveTextContent('Remote Work Policy');
        expect(screen.getByTestId('kb-detail-type-pill')).toHaveTextContent('policy');
        expect(screen.getByTestId('kb-detail-status-pill')).toHaveTextContent('accepted');
        // Not trashed → restore hidden, delete visible.
        expect(screen.queryByTestId('kb-detail-trashed-badge')).toBeNull();
        expect(screen.queryByTestId('kb-action-restore')).toBeNull();
        expect(screen.getByTestId('kb-action-delete')).toBeInTheDocument();
        expect(screen.getByTestId('kb-action-force-delete')).toBeInTheDocument();
        expect(screen.getByTestId('kb-action-download')).toBeInTheDocument();
        expect(screen.getByTestId('kb-action-print')).toBeInTheDocument();
    });

    it('renders trashed badge + restore button when deleted_at is set', () => {
        const prev = mockState.doc;
        mockState.doc = { ...prev, deleted_at: '2026-04-20T10:00:00Z' };
        wrap(
            <DocumentDetail
                documentId={7}
                activeTab="preview"
                onTabChange={vi.fn()}
            />,
        );
        expect(screen.getByTestId('kb-detail-trashed-badge')).toBeInTheDocument();
        expect(screen.getByTestId('kb-action-restore')).toBeInTheDocument();
        expect(screen.queryByTestId('kb-action-delete')).toBeNull();
        mockState.doc = prev;
    });

    it('switches tabs via the tab strip', async () => {
        const onTab = vi.fn();
        wrap(
            <DocumentDetail
                documentId={7}
                activeTab="preview"
                onTabChange={onTab}
            />,
        );
        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-tab-meta'));
        });
        expect(onTab).toHaveBeenCalledWith('meta');
    });

    it('opens confirm dialog before soft delete and cancels cleanly', async () => {
        wrap(
            <DocumentDetail
                documentId={7}
                activeTab="preview"
                onTabChange={vi.fn()}
            />,
        );
        expect(screen.queryByTestId('kb-detail-confirm')).toBeNull();
        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-action-delete'));
        });
        const dialog = screen.getByTestId('kb-detail-confirm');
        expect(dialog).toHaveAttribute('data-mode', 'soft');
        await act(async () => {
            await userEvent.click(screen.getByTestId('kb-detail-confirm-cancel'));
        });
        expect(screen.queryByTestId('kb-detail-confirm')).toBeNull();
    });
});
