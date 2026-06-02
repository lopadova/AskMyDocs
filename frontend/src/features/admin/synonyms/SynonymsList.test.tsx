import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { SynonymsList } from './SynonymsList';
import { normalizeToken, parseSynonyms } from './synonyms.api';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockPut = vi.fn();
const mockDelete = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockPut.mockReset();
    mockDelete.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
    vi.spyOn(api, 'put').mockImplementation(mockPut);
    vi.spyOn(api, 'delete').mockImplementation(mockDelete);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const FIXTURE = [
    { id: 1, project_key: 'engineering', term: 'k8s', synonyms: ['kubernetes'], enabled: true },
    { id: 2, project_key: 'hr', term: 'pto', synonyms: ['paid time off', 'leave'], enabled: false },
];

describe('parseSynonyms', () => {
    it('splits on newline or comma, lowercases, trims and dedupes', () => {
        expect(parseSynonyms('Kubernetes\nK8S Cluster, kubernetes')).toEqual([
            'kubernetes',
            'k8s cluster',
        ]);
    });

    it('returns an empty list for blank input', () => {
        expect(parseSynonyms('   \n , ')).toEqual([]);
    });
});

describe('normalizeToken', () => {
    it('lowercases, trims, and collapses internal whitespace (matches the backend)', () => {
        expect(normalizeToken('  Continuous   Integration ')).toBe('continuous integration');
        expect(normalizeToken('K8S')).toBe('k8s');
    });
});

