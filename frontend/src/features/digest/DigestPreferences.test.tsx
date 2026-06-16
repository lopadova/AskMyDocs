import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { DigestPreferences } from './DigestPreferences';
import { api } from '../../lib/api';

const PREFS = {
    frequency: 'weekly',
    sections: ['metrics', 'new_docs', 'stale_docs', 'top_gaps', 'leaderboard'],
    available_frequencies: ['weekly', 'monthly', 'off'],
    available_sections: ['metrics', 'new_docs', 'stale_docs', 'top_gaps', 'leaderboard'],
};

beforeEach(() => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: PREFS } as never);
    vi.spyOn(api, 'put').mockResolvedValue({ data: { ...PREFS, frequency: 'monthly' } } as never);
});

afterEach(() => vi.restoreAllMocks());

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('DigestPreferences', () => {
    it('loads then shows ready with the current frequency checked', async () => {
        render(wrapped(<DigestPreferences />));
        await waitFor(() => expect(screen.getByTestId('digest-pref')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('digest-pref-frequency-weekly')).toBeChecked();
        expect(screen.getByTestId('digest-pref-save')).toBeDisabled();
    });

    it('changing frequency marks dirty and enables Save', async () => {
        const user = userEvent.setup();
        render(wrapped(<DigestPreferences />));
        await waitFor(() => expect(screen.getByTestId('digest-pref')).toHaveAttribute('data-state', 'ready'));

        await user.click(screen.getByTestId('digest-pref-frequency-monthly'));
        expect(screen.getByTestId('digest-pref-dirty')).toBeInTheDocument();
        expect(screen.getByTestId('digest-pref-save')).toBeEnabled();
    });

    it('saves and shows the success status', async () => {
        const user = userEvent.setup();
        render(wrapped(<DigestPreferences />));
        await waitFor(() => expect(screen.getByTestId('digest-pref')).toHaveAttribute('data-state', 'ready'));

        await user.click(screen.getByTestId('digest-pref-frequency-monthly'));
        await user.click(screen.getByTestId('digest-pref-save'));

        await waitFor(() => expect(api.put).toHaveBeenCalledOnce());
        expect(await screen.findByTestId('digest-pref-save-success')).toBeVisible();
    });

    it('surfaces a save error when PUT fails (R14)', async () => {
        const user = userEvent.setup();
        vi.spyOn(api, 'put').mockRejectedValueOnce(new Error('500'));
        render(wrapped(<DigestPreferences />));
        await waitFor(() => expect(screen.getByTestId('digest-pref')).toHaveAttribute('data-state', 'ready'));

        await user.click(screen.getByTestId('digest-pref-frequency-monthly'));
        await user.click(screen.getByTestId('digest-pref-save'));

        expect(await screen.findByTestId('digest-pref-save-error')).toBeVisible();
    });
});
