import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { CoachingCard } from './CoachingCard';
import { api } from '../../lib/api';

afterEach(() => vi.restoreAllMocks());

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

function readyInsight() {
    return {
        available: true,
        insight: {
            scope_type: 'user',
            scope_id: '7',
            period_label: 'June 2026',
            metrics: { docs: 4 },
            narrative: {
                headline: 'Strong contributor month',
                strengths: ['Consistent authoring'],
                growth: ['Add more citations'],
                next_steps: ['Promote two drafts'],
                summary: 'You are trending up.',
            },
            titles: [
                { key: 'curator', label: 'Curator', icon: '📚', reason: 'Authored 4 docs' },
            ],
            model: 'claude-x',
            computed_at: '2026-06-19T00:00:00Z',
        },
    };
}

describe('CoachingCard', () => {
    it('shows the loading state before the query resolves', async () => {
        // Never-resolving promise keeps the query in flight.
        vi.spyOn(api, 'get').mockReturnValue(new Promise(() => {}) as never);
        render(wrapped(<CoachingCard />));
        await waitFor(() => expect(screen.getByTestId('me-coaching')).toHaveAttribute('data-state', 'loading'));
    });

    it('renders the coaching narrative + titles when available', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: readyInsight() } as never);
        render(wrapped(<CoachingCard />));

        await waitFor(() => expect(screen.getByTestId('me-coaching')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('me-coaching-headline')).toHaveTextContent('Strong contributor month');
        expect(screen.getByTestId('me-coaching-title-curator')).toHaveTextContent('Curator');
    });

    it('renders a friendly empty state when not available (not null)', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: { available: false, insight: null } } as never);
        render(wrapped(<CoachingCard />));

        // R16 — assert the empty-state testid, not "ready".
        await waitFor(() => expect(screen.getByTestId('me-coaching')).toHaveAttribute('data-state', 'empty'));
        expect(screen.getByTestId('me-coaching-empty')).toBeInTheDocument();
        expect(screen.queryByTestId('me-coaching-headline')).not.toBeInTheDocument();
    });

    it('surfaces a backend failure with a retry instead of swallowing it (R14)', async () => {
        vi.spyOn(api, 'get').mockRejectedValue(new Error('boom'));
        render(wrapped(<CoachingCard />));

        await waitFor(() => expect(screen.getByTestId('me-coaching')).toHaveAttribute('data-state', 'error'));
        expect(screen.getByTestId('me-coaching-retry')).toBeInTheDocument();
    });
});
