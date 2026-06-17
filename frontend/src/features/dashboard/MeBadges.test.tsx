import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { MeBadges } from './MeBadges';
import { api } from '../../lib/api';

afterEach(() => vi.restoreAllMocks());

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('MeBadges', () => {
    it('renders nothing when gamification is disabled', async () => {
        const getSpy = vi.spyOn(api, 'get').mockResolvedValue({ data: { enabled: false, badges: [] } } as never);
        render(wrapped(<MeBadges />));
        // Wait on an observable condition (the query firing) instead of a timer.
        await waitFor(() => expect(getSpy).toHaveBeenCalled());
        expect(screen.queryByTestId('me-badges')).not.toBeInTheDocument();
    });

    it('surfaces a backend failure instead of swallowing it', async () => {
        vi.spyOn(api, 'get').mockRejectedValue(new Error('boom'));
        render(wrapped(<MeBadges />));
        await waitFor(() => expect(screen.getByTestId('me-badges')).toHaveAttribute('data-state', 'error'));
        expect(screen.getByTestId('me-badges-retry')).toBeInTheDocument();
    });

    it('renders earned + locked badges when enabled', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({
            data: {
                enabled: true,
                badges: [
                    { key: 'contributor', label: 'Contributor', icon: '✍️', metric: 'score', threshold: 25, progress: 25, earned: true, awarded_at: '2026-06-17T00:00:00Z' },
                    { key: 'author', label: 'Author', icon: '📚', metric: 'authored', threshold: 5, progress: 1, earned: false, awarded_at: null },
                ],
            },
        } as never);
        render(wrapped(<MeBadges />));

        await waitFor(() => expect(screen.getByTestId('me-badges')).toBeInTheDocument());
        expect(screen.getByTestId('me-badge-contributor')).toHaveAttribute('data-earned', 'true');
        expect(screen.getByTestId('me-badge-author')).toHaveAttribute('data-earned', 'false');
    });
});
