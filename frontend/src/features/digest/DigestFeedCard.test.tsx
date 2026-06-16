import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { DigestFeedCard } from './DigestFeedCard';
import { api } from '../../lib/api';

const LATEST = {
    has_digest: true,
    generated_at: '2026-06-16T07:00:00Z',
    enabled_sections: ['metrics', 'new_docs', 'top_gaps', 'leaderboard'],
    digest: {
        frequency: 'weekly',
        narrative: 'Three new docs landed and one question still needs an answer.',
        metrics: { contributors: 2, new_docs: 3, promoted_docs: 1, open_gaps: 1 },
        new_docs: [{ title: 'Onboarding', project_key: 'hr', change: 'created' }],
        stale_docs: [{ title: 'Old policy', debt_score: 80, age_days: 200 }],
        top_gaps: [{ question: 'how to deploy', occurrences: 9 }],
        leaderboard: [{ name: 'Ada', score: 21 }],
    },
};

afterEach(() => vi.restoreAllMocks());

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('DigestFeedCard', () => {
    it('renders the enabled sections and the narrative when a digest exists', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: LATEST } as never);
        render(wrapped(<DigestFeedCard />));

        await waitFor(() => expect(screen.getByTestId('digest-feed-card')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('digest-feed-narrative')).toBeInTheDocument();
        expect(screen.getByTestId('digest-feed-metrics')).toBeInTheDocument();
        expect(screen.getByTestId('digest-feed-new-docs')).toBeInTheDocument();
        expect(screen.getByTestId('digest-feed-gaps')).toBeInTheDocument();
        expect(screen.getByTestId('digest-feed-leaderboard')).toBeInTheDocument();
        // stale_docs is NOT in enabled_sections → must be hidden.
        expect(screen.queryByTestId('digest-feed-stale')).not.toBeInTheDocument();
    });

    it('shows the empty state when no digest has been generated', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: { has_digest: false, digest: null, generated_at: null, enabled_sections: [] } } as never);
        render(wrapped(<DigestFeedCard />));

        await waitFor(() => expect(screen.getByTestId('digest-feed-card')).toHaveAttribute('data-state', 'empty'));
        expect(screen.getByTestId('digest-feed-empty')).toBeInTheDocument();
    });

    it('surfaces an error state when the request fails (R14)', async () => {
        vi.spyOn(api, 'get').mockRejectedValue(new Error('500'));
        render(wrapped(<DigestFeedCard />));

        await waitFor(() => expect(screen.getByTestId('digest-feed-card')).toHaveAttribute('data-state', 'error'));
        expect(screen.getByTestId('digest-feed-error')).toBeInTheDocument();
    });
});
