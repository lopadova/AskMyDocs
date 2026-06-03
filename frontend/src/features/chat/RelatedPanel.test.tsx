import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { RelatedPanel } from './RelatedPanel';
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

const RELATED = {
    data: {
        related: [
            { slug: 'dec-redis', title: 'Redis decision', edge_type: 'depends_on', direction: 'outgoing', weight: 0.9 },
            { slug: 'runbook-cache', title: null, edge_type: 'documented_by', direction: 'incoming', weight: 0.5 },
        ],
        meta: { count: 2 },
    },
};

describe('RelatedPanel', () => {
    it('renders nothing when there are no canonical slugs', () => {
        const { container } = render(wrap(<RelatedPanel projectKey="eng" slugs={[]} />));
        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when projectKey is null', () => {
        const { container } = render(wrap(<RelatedPanel projectKey={null} slugs={['dec-cache']} />));
        expect(container).toBeEmptyDOMElement();
    });

    it('does not fetch until expanded (lazy)', async () => {
        mockGet.mockResolvedValue(RELATED);
        render(wrap(<RelatedPanel projectKey="eng" slugs={['dec-cache']} />));
        expect(screen.getByTestId('chat-related-toggle')).toHaveAttribute('aria-expanded', 'false');
        expect(mockGet).not.toHaveBeenCalled();
    });

    it('fetches and lists neighbours when expanded', async () => {
        mockGet.mockResolvedValue(RELATED);
        render(wrap(<RelatedPanel projectKey="eng" slugs={['dec-cache']} />));
        await userEvent.click(screen.getByTestId('chat-related-toggle'));

        await waitFor(() => expect(screen.getByTestId('chat-related-list')).toBeVisible());
        expect(screen.getByTestId('chat-related-item-dec-redis')).toHaveTextContent('Redis decision');
        expect(screen.getByTestId('chat-related-item-dec-redis')).toHaveAttribute('data-direction', 'outgoing');
        // Null title falls back to the slug.
        expect(screen.getByTestId('chat-related-item-runbook-cache')).toHaveTextContent('runbook-cache');
        expect(mockGet).toHaveBeenCalledWith('/api/kb/related?project_key=eng&slugs%5B%5D=dec-cache');
    });

    it('shows the empty state when the graph has no neighbours', async () => {
        mockGet.mockResolvedValue({ data: { related: [], meta: { count: 0 } } });
        render(wrap(<RelatedPanel projectKey="eng" slugs={['lonely']} />));
        await userEvent.click(screen.getByTestId('chat-related-toggle'));
        const empty = await screen.findByTestId('chat-related-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('surfaces an error (not empty) when the request fails', async () => {
        mockGet.mockRejectedValue(new Error('boom 500'));
        render(wrap(<RelatedPanel projectKey="eng" slugs={['dec-cache']} />));
        await userEvent.click(screen.getByTestId('chat-related-toggle'));
        const err = await screen.findByTestId('chat-related-error');
        expect(err).toHaveTextContent('boom 500');
        expect(screen.queryByTestId('chat-related-empty')).not.toBeInTheDocument();
    });
});
