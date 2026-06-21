import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { ProjectsList } from './ProjectsList';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockPatch = vi.fn();
const mockDelete = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockPatch.mockReset();
    mockDelete.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
    vi.spyOn(api, 'patch').mockImplementation(mockPatch);
    vi.spyOn(api, 'delete').mockImplementation(mockDelete);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const PROJECTS_FIXTURE = [
    {
        id: 1,
        project_key: 'surface-kb',
        name: 'Surface KB',
        description: 'Surface docs',
        document_count: 4,
        member_count: 2,
    },
    {
        id: 2,
        project_key: 'ffw-kb',
        name: 'FFW',
        description: null,
        document_count: 0,
        member_count: 1,
    },
];

describe('ProjectsList', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<ProjectsList />));
        expect(screen.getByTestId('admin-projects-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('renders the empty state when there are no projects', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<ProjectsList />));
        const empty = await screen.findByTestId('admin-projects-empty');
        expect(empty).toBeVisible();
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('renders one row per project with name, key and counts', async () => {
        mockGet.mockResolvedValue({ data: { data: PROJECTS_FIXTURE } });
        render(withQueryClient(<ProjectsList />));
        await waitFor(() => expect(screen.getByTestId('admin-project-row-1')).toBeVisible());

        expect(screen.getByTestId('admin-project-row-1')).toHaveAttribute('data-project-key', 'surface-kb');
        expect(screen.getByTestId('admin-project-row-1-docs')).toHaveTextContent('4');
        expect(screen.getByTestId('admin-project-row-1-members')).toHaveTextContent('2');
        expect(screen.getByTestId('admin-projects-count')).toHaveTextContent('2 total');
    });

    it('filters rows by free-text across name/key/description', async () => {
        mockGet.mockResolvedValue({ data: { data: PROJECTS_FIXTURE } });
        render(withQueryClient(<ProjectsList />));
        await waitFor(() => expect(screen.getByTestId('admin-project-row-1')).toBeVisible());

        await userEvent.type(screen.getByTestId('admin-projects-filter'), 'ffw');
        expect(screen.queryByTestId('admin-project-row-1')).not.toBeInTheDocument();
        expect(screen.getByTestId('admin-project-row-2')).toBeVisible();
    });

    it('auto-slugs the key from the name in the create dialog', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<ProjectsList />));
        await screen.findByTestId('admin-projects-empty');

        await userEvent.click(screen.getByTestId('admin-projects-create'));
        const dialog = await screen.findByTestId('admin-project-form');
        expect(dialog).toHaveAttribute('data-mode', 'create');

        await userEvent.type(screen.getByTestId('admin-project-form-name'), 'Surface KB');
        // The key mirrors the slugified name while untouched.
        expect(screen.getByTestId('admin-project-form-key')).toHaveValue('surface-kb');
    });

    it('submits a create with the slugged key', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        mockPost.mockResolvedValue({
            data: { data: { id: 9, project_key: 'surface-kb', name: 'Surface KB', description: null } },
        });
        render(withQueryClient(<ProjectsList />));
        await screen.findByTestId('admin-projects-empty');

        await userEvent.click(screen.getByTestId('admin-projects-create'));
        await screen.findByTestId('admin-project-form');
        await userEvent.type(screen.getByTestId('admin-project-form-name'), 'Surface KB');
        await userEvent.click(screen.getByTestId('admin-project-form-submit'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                '/api/admin/projects',
                expect.objectContaining({ name: 'Surface KB', project_key: 'surface-kb' }),
            );
        });
    });

    it('opens edit mode with a read-only key', async () => {
        mockGet.mockResolvedValue({ data: { data: PROJECTS_FIXTURE } });
        render(withQueryClient(<ProjectsList />));
        await waitFor(() => expect(screen.getByTestId('admin-project-row-1')).toBeVisible());

        await userEvent.click(screen.getByTestId('admin-project-row-1-edit'));
        const dialog = await screen.findByTestId('admin-project-form');
        expect(dialog).toHaveAttribute('data-mode', 'edit');
        expect(screen.getByTestId('admin-project-form-key')).toHaveAttribute('readonly');
        expect(screen.getByTestId('admin-project-form-name')).toHaveValue('Surface KB');
    });

    it('deletes only after the inline confirm step', async () => {
        mockGet.mockResolvedValue({ data: { data: PROJECTS_FIXTURE } });
        mockDelete.mockResolvedValue({ data: null });
        render(withQueryClient(<ProjectsList />));
        await waitFor(() => expect(screen.getByTestId('admin-project-row-2')).toBeVisible());

        await userEvent.click(screen.getByTestId('admin-project-row-2-delete'));
        // Not deleted yet — confirm not pressed.
        expect(mockDelete).not.toHaveBeenCalled();

        await userEvent.click(screen.getByTestId('admin-project-row-2-delete-confirm'));
        await waitFor(() => expect(mockDelete).toHaveBeenCalledWith('/api/admin/projects/2'));
    });

    it('surfaces a delete-in-use 422 in the row error slot', async () => {
        mockGet.mockResolvedValue({ data: { data: PROJECTS_FIXTURE } });
        // Laravel ValidationException::withMessages returns BOTH a top-level
        // `message` (first error) AND the per-field `errors` map.
        mockDelete.mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    message: 'Cannot delete: 4 document(s) and 0 membership(s) still use this project.',
                    errors: { project_key: ['Cannot delete: 4 document(s) and 0 membership(s) still use this project.'] },
                },
            },
        });
        render(withQueryClient(<ProjectsList />));
        await waitFor(() => expect(screen.getByTestId('admin-project-row-1')).toBeVisible());

        await userEvent.click(screen.getByTestId('admin-project-row-1-delete'));
        await userEvent.click(screen.getByTestId('admin-project-row-1-delete-confirm'));

        const err = await screen.findByTestId('admin-project-row-1-error');
        expect(err).toHaveTextContent(/Cannot delete/i);
    });
});
