import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { PathContextsView } from './PathContextsView';
import { PathContextsDisabledError, adminPathContextsApi, validatePathContext } from './path-contexts.api';
import { api } from '../../../lib/api';

const mockGet = vi.fn();
const mockPut = vi.fn();
const mockDelete = vi.fn();

const FIXTURE = [
    {
        id: 1,
        project_key: 'engineering',
        path_prefix: 'runbooks/',
        description: 'Operational runbooks.',
        updated_at: '2026-11-05T10:00:00Z',
    },
    {
        id: 2,
        project_key: 'hr',
        path_prefix: 'policies/',
        description: 'Current HR policies approved by the DPO.',
        updated_at: '2026-11-04T08:30:00Z',
    },
];

function respondWith(contexts: unknown[] | Error) {
    mockGet.mockImplementation((url: string) => {
        if (url === '/api/admin/kb/projects') {
            return Promise.resolve({ data: { projects: ['engineering', 'hr'] } });
        }
        if (contexts instanceof Error) return Promise.reject(contexts);
        return Promise.resolve({ data: { data: contexts } });
    });
}

function disabledError() {
    return Object.assign(new Error('Request failed with status code 404'), {
        response: { status: 404, data: { code: 'path_contexts_disabled', message: 'Path contexts are disabled.' } },
    });
}

function validationError(errors: Record<string, string[]>) {
    return Object.assign(new Error('Request failed with status code 422'), {
        response: { status: 422, data: { message: 'The given data was invalid.', errors } },
    });
}