describe('SynonymsList', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<SynonymsList />));
        expect(screen.getByTestId('admin-synonyms-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('renders empty state when there are no groups', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<SynonymsList />));
        const empty = await screen.findByTestId('admin-synonyms-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('renders one row per group with term + synonyms + enabled state', async () => {
        mockGet.mockResolvedValue({ data: { data: FIXTURE } });
        render(withQueryClient(<SynonymsList />));
        await waitFor(() => expect(screen.getByTestId('admin-synonym-row-1')).toBeVisible());
        expect(screen.getByTestId('admin-synonym-row-1')).toHaveAttribute('data-synonym-term', 'k8s');
        expect(screen.getByTestId('admin-synonym-row-1-enabled')).toHaveAttribute('data-enabled', 'true');
        expect(screen.getByTestId('admin-synonym-row-2-enabled')).toHaveAttribute('data-enabled', 'false');
        expect(screen.getByTestId('admin-synonyms-count')).toHaveTextContent('2 total');
    });

    it('filters rows across project / term / synonym', async () => {
        mockGet.mockResolvedValue({ data: { data: FIXTURE } });
        render(withQueryClient(<SynonymsList />));
        await waitFor(() => expect(screen.getByTestId('admin-synonym-row-1')).toBeVisible());
        // 'leave' is a synonym of row 2 only.
        await userEvent.type(screen.getByTestId('admin-synonyms-filter'), 'leave');
        expect(screen.queryByTestId('admin-synonym-row-1')).not.toBeInTheDocument();
        expect(screen.getByTestId('admin-synonym-row-2')).toBeVisible();
    });

    it('opens the create dialog with role=dialog + aria-modal', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<SynonymsList />));
        await screen.findByTestId('admin-synonyms-empty');
        await userEvent.click(screen.getByTestId('admin-synonyms-create'));
        const dialog = screen.getByTestId('admin-synonym-form');
        expect(dialog).toHaveAttribute('data-mode', 'create');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAttribute('role', 'dialog');
    });

    it('create submit posts the parsed, deduped, lowercased payload', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        mockPost.mockResolvedValue({
            data: { data: { id: 9, project_key: 'eng', term: 'k8s', synonyms: ['kubernetes'], enabled: true } },
        });
        render(withQueryClient(<SynonymsList />));
        await screen.findByTestId('admin-synonyms-empty');
        await userEvent.click(screen.getByTestId('admin-synonyms-create'));
        await userEvent.type(screen.getByTestId('admin-synonym-form-project'), 'eng');
        await userEvent.type(screen.getByTestId('admin-synonym-form-term'), 'K8S');
        await userEvent.type(screen.getByTestId('admin-synonym-form-synonyms'), 'Kubernetes, kubernetes');
        await userEvent.click(screen.getByTestId('admin-synonym-form-submit'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith('/api/admin/kb/synonyms', {
                project_key: 'eng',
                term: 'k8s',
                synonyms: ['kubernetes'],
                enabled: true,
            });
        });
    });

    it('submit stays disabled until a distinct synonym is provided', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<SynonymsList />));
        await screen.findByTestId('admin-synonyms-empty');
        await userEvent.click(screen.getByTestId('admin-synonyms-create'));
        await userEvent.type(screen.getByTestId('admin-synonym-form-project'), 'eng');
        await userEvent.type(screen.getByTestId('admin-synonym-form-term'), 'k8s');
        // Only synonym equals the term → collapses to nothing distinct.
        await userEvent.type(screen.getByTestId('admin-synonym-form-synonyms'), 'k8s');
        expect(screen.getByTestId('admin-synonym-form-submit')).toBeDisabled();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('edit dialog prefills + read-only project; save sends the full synonym list', async () => {
        mockGet.mockResolvedValue({ data: { data: FIXTURE } });
        mockPut.mockResolvedValue({ data: { data: { ...FIXTURE[1], synonyms: ['paid time off', 'leave', 'holiday'] } } });
        render(withQueryClient(<SynonymsList />));
        await waitFor(() => expect(screen.getByTestId('admin-synonym-row-2')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-synonym-row-2-edit'));
        const dialog = screen.getByTestId('admin-synonym-form');
        expect(dialog).toHaveAttribute('data-mode', 'edit');
        expect(screen.getByTestId('admin-synonym-form-project')).toHaveAttribute('readonly');

        await userEvent.type(screen.getByTestId('admin-synonym-form-synonyms'), '\nholiday');
        await userEvent.click(screen.getByTestId('admin-synonym-form-submit'));
        await waitFor(() => {
            expect(mockPut).toHaveBeenCalledWith('/api/admin/kb/synonyms/2', {
                synonyms: ['paid time off', 'leave', 'holiday'],
            });
        });
    });

    it('renders an error state (NOT the empty state) when the list query fails', async () => {
        mockGet.mockRejectedValue(new Error('boom 500'));
        render(withQueryClient(<SynonymsList />));
        const err = await screen.findByTestId('admin-synonyms-error');
        expect(err).toHaveAttribute('data-state', 'error');
        // The empty state must NOT render on a failed query — they are distinct.
        expect(screen.queryByTestId('admin-synonyms-empty')).not.toBeInTheDocument();
    });

    it('surfaces a delete failure instead of swallowing it', async () => {
        mockGet.mockResolvedValue({ data: { data: FIXTURE } });
        mockDelete.mockRejectedValue(new Error('delete failed'));
        render(withQueryClient(<SynonymsList />));
        await waitFor(() => expect(screen.getByTestId('admin-synonym-row-1')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-synonym-row-1-delete'));
        await userEvent.click(screen.getByTestId('admin-synonym-row-1-delete-confirm'));
        const err = await screen.findByTestId('admin-synonyms-delete-error');
        expect(err).toHaveTextContent('delete failed');
    });

    it('delete requires a confirm step before firing the API', async () => {
        mockGet.mockResolvedValue({ data: { data: FIXTURE } });
        mockDelete.mockResolvedValue({ status: 204 });
        render(withQueryClient(<SynonymsList />));
        await waitFor(() => expect(screen.getByTestId('admin-synonym-row-1')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-synonym-row-1-delete'));
        expect(screen.getByTestId('admin-synonym-row-1-delete-confirm')).toBeVisible();
        expect(mockDelete).not.toHaveBeenCalled();
        await userEvent.click(screen.getByTestId('admin-synonym-row-1-delete-confirm'));
        await waitFor(() => expect(mockDelete).toHaveBeenCalledWith('/api/admin/kb/synonyms/1'));
    });
});
