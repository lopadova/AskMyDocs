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
            { id: 22, title: 'Decision v2', version_hash: 'bbbbbbbb11', status: 'active', is_canonical: true, canonical_type: 'decision', is_live: true, indexed_at: '2026-06-02T00:00:00Z', created_at: null, version_actor: 'system:ocr', version_reason: 'ocr re-run (fake)', content_hash: 'cafe', has_artifact: true, restored_by: 'user:7', restored_at: '2026-06-03T00:00:00Z' },
            { id: 11, title: 'Decision v1', version_hash: 'aaaaaaaa22', status: 'archived', is_canonical: false, canonical_type: null, is_live: false, indexed_at: '2026-06-01T00:00:00Z', created_at: null, version_actor: null, version_reason: null, content_hash: null, has_artifact: false },
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
        // R11 — the diff region carries the canonical async state itself.
        expect(screen.getByTestId('kb-time-machine-diff')).toHaveAttribute('data-state', 'ready');
        expect(screen.getByTestId('kb-time-machine-diff')).toHaveAttribute('aria-busy', 'false');
        expect(screen.getByTestId('kb-time-machine-diff-body')).toHaveTextContent('new');
    });

    it('shows who created each version and marks the ones with a stored document (v8.36)', async () => {
        mockGet.mockResolvedValue(TIMELINE);
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-22')).toBeVisible());

        expect(screen.getByTestId('kb-time-machine-version-22')).toHaveAttribute('data-has-artifact', 'true');
        // creation provenance first, the restore apart from it (ADR 0030 §6)
        expect(screen.getByTestId('kb-time-machine-version-22-actor')).toHaveTextContent('system:ocr · ocr re-run (fake) · restored by user:7');
        expect(screen.getByTestId('kb-time-machine-version-22-artifact')).toBeVisible();
        // a row that predates the artifacts says so instead of inventing an actor
        expect(screen.getByTestId('kb-time-machine-version-11')).toHaveAttribute('data-has-artifact', 'false');
        expect(screen.getByTestId('kb-time-machine-version-11-actor')).toHaveTextContent('unknown actor');
        expect(screen.queryByTestId('kb-time-machine-version-11-artifact')).toBeNull();
    });

    it('warns when a version\'s stored document is missing or does not match its hash (v8.36)', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.resolve({ data: { data: { from: 11, to: 22, added: 0, removed: 0, rows: [] } } });
            }
            return Promise.resolve({ data: { data: [
                { id: 33, title: 'Decision v3', version_hash: 'cccccccc33', status: 'active', is_canonical: false, canonical_type: null, is_live: true, indexed_at: '2026-06-03T00:00:00Z', created_at: null, version_actor: 'user:7', version_reason: null, content_hash: 'c0ffee', has_artifact: false, artifact_state: 'mismatch' },
                { id: 22, title: 'Decision v2', version_hash: 'bbbbbbbb11', status: 'archived', is_canonical: false, canonical_type: null, is_live: false, indexed_at: '2026-06-02T00:00:00Z', created_at: null, version_actor: 'user:7', version_reason: null, content_hash: 'cafe', has_artifact: false, artifact_state: 'missing' },
                { id: 11, title: 'Decision v1', version_hash: 'aaaaaaaa22', status: 'archived', is_canonical: false, canonical_type: null, is_live: false, indexed_at: '2026-06-01T00:00:00Z', created_at: null, version_actor: null, version_reason: null, content_hash: null, has_artifact: false, artifact_state: 'none' },
            ], meta: { project_key: 'eng', source_path: 'docs/dec.md', total: 3 } } });
        });
        render(withQueryClient(<TimeMachineView docId={33} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-33')).toBeVisible());

        expect(screen.getByTestId('kb-time-machine-version-33-artifact-warning')).toHaveAttribute('data-artifact-state', 'mismatch');
        expect(screen.getByTestId('kb-time-machine-version-33-artifact-warning')).toHaveTextContent('artifact mismatch');
        expect(screen.getByTestId('kb-time-machine-version-22-artifact-warning')).toHaveAttribute('data-artifact-state', 'missing');
        expect(screen.queryByTestId('kb-time-machine-version-33-artifact')).toBeNull(); // never "stored" over a bad file
        expect(screen.queryByTestId('kb-time-machine-version-11-artifact-warning')).toBeNull(); // a row that never had one is not a warning
    });

    it('labels a diff as faithful only when both sides are stored documents (v8.36)', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.resolve({ data: { data: { from: 11, to: 22, added: 1, removed: 0, rows: [{ type: 'add', text: 'new' }], from_source: 'reconstruction', to_source: 'artifact' } } });
            }
            return Promise.resolve(TIMELINE);
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-from'));
        await userEvent.click(screen.getByTestId('kb-time-machine-version-22-to'));

        await waitFor(() => expect(screen.getByTestId('kb-time-machine-diff-source')).toBeVisible());
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveAttribute('data-diff-faithful', 'false');
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveTextContent('Index diff — the From side is reconstructed');
    });

    it('names the reconstructed side by the pick that selected it, never as newer or older (v8.36)', async () => {
        // From and To are independent picks: here the ARCHIVED row 11 is the To side.
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.resolve({ data: { data: { from: 22, to: 11, added: 0, removed: 1, rows: [{ type: 'remove', text: 'old' }], from_source: 'artifact', to_source: 'reconstruction' } } });
            }
            return Promise.resolve(TIMELINE);
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-22-from'));
        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-to'));

        await waitFor(() => expect(screen.getByTestId('kb-time-machine-diff-source')).toBeVisible());
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveTextContent('Index diff — the To side is reconstructed');
    });

    it('says both sides are reconstructed when neither is a stored document (v8.36)', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.resolve({ data: { data: { from: 11, to: 22, added: 0, removed: 0, rows: [], from_source: 'reconstruction', to_source: 'reconstruction' } } });
            }
            return Promise.resolve(TIMELINE);
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-from'));
        await userEvent.click(screen.getByTestId('kb-time-machine-version-22-to'));

        await waitFor(() => expect(screen.getByTestId('kb-time-machine-diff-source')).toBeVisible());
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveTextContent('Index diff — both the From and the To side are reconstructed');
    });

    it('labels a diff as faithful only when both sides are stored documents VERIFIED against their hashes (v8.36)', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.resolve({ data: { data: { from: 11, to: 22, added: 0, removed: 0, rows: [], from_source: 'artifact', to_source: 'artifact', from_integrity: 'verified', to_integrity: 'verified' } } });
            }
            return Promise.resolve(TIMELINE);
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-from'));
        await userEvent.click(screen.getByTestId('kb-time-machine-version-22-to'));

        await waitFor(() => expect(screen.getByTestId('kb-time-machine-diff-source')).toBeVisible());
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveAttribute('data-diff-faithful', 'true');
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveAttribute('data-diff-state', 'faithful');
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveTextContent('Faithful diff');
    });

    it('never calls a diff faithful when a stored side has no hash to verify against (legacy pointer)', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.resolve({ data: { data: { from: 11, to: 22, added: 0, removed: 0, rows: [], from_source: 'artifact', to_source: 'artifact', from_integrity: null, to_integrity: 'verified' } } });
            }
            return Promise.resolve(TIMELINE);
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-from'));
        await userEvent.click(screen.getByTestId('kb-time-machine-version-22-to'));

        await waitFor(() => expect(screen.getByTestId('kb-time-machine-diff-source')).toBeVisible());
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveAttribute('data-diff-faithful', 'false');
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveAttribute('data-diff-state', 'unverified');
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveTextContent('Stored documents compared, but not verified — the From side has no recorded hash');
    });

    it('names both sides when neither stored document has a hash to verify against', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.resolve({ data: { data: { from: 11, to: 22, added: 0, removed: 0, rows: [], from_source: 'artifact', to_source: 'artifact', from_integrity: null, to_integrity: null } } });
            }
            return Promise.resolve(TIMELINE);
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-from'));
        await userEvent.click(screen.getByTestId('kb-time-machine-version-22-to'));

        await waitFor(() => expect(screen.getByTestId('kb-time-machine-diff-source')).toBeVisible());
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveAttribute('data-diff-state', 'unverified');
        expect(screen.getByTestId('kb-time-machine-diff-source')).toHaveTextContent('both the From and the To side have no recorded hash');
    });

    it('says nothing about the diff source when an older server omits it', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.resolve({ data: { data: { from: 11, to: 22, added: 0, removed: 0, rows: [] } } });
            }
            return Promise.resolve(TIMELINE);
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-from'));
        await userEvent.click(screen.getByTestId('kb-time-machine-version-22-to'));

        await waitFor(() => expect(screen.getByTestId('kb-time-machine-diff-summary')).toBeVisible());
        expect(screen.queryByTestId('kb-time-machine-diff-source')).toBeNull();
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
        // R11 / R15 — the mutation is announced and exposed on the container, not only as disabled buttons.
        const status = screen.getByTestId('kb-time-machine-restore-status');
        expect(status).toHaveAttribute('role', 'status');
        expect(status).toHaveAttribute('aria-live', 'polite');
        await waitFor(() => expect(status).toHaveAttribute('data-restore-state', 'restored'));
        expect(status).toHaveAttribute('data-state', 'ready'); // R11 vocabulary; the domain nuance is data-restore-state
        expect(status).toHaveTextContent('Version 11 restored and live.');
        expect(screen.getByTestId('kb-time-machine-timeline')).toHaveAttribute('data-restore-state', 'restored');
        expect(screen.getByTestId('kb-time-machine-timeline')).toHaveAttribute('aria-busy', 'false');
    });

    it('announces the restore in progress and marks the timeline busy', async () => {
        mockGet.mockResolvedValue(TIMELINE);
        let resolvePost: (value: unknown) => void = () => undefined;
        mockPost.mockReturnValue(new Promise((resolve) => { resolvePost = resolve; }));
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-restore'));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-restore-status')).toHaveAttribute('data-restore-state', 'restoring'));
        expect(screen.getByTestId('kb-time-machine-restore-status')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('kb-time-machine-restore-status')).toHaveTextContent('Restoring version…');
        expect(screen.getByTestId('kb-time-machine-timeline')).toHaveAttribute('aria-busy', 'true');
        expect(screen.getByTestId('kb-time-machine-version-11-restore')).toBeDisabled();

        resolvePost({ data: { data: { id: 11, status: 'active' } } });
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-restore-status')).toHaveAttribute('data-restore-state', 'restored'));
    });

    it('clears the previous restore failure when a retry starts', async () => {
        mockGet.mockResolvedValue(TIMELINE);
        mockPost.mockRejectedValueOnce(new Error('already live')).mockResolvedValueOnce({ data: { data: { id: 11, status: 'active' } } });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-restore'));
        await screen.findByTestId('kb-time-machine-restore-error');
        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-restore'));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-restore-status')).toHaveAttribute('data-restore-state', 'restored'));
        expect(screen.queryByTestId('kb-time-machine-restore-error')).not.toBeInTheDocument();
    });

    it('says when the family holds more versions than the bounded page and loads the older ones on demand', async () => {
        const olderRow = { id: 5, title: 'Decision v0', version_hash: 'dddddddd00', status: 'archived', is_canonical: false, canonical_type: null, is_live: false, indexed_at: '2026-05-01T00:00:00Z', created_at: null, version_actor: null, version_reason: null, content_hash: null, has_artifact: false };
        mockGet.mockImplementation((url: string) => {
            if (url.includes('offset=2')) {
                return Promise.resolve({ data: { data: [olderRow], meta: { ...TIMELINE.data.meta, total: 3, limit: 2, offset: 2, truncated: false } } });
            }
            return Promise.resolve({ data: { data: TIMELINE.data.data, meta: { ...TIMELINE.data.meta, total: 3, limit: 2, offset: 0, truncated: true } } });
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        const note = await screen.findByTestId('kb-time-machine-truncated');
        expect(note).toHaveTextContent('Showing the newest 2 of 3 versions.');
        expect(screen.getByTestId('kb-time-machine-source')).toHaveTextContent('3 versions');
        const older = screen.getByTestId('kb-time-machine-load-older');
        expect(older).toHaveTextContent('Load older versions (1 more)');

        await userEvent.click(older);
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-5')).toBeVisible());
        expect(mockGet).toHaveBeenCalledWith('/api/admin/kb/documents/22/versions?offset=2');
        // the whole family is reachable now: no note, no button
        expect(screen.queryByTestId('kb-time-machine-truncated')).not.toBeInTheDocument();
        expect(screen.queryByTestId('kb-time-machine-load-older')).not.toBeInTheDocument();
    });

    it('surfaces a failure to load older versions instead of swallowing it', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('offset=2')) {
                return Promise.reject(new Error('boom 500'));
            }
            return Promise.resolve({ data: { data: TIMELINE.data.data, meta: { ...TIMELINE.data.meta, total: 3, limit: 2, offset: 0, truncated: true } } });
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await userEvent.click(await screen.findByTestId('kb-time-machine-load-older'));
        const err = await screen.findByTestId('kb-time-machine-load-older-error');
        expect(err).toHaveTextContent('boom 500');
        expect(screen.getByTestId('kb-time-machine-load-older')).toHaveAttribute('data-state', 'error');
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

    it('renders a diff error state instead of blank when the diff query fails', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.includes('/diff')) {
                return Promise.reject(new Error('diff 500'));
            }
            return Promise.resolve(TIMELINE);
        });
        render(withQueryClient(<TimeMachineView docId={22} />));
        await waitFor(() => expect(screen.getByTestId('kb-time-machine-version-11')).toBeVisible());

        await userEvent.click(screen.getByTestId('kb-time-machine-version-11-from'));
        await userEvent.click(screen.getByTestId('kb-time-machine-version-22-to'));

        const diffErr = await screen.findByTestId('kb-time-machine-diff-error');
        expect(diffErr).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('kb-time-machine-diff')).toHaveAttribute('data-state', 'error');
        expect(diffErr).toHaveTextContent('diff 500');
        expect(screen.queryByTestId('kb-time-machine-diff-summary')).not.toBeInTheDocument();
    });
});
