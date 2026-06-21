import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { CitationDocumentModal } from './CitationDocumentModal';
import type { MessageCitation } from './chat.api';
import { api } from '../../lib/api';

const mockGet = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
});
afterEach(() => vi.restoreAllMocks());

function wrap(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const CITATION: MessageCitation = {
    document_id: 7,
    title: 'Cache decision',
    source_path: 'decisions/dec-cache.md',
    slug: 'dec-cache',
    project_key: 'eng',
    origin: 'primary',
};

function docPayload(content: string) {
    return {
        data: {
            document_id: 7,
            title: 'Cache decision',
            source_path: 'decisions/dec-cache.md',
            slug: 'dec-cache',
            project_key: 'eng',
            source_type: 'markdown',
            canonical_type: 'decision',
            canonical_status: 'accepted',
            is_canonical: true,
            content,
        },
    };
}

describe('CitationDocumentModal', () => {
    it('fetches the cited document and renders its source content', async () => {
        mockGet.mockResolvedValue(docPayload('# Cache\n\nWe chose Redis for hot reads.'));

        render(wrap(<CitationDocumentModal citation={CITATION} onClose={() => {}} />));

        expect(screen.getByTestId('chat-citation-modal-title')).toHaveTextContent('Cache decision');
        expect(screen.getByTestId('chat-citation-modal-path')).toHaveTextContent('decisions/dec-cache.md');

        const content = await screen.findByTestId('chat-citation-modal-content');
        expect(content).toHaveTextContent('We chose Redis for hot reads.');
        // The lifecycle state lives on the component-owned body (not the Radix
        // DialogContent, whose own data-state="open|closed" would collide).
        expect(screen.getByTestId('chat-citation-modal-body')).toHaveAttribute('data-state', 'ready');
        expect(mockGet).toHaveBeenCalledWith('/api/kb/documents/7/preview');
    });

    it('shows the empty state when the document has no content', async () => {
        mockGet.mockResolvedValue(docPayload(''));

        render(wrap(<CitationDocumentModal citation={CITATION} onClose={() => {}} />));

        const empty = await screen.findByTestId('chat-citation-modal-empty');
        expect(empty).toBeVisible();
        expect(screen.getByTestId('chat-citation-modal-body')).toHaveAttribute('data-state', 'empty');
        expect(screen.queryByTestId('chat-citation-modal-content')).not.toBeInTheDocument();
    });

    it('surfaces a retryable error (not a silent blank) when the fetch fails', async () => {
        mockGet.mockRejectedValue(new Error('boom 500'));

        render(wrap(<CitationDocumentModal citation={CITATION} onClose={() => {}} />));

        const err = await screen.findByTestId('chat-citation-modal-error');
        expect(err).toHaveAttribute('role', 'alert');
        expect(screen.getByTestId('chat-citation-modal-retry')).toBeVisible();
        expect(screen.queryByTestId('chat-citation-modal-content')).not.toBeInTheDocument();
        expect(screen.queryByTestId('chat-citation-modal-empty')).not.toBeInTheDocument();
    });

    it('closes via the close button', async () => {
        mockGet.mockResolvedValue(docPayload('body'));
        const onClose = vi.fn();

        render(wrap(<CitationDocumentModal citation={CITATION} onClose={onClose} />));

        await userEvent.click(screen.getByTestId('chat-citation-modal-close'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('shows the admin "open in KB" action only when wired', async () => {
        mockGet.mockResolvedValue(docPayload('body'));
        const onOpenInKb = vi.fn();

        const { rerender } = render(
            wrap(<CitationDocumentModal citation={CITATION} onClose={() => {}} />),
        );
        // No handler → no admin action.
        expect(screen.queryByTestId('chat-citation-modal-open-kb')).not.toBeInTheDocument();

        rerender(wrap(<CitationDocumentModal citation={CITATION} onClose={() => {}} onOpenInKb={onOpenInKb} />));
        const openKb = screen.getByTestId('chat-citation-modal-open-kb');
        await userEvent.click(openKb);
        expect(onOpenInKb).toHaveBeenCalledWith(CITATION);
    });
});
