import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { WikiExplorerView } from './WikiExplorerView';
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

const PROJECTS = { data: { projects: ['eng', 'hr'] } };

function page(over: { id: number; slug: string; generation_source: 'auto' | 'human'; backlinks?: number; outgoing_edges?: number }) {
    return {
        id: over.id,
        project_key: 'eng',
        slug: over.slug,
        title: over.slug,
        canonical_type: 'decision',
        canonical_status: 'accepted',
        generation_source: over.generation_source,
        outgoing_edges: over.outgoing_edges ?? 0,
        backlinks: over.backlinks ?? 0,
        updated_at: '2026-06-14T00:00:00+00:00',
    };
}

function listResp(pages: ReturnType<typeof page>[]) {
    return { data: { data: { tier: 'all', project_key: 'eng', total: pages.length, pages } } };
}

function routeGet(list: unknown) {
    return (url: string) => {
        if (url === '/api/admin/kb/projects') return Promise.resolve(PROJECTS);
        if (url === '/api/admin/kb/wiki-pages') return Promise.resolve(list);
        return Promise.reject(new Error(`unexpected GET ${url}`));
    };
}

async function selectProject(value = 'eng') {
    const sel = await screen.findByTestId('admin-wiki-explorer-project');
    await screen.findByRole('option', { name: value });
    await userEvent.selectOptions(sel, value);
}

describe('WikiExplorerView', () => {
    it('starts idle until a project is selected', async () => {
        mockGet.mockImplementation(routeGet(listResp([])));
        render(withQueryClient(<WikiExplorerView />));
        const idle = await screen.findByTestId('admin-wiki-explorer-idle');
        expect(idle).toHaveAttribute('data-state', 'idle');
    });

    it('project options derive from the DB (R18)', async () => {
        mockGet.mockImplementation(routeGet(listResp([])));
        render(withQueryClient(<WikiExplorerView />));
        await waitFor(() => {
            const sel = screen.getByTestId('admin-wiki-explorer-project') as HTMLSelectElement;
            expect(Array.from(sel.options).map((o) => o.value)).toEqual(['', 'eng', 'hr']);
        });
    });

    it('shows the empty state when the project has no pages', async () => {
        mockGet.mockImplementation(routeGet(listResp([])));
        render(withQueryClient(<WikiExplorerView />));
        await selectProject();
        const empty = await screen.findByTestId('admin-wiki-explorer-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('renders auto pages with actions and human pages read-only', async () => {
        mockGet.mockImplementation(routeGet(listResp([
            page({ id: 1, slug: 'auto-a', generation_source: 'auto', backlinks: 2, outgoing_edges: 1 }),
            page({ id: 2, slug: 'human-a', generation_source: 'human' }),
        ])));
        render(withQueryClient(<WikiExplorerView />));
        await selectProject();
        await waitFor(() => expect(screen.getByTestId('admin-wiki-explorer-table')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('admin-wiki-explorer-row-1-tier')).toHaveTextContent('auto');
        expect(screen.getByTestId('admin-wiki-explorer-row-1-promote')).toBeInTheDocument();
        expect(screen.getByTestId('admin-wiki-explorer-row-1-discard')).toBeInTheDocument();
        // The human page has no promote/discard — it is read-only.
        expect(screen.getByTestId('admin-wiki-explorer-row-2-readonly')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-wiki-explorer-row-2-promote')).not.toBeInTheDocument();
    });

    it('promotes an auto page and shows the success note', async () => {
        mockGet.mockImplementation(routeGet(listResp([page({ id: 1, slug: 'auto-a', generation_source: 'auto' })])));
        mockPost.mockResolvedValue({ data: { data: { promoted: true, slug: 'auto-a' } } });
        render(withQueryClient(<WikiExplorerView />));
        await selectProject();
        await userEvent.click(await screen.findByTestId('admin-wiki-explorer-row-1-promote'));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/api/admin/kb/documents/1/wiki-promote', {}));
        expect(await screen.findByTestId('admin-wiki-explorer-note')).toHaveTextContent('Promoted auto-a');
    });

    it('surfaces a 200-with-refusal distinctly (R14)', async () => {
        mockGet.mockImplementation(routeGet(listResp([page({ id: 1, slug: 'auto-a', generation_source: 'auto' })])));
        mockPost.mockResolvedValue({ data: { data: { promoted: false, reason: 'not_auto' } } });
        render(withQueryClient(<WikiExplorerView />));
        await selectProject();
        await userEvent.click(await screen.findByTestId('admin-wiki-explorer-row-1-promote'));
        expect(await screen.findByTestId('admin-wiki-explorer-action-error')).toHaveTextContent('not_auto');
    });

    it('surfaces a discard transport error (not silent)', async () => {
        mockGet.mockImplementation(routeGet(listResp([page({ id: 1, slug: 'auto-a', generation_source: 'auto' })])));
        mockPost.mockRejectedValue(new Error('discard boom 500'));
        render(withQueryClient(<WikiExplorerView />));
        await selectProject();
        await userEvent.click(await screen.findByTestId('admin-wiki-explorer-row-1-discard'));
        expect(await screen.findByTestId('admin-wiki-explorer-action-error')).toHaveTextContent('discard boom 500');
    });

    it('renders an error state (not empty) when the pages query fails', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url === '/api/admin/kb/projects') return Promise.resolve(PROJECTS);
            return Promise.reject(new Error('pages boom 500'));
        });
        render(withQueryClient(<WikiExplorerView />));
        await selectProject();
        const err = await screen.findByTestId('admin-wiki-explorer-error');
        expect(err).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('admin-wiki-explorer-empty')).not.toBeInTheDocument();
    });
});
