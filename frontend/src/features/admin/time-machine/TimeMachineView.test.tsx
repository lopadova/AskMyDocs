import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { TimeMachineView } from './TimeMachineView';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPost = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
});
afterEach(() => vi.restoreAllMocks());

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const TIMELINE = {
    data: {
        data: [
            { id: 22, title: 'Decision v2', version_hash: 'bbbbbbbb11', status: 'active', is_canonical: true, canonical_type: 'decision', is_live: true, indexed_at: '2026-06-02T00:00:00Z', created_at: null },
            { id: 11, title: 'Decision v1', version_hash: 'aaaaaaaa22', status: 'archived', is_canonical: false, canonical_type: null, is_live: false, indexed_at: '2026-06-01T00:00:00Z', created_at: null },
        ],
        meta: { project_key: 'eng', source_path: 'docs/dec.md', total: 2 },
    },
};

describe('TimeMachineView', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<TimeMachineView docId={22} />));
        expect(screen.getByTestId('kb-time-machine-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('renders the version timeline with a live + archived row', async () => {
        mockGet.mockResolvedValue(TIMELINE);
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-22')).toBeVisible());
        expect(screen.getByTestId('kb-time-machine-version-22')).toHaveAttribute('data-is-live', 'true');
        expect(screen.getByTestId('kb-time-machine-version-11')).toHaveAttribute('data-version-status', 'archived');
        // Live version has no Restore button; archived one does.
        expect(screen.queryByTestId('kb-time-machine-version-22-restore')).not.toBeInTheDocument();
        expect(screen.getByTestId('kb-time-machine-version-11-restore')).toBeVisible();
    });

    it('renders the empty state when there are no versions', async () => {
        mockGet.mockResolvedValue({ data: { data: [], meta: { project_key: 'eng', source_path: 'x', total: 0 } } });
        render(withQueryClient(<TimeMachineView docId={22} />));
        const empty = await screen.findByTestId('kb-time-machine-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('picking From + To fetches and shows the diff', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.resolve({ data: { data: { from: 11, to: 22, added: 1, removed: 1, rows: [{ type: 'remove', text: 'old' }, { type: 'add', text: 'new' }] } } });
            }
            return Promise.resolve(TIMELINE);
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-from'));
        await userEvent.click(screen.getByTestId('kb-time-machine-version-22-to'));

        await waitFor(() => expect(screen.getByTestId('kb-time-machine-diff-summary')).toBeVisible());
        expect(screen.getByTestId('kb-time-machine-diff-summary')).toHaveTextContent('+1 / −1');
        expect(screen.getByTestId('kb-time-machine-diff-body')).toHaveTextContent('new');
    });

    it('restoring an archived version POSTs to restore-version', async () => {
        mockGet.mockResolvedValue(TIMELINE);
        mockPost.mockResolvedValue({ data: { data: { id: 11, status: 'active' } } });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-restore'));
        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith('/api/admin/kb/documents/11/restore-version');
        });
    });

    it('surfaces a restore failure instead of swallowing it', async () => {
        mockGet.mockResolvedValue(TIMELINE);
        mockPost.mockRejectedValue(new Error('already live'));
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-restore'));
        const err = await screen.findByTestId('kb-time-machine-restore-error');
        expect(err).toHaveTextContent('already live');
    });

    it('renders an error state (not empty) when the timeline query fails', async () => {
        mockGet.mockRejectedValue(new Error('boom 500'));
        render(withQueryClient(<TimeMachineView docId={22} />));
        const err = await screen.findByTestId('kb-time-machine-error');
        expect(err).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('kb-time-machine-empty')).not.toBeInTheDocument();
    });
});