beforeEach(() => {
    mockGet.mockReset();
    mockPut.mockReset();
    mockDelete.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
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

describe('validatePathContext', () => {
    const ok = { project_key: 'hr', path_prefix: 'policies/', description: 'x' };

    it('accepts a valid payload', () => {
        expect(validatePathContext(ok)).toEqual({});
    });

    it('mirrors the backend rules', () => {
        expect(validatePathContext({ ...ok, project_key: ' ' }).project_key).toBeDefined();
        expect(validatePathContext({ ...ok, path_prefix: '/abs/' }).path_prefix).toMatch(/slash/);
        expect(validatePathContext({ ...ok, path_prefix: 'a/../b' }).path_prefix).toMatch(/\.\./);
        expect(validatePathContext({ ...ok, path_prefix: 'a'.repeat(301) }).path_prefix).toBeDefined();
        expect(validatePathContext({ ...ok, description: 'a'.repeat(501) }).description).toBeDefined();
        expect(validatePathContext({ ...ok, description: 'a'.repeat(500) })).toEqual({});
    });
});

describe('adminPathContextsApi', () => {
    it('sends project_key as a query param only when filtering', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        await adminPathContextsApi.list();
        expect(mockGet).toHaveBeenLastCalledWith('/api/admin/kb/path-contexts', undefined);
        await adminPathContextsApi.list('hr');
        expect(mockGet).toHaveBeenLastCalledWith('/api/admin/kb/path-contexts', { params: { project_key: 'hr' } });
    });

    it('turns the flag-OFF 404 into a typed error on all three calls and rethrows others untouched', async () => {
        mockGet.mockRejectedValue(disabledError());
        mockPut.mockRejectedValue(disabledError());
        mockDelete.mockRejectedValue(disabledError());
        await expect(adminPathContextsApi.list()).rejects.toBeInstanceOf(PathContextsDisabledError);
        await expect(
            adminPathContextsApi.upsert({ project_key: 'hr', path_prefix: 'p/', description: 'd' }),
        ).rejects.toBeInstanceOf(PathContextsDisabledError);
        await expect(adminPathContextsApi.remove(1)).rejects.toBeInstanceOf(PathContextsDisabledError);

        const other = Object.assign(new Error('nope'), { response: { status: 404, data: { message: 'other' } } });
        mockGet.mockRejectedValue(other);
        await expect(adminPathContextsApi.list()).rejects.toBe(other);
    });
});

describe('PathContextsView', () => {
    it('renders the loading state initially', () => {
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(withQueryClient(<PathContextsView />));
        const page = screen.getByTestId('admin-path-contexts-page');
        expect(page).toHaveAttribute('data-state', 'loading');
        expect(page).toHaveAttribute('aria-busy', 'true');
    });

    it('renders one row per context with project, prefix, description and updated', async () => {
        respondWith(FIXTURE);
        render(withQueryClient(<PathContextsView />));
        const row = await screen.findByTestId('admin-path-context-row-2');
        expect(screen.getByTestId('admin-path-contexts-page')).toHaveAttribute('data-state', 'ready');
        expect(screen.getByTestId('admin-path-contexts-page')).toHaveAttribute('aria-busy', 'false');
        expect(row).toHaveTextContent('hr');
        expect(row).toHaveTextContent('policies/');
        expect(row).toHaveTextContent('Current HR policies approved by the DPO.');
        expect(row).toHaveTextContent('2026-11-04 08:30 UTC');
        expect(screen.getByTestId('admin-path-context-row-1')).toBeInTheDocument();
        expect(screen.getByTestId('admin-path-contexts-count')).toHaveTextContent('2 total');
    });

    it('derives the project filter from the projects API and refetches filtered', async () => {
        respondWith(FIXTURE);
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-context-row-1');
        const filter = screen.getByTestId('admin-path-contexts-project-filter');
        const values = Array.from(filter.querySelectorAll('option')).map((o) => o.getAttribute('value'));
        expect(values).toEqual(['', 'engineering', 'hr']);

        await userEvent.selectOptions(filter, 'hr');
        await waitFor(() =>
            expect(mockGet).toHaveBeenCalledWith('/api/admin/kb/path-contexts', { params: { project_key: 'hr' } }),
        );
    });

    it('renders the empty state when there are no contexts', async () => {
        respondWith([]);
        render(withQueryClient(<PathContextsView />));
        const empty = await screen.findByTestId('admin-path-contexts-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
        expect(screen.getByTestId('admin-path-contexts-page')).toHaveAttribute('data-state', 'empty');
    });

    it('renders an error state (NOT empty) when the list fails', async () => {
        respondWith(new Error('boom 500'));
        render(withQueryClient(<PathContextsView />));
        const err = await screen.findByTestId('admin-path-contexts-error');
        expect(err).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('admin-path-contexts-page')).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('admin-path-contexts-empty')).not.toBeInTheDocument();
    });

    it('degrades to a single unavailable panel on 404 path_contexts_disabled (R43)', async () => {
        respondWith(disabledError());
        render(withQueryClient(<PathContextsView />));
        const panel = await screen.findByTestId('admin-path-contexts-unavailable');
        expect(panel).toHaveAttribute('data-state', 'unavailable');
        expect(panel).toHaveTextContent('Path contexts are disabled on this deployment');
        expect(screen.getByTestId('admin-path-contexts-page')).toHaveAttribute('data-state', 'unavailable');
        expect(screen.queryByTestId('admin-path-contexts-error')).not.toBeInTheDocument();
        expect(screen.queryByTestId('admin-path-contexts-add')).not.toBeInTheDocument();
        expect(screen.queryByTestId('admin-path-contexts-table')).not.toBeInTheDocument();
    });

    it('add form PUTs exactly {project_key, path_prefix, description}', async () => {
        respondWith([]);
        mockPut.mockResolvedValue({
            data: { data: { id: 9, project_key: 'hr', path_prefix: 'policies/', description: 'Policies.', updated_at: null } },
        });
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-contexts-empty');
        await userEvent.click(screen.getByTestId('admin-path-contexts-add'));
        expect(screen.getByTestId('path-context-form')).toHaveAttribute('data-mode', 'create');

        await userEvent.selectOptions(screen.getByTestId('path-context-project'), 'hr');
        await userEvent.type(screen.getByTestId('path-context-prefix'), ' policies/ ');
        await userEvent.type(screen.getByTestId('path-context-description'), 'Policies.');
        await userEvent.click(screen.getByTestId('path-context-save'));

        await waitFor(() =>
            expect(mockPut).toHaveBeenCalledWith('/api/admin/kb/path-contexts', {
                project_key: 'hr',
                path_prefix: 'policies/',
                description: 'Policies.',
            }),
        );
        await waitFor(() => expect(screen.queryByTestId('path-context-form')).not.toBeInTheDocument());
    });

    it('prefills the filtered project in the add form', async () => {
        respondWith(FIXTURE);
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-context-row-1');
        await userEvent.selectOptions(screen.getByTestId('admin-path-contexts-project-filter'), 'hr');
        await userEvent.click(screen.getByTestId('admin-path-contexts-add'));
        expect(screen.getByTestId('path-context-project')).toHaveValue('hr');
    });

    it('edit mode locks project + prefix and PUTs the changed description', async () => {
        respondWith(FIXTURE);
        mockPut.mockResolvedValue({ data: { data: { ...FIXTURE[1], description: 'Updated.' } } });
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-context-row-2');
        await userEvent.click(screen.getByTestId('admin-path-context-row-2-edit'));

        expect(screen.getByTestId('path-context-form')).toHaveAttribute('data-mode', 'edit');
        expect(screen.getByTestId('path-context-project')).toBeDisabled();
        expect(screen.getByTestId('path-context-prefix')).toHaveAttribute('readonly');
        expect(screen.getByTestId('path-context-description')).toHaveValue('Current HR policies approved by the DPO.');

        const description = screen.getByTestId('path-context-description');
        await userEvent.clear(description);
        await userEvent.type(description, 'Updated.');
        await userEvent.click(screen.getByTestId('path-context-save'));
        await waitFor(() =>
            expect(mockPut).toHaveBeenCalledWith('/api/admin/kb/path-contexts', {
                project_key: 'hr',
                path_prefix: 'policies/',
                description: 'Updated.',
            }),
        );
    });

    it('an empty submit shows per-field errors and never calls the API', async () => {
        respondWith([]);
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-contexts-empty');
        await userEvent.click(screen.getByTestId('admin-path-contexts-add'));
        await userEvent.click(screen.getByTestId('path-context-save'));

        expect(screen.getByTestId('path-context-project-error')).toBeVisible();
        expect(screen.getByTestId('path-context-prefix-error')).toBeVisible();
        expect(screen.getByTestId('path-context-description-error')).toBeVisible();
        expect(screen.getByTestId('path-context-prefix')).toHaveAttribute('aria-invalid', 'true');
        expect(mockPut).not.toHaveBeenCalled();
    });

    it('renders 422 field errors next to their inputs', async () => {
        respondWith([]);
        mockPut.mockRejectedValue(
            validationError({
                path_prefix: ['The path prefix is invalid.'],
                description: ['The description is too short.'],
            }),
        );
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-contexts-empty');
        await userEvent.click(screen.getByTestId('admin-path-contexts-add'));
        await userEvent.selectOptions(screen.getByTestId('path-context-project'), 'hr');
        await userEvent.type(screen.getByTestId('path-context-prefix'), 'policies/');
        await userEvent.type(screen.getByTestId('path-context-description'), 'x');
        await userEvent.click(screen.getByTestId('path-context-save'));

        expect(await screen.findByTestId('path-context-prefix-error')).toHaveTextContent('The path prefix is invalid.');
        expect(screen.getByTestId('path-context-description-error')).toHaveTextContent('The description is too short.');
        expect(screen.queryByTestId('path-context-project-error')).not.toBeInTheDocument();
        // The form stays open so the admin can fix the input.
        expect(screen.getByTestId('path-context-form')).toBeVisible();
    });

    it('surfaces a non-422 save failure loudly instead of closing the form', async () => {
        respondWith([]);
        mockPut.mockRejectedValue(
            Object.assign(new Error('x'), { response: { status: 500, data: { message: 'Server exploded.' } } }),
        );
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-contexts-empty');
        await userEvent.click(screen.getByTestId('admin-path-contexts-add'));
        await userEvent.selectOptions(screen.getByTestId('path-context-project'), 'hr');
        await userEvent.type(screen.getByTestId('path-context-prefix'), 'policies/');
        await userEvent.type(screen.getByTestId('path-context-description'), 'ok');
        await userEvent.click(screen.getByTestId('path-context-save'));

        expect(await screen.findByTestId('path-context-form-error')).toHaveTextContent('Server exploded.');
        expect(screen.getByTestId('path-context-form')).toBeVisible();
    });

    it('description counter tracks length and flags the limit', async () => {
        respondWith([]);
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-contexts-empty');
        await userEvent.click(screen.getByTestId('admin-path-contexts-add'));
        const count = screen.getByTestId('path-context-description-count');
        expect(count).toHaveTextContent('0 / 500');
        expect(count).toHaveAttribute('data-over-limit', 'false');

        await userEvent.type(screen.getByTestId('path-context-description'), 'hello');
        expect(count).toHaveTextContent('5 / 500');

        // paste (not 501 keystrokes) to cross the limit
        await userEvent.click(screen.getByTestId('path-context-description'));
        await userEvent.paste('a'.repeat(496));
        expect(count).toHaveTextContent('501 / 500');
        expect(count).toHaveAttribute('data-over-limit', 'true');
    });

    it('cancel closes the form without calling the API', async () => {
        respondWith([]);
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-contexts-empty');
        await userEvent.click(screen.getByTestId('admin-path-contexts-add'));
        await userEvent.click(screen.getByTestId('path-context-cancel'));
        expect(screen.queryByTestId('path-context-form')).not.toBeInTheDocument();
        expect(mockPut).not.toHaveBeenCalled();
    });

    it('delete requires the confirm click before firing the API', async () => {
        respondWith(FIXTURE);
        mockDelete.mockResolvedValue({ status: 204 });
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-context-row-1');
        await userEvent.click(screen.getByTestId('admin-path-context-row-1-delete'));
        expect(screen.getByTestId('admin-path-context-row-1-delete-confirm')).toBeVisible();
        expect(mockDelete).not.toHaveBeenCalled();

        await userEvent.click(screen.getByTestId('admin-path-context-row-1-delete-confirm'));
        await waitFor(() => expect(mockDelete).toHaveBeenCalledWith('/api/admin/kb/path-contexts/1'));
    });

    it('cancelling the confirm does not delete', async () => {
        respondWith(FIXTURE);
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-context-row-1');
        await userEvent.click(screen.getByTestId('admin-path-context-row-1-delete'));
        await userEvent.click(screen.getByTestId('admin-path-context-row-1-delete-cancel'));
        expect(screen.getByTestId('admin-path-context-row-1-delete')).toBeVisible();
        expect(mockDelete).not.toHaveBeenCalled();
    });

    it('surfaces a delete failure instead of swallowing it', async () => {
        respondWith(FIXTURE);
        mockDelete.mockRejectedValue(
            Object.assign(new Error('x'), { response: { status: 500, data: { message: 'delete failed' } } }),
        );
        render(withQueryClient(<PathContextsView />));
        await screen.findByTestId('admin-path-context-row-1');
        await userEvent.click(screen.getByTestId('admin-path-context-row-1-delete'));
        await userEvent.click(screen.getByTestId('admin-path-context-row-1-delete-confirm'));
        expect(await screen.findByTestId('admin-path-contexts-delete-error')).toHaveTextContent('delete failed');
    });
});
