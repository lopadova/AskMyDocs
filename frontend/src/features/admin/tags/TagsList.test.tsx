import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { TagsList } from './TagsList';
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

const TAGS_FIXTURE = [
    { id: 1, project_key: 'engineering', slug: 'release', label: 'Release', color: '#1abc9c' },
    { id: 2, project_key: 'hr', slug: 'policy', label: 'Policy', color: '#e74c3c' },
    { id: 3, project_key: 'hr', slug: 'safety', label: 'Safety', color: null },
];

describe('TagsList', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<TagsList />));
        expect(screen.getByTestId('admin-tags-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('renders empty state when there are no tags', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<TagsList />));
        const empty = await screen.findByTestId('admin-tags-empty');
        expect(empty).toBeVisible();
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('renders one row per tag with project + slug + label visible', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        render(withQueryClient(<TagsList />));
        await waitFor(() => {
            expect(screen.getByTestId('admin-tag-row-1')).toBeVisible();
        });
        expect(screen.getByTestId('admin-tag-row-1')).toHaveAttribute('data-tag-slug', 'release');
        expect(screen.getByTestId('admin-tag-row-2')).toHaveAttribute('data-tag-project', 'hr');
        expect(screen.getByTestId('admin-tags-count')).toHaveTextContent('3 total');
    });

    it('filters rows by free-text input across project/slug/label', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        render(withQueryClient(<TagsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tag-row-1')).toBeVisible());
        await userEvent.type(screen.getByTestId('admin-tags-filter'), 'hr');
        // Engineering row drops; both hr rows stay.
        expect(screen.queryByTestId('admin-tag-row-1')).not.toBeInTheDocument();
        expect(screen.getByTestId('admin-tag-row-2')).toBeVisible();
        expect(screen.getByTestId('admin-tag-row-3')).toBeVisible();
    });

    it('shows no-match state when filter excludes everything', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        render(withQueryClient(<TagsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tag-row-1')).toBeVisible());
        await userEvent.type(screen.getByTestId('admin-tags-filter'), 'absolutely-nothing-matches-this');
        expect(screen.getByTestId('admin-tags-no-match')).toBeVisible();
    });

    it('clicking + New tag opens the create dialog', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<TagsList />));
        await screen.findByTestId('admin-tags-empty');
        await userEvent.click(screen.getByTestId('admin-tags-create'));
        const dialog = screen.getByTestId('admin-tag-form');
        expect(dialog).toHaveAttribute('data-mode', 'create');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAttribute('role', 'dialog');
    });

    it('clicking Edit on a row opens the dialog in edit mode prefilled', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        render(withQueryClient(<TagsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tag-row-2')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-tag-row-2-edit'));
        const dialog = screen.getByTestId('admin-tag-form');
        expect(dialog).toHaveAttribute('data-mode', 'edit');
        // Project + slug + label fields prefilled from the tag.
        expect(screen.getByTestId('admin-tag-form-project')).toHaveValue('hr');
        expect(screen.getByTestId('admin-tag-form-slug')).toHaveValue('policy');
        expect(screen.getByTestId('admin-tag-form-label')).toHaveValue('Policy');
        // project_key is read-only in edit mode (orphan-pivot guard).
        expect(screen.getByTestId('admin-tag-form-project')).toHaveAttribute('readonly');
    });

    it('Delete on a row requires a confirm step (no accidental delete)', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        render(withQueryClient(<TagsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tag-row-2')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-tag-row-2-delete'));
        // First click reveals Confirm + Cancel; no DELETE issued yet.
        expect(screen.getByTestId('admin-tag-row-2-delete-confirm')).toBeVisible();
        expect(screen.getByTestId('admin-tag-row-2-delete-cancel')).toBeVisible();
        expect(mockDelete).not.toHaveBeenCalled();
    });

    it('Delete confirm fires the DELETE API call', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        mockDelete.mockResolvedValue({ status: 204 });
        render(withQueryClient(<TagsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tag-row-2')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-tag-row-2-delete'));
        await userEvent.click(screen.getByTestId('admin-tag-row-2-delete-confirm'));
        await waitFor(() => {
            expect(mockDelete).toHaveBeenCalledWith('/api/admin/kb/tags/2');
        });
    });

    it('Delete cancel reverts the row to its non-confirming state', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        render(withQueryClient(<TagsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tag-row-2')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-tag-row-2-delete'));
        await userEvent.click(screen.getByTestId('admin-tag-row-2-delete-cancel'));
        // Edit + Delete buttons are back; Confirm gone.
        expect(screen.getByTestId('admin-tag-row-2-delete')).toBeVisible();
        expect(screen.queryByTestId('admin-tag-row-2-delete-confirm')).not.toBeInTheDocument();
        expect(mockDelete).not.toHaveBeenCalled();
    });

    it('Create dialog: filling all fields + submit calls POST with the right payload', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        mockPost.mockResolvedValue({
            data: { data: { id: 999, project_key: 'finance', slug: 'compliance', label: 'Compliance', color: '#abcdef' } },
        });
        render(withQueryClient(<TagsList />));
        await screen.findByTestId('admin-tags-empty');
        await userEvent.click(screen.getByTestId('admin-tags-create'));
        await userEvent.type(screen.getByTestId('admin-tag-form-project'), 'finance');
        await userEvent.type(screen.getByTestId('admin-tag-form-slug'), 'compliance');
        await userEvent.type(screen.getByTestId('admin-tag-form-label'), 'Compliance');
        await userEvent.clear(screen.getByTestId('admin-tag-form-color-text'));
        await userEvent.type(screen.getByTestId('admin-tag-form-color-text'), '#abcdef');
        await userEvent.click(screen.getByTestId('admin-tag-form-submit'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                '/api/admin/kb/tags',
                {
                    project_key: 'finance',
                    slug: 'compliance',
                    label: 'Compliance',
                    color: '#abcdef',
                },
            );
        });
    });

    it('Edit dialog: changing label only sends just label in PUT', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        mockPut.mockResolvedValue({
            data: { data: { ...TAGS_FIXTURE[1], label: 'New Label' } },
        });
        render(withQueryClient(<TagsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tag-row-2')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-tag-row-2-edit'));

        const labelInput = screen.getByTestId('admin-tag-form-label');
        await userEvent.clear(labelInput);
        await userEvent.type(labelInput, 'New Label');
        await userEvent.click(screen.getByTestId('admin-tag-form-submit'));

        await waitFor(() => {
            expect(mockPut).toHaveBeenCalledWith(
                '/api/admin/kb/tags/2',
                { label: 'New Label' },
            );
        });
    });

    it('Edit dialog: clearing color sends color: null', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        mockPut.mockResolvedValue({ data: { data: { ...TAGS_FIXTURE[1], color: null } } });
        render(withQueryClient(<TagsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tag-row-2')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-tag-row-2-edit'));
        await userEvent.click(screen.getByTestId('admin-tag-form-color-clear'));
        await userEvent.click(screen.getByTestId('admin-tag-form-submit'));
        await waitFor(() => {
            expect(mockPut).toHaveBeenCalledWith(
                '/api/admin/kb/tags/2',
                { color: null },
            );
        });
    });

    it('Cancel button on the dialog closes without calling the API', async () => {
        mockGet.mockResolvedValue({ data: { data: TAGS_FIXTURE } });
        render(withQueryClient(<TagsList />));
        await waitFor(() => expect(screen.getByTestId('admin-tag-row-2')).toBeVisible());
        await userEvent.click(screen.getByTestId('admin-tag-row-2-edit'));
        await userEvent.click(screen.getByTestId('admin-tag-form-cancel'));
        expect(screen.queryByTestId('admin-tag-form')).not.toBeInTheDocument();
        expect(mockPut).not.toHaveBeenCalled();
        expect(mockPost).not.toHaveBeenCalled();
    });
});
