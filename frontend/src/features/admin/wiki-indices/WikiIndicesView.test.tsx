import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { WikiIndicesView } from './WikiIndicesView';
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

const EMPTY_HUB = { data: { data: { hub: null, projects: [] } } };
const NO_OPS = { data: { data: [] } };

function hub(projects: Array<{ project_key: string; page_total: number; concept_count: number; auto_count: number; human_count: number }>) {
    return {
        data: {
            data: {
                hub: {
                    project_key: '*',
                    index_type: 'tenant_hub',
                    payload: {
                        project_count: projects.length,
                        total_pages: projects.reduce((s, p) => s + p.page_total, 0),
                        total_concepts: projects.reduce((s, p) => s + p.concept_count, 0),
                        projects,
                    },
                    updated_at: '2026-06-14T00:00:00+00:00',
                },
                projects: projects.map((p) => ({
                    project_key: p.project_key,
                    index_type: 'project',
                    payload: {
                        page_counts_by_type: { decision: p.page_total },
                        page_total: p.page_total,
                        concept_count: p.concept_count,
                        auto_count: p.auto_count,
                        human_count: p.human_count,
                        recently_changed: [],
                    },
                    updated_at: '2026-06-14T00:00:00+00:00',
                })),
            },
        },
    };
}

function ops(rows: Array<{ id: number; project_key: string; event_type: string; slug?: string | null }>) {
    return { data: { data: rows.map((r) => ({ doc_id: null, slug: null, metadata: null, created_at: '2026-06-14T00:00:00+00:00', ...r })) } };
}

/** Route api.get by URL: index vs operations. */
function routeGet(index: unknown, operations: unknown = NO_OPS) {
    return (url: string) => {
        if (url === '/api/admin/kb/wiki-index') return Promise.resolve(index);
        if (url === '/api/admin/kb/wiki-operations') return Promise.resolve(operations);
        return Promise.reject(new Error(`unexpected GET ${url}`));
    };
}

describe('WikiIndicesView', () => {
    it('shows the empty state when no index has been built', async () => {
        mockGet.mockImplementation(routeGet(EMPTY_HUB));
        render(withQueryClient(<WikiIndicesView />));
        const empty = await screen.findByTestId('admin-wiki-indices-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('renders hub stats + per-project rows', async () => {
        mockGet.mockImplementation(routeGet(hub([
            { project_key: 'eng', page_total: 4, concept_count: 1, auto_count: 1, human_count: 3 },
            { project_key: 'hr', page_total: 2, concept_count: 0, auto_count: 0, human_count: 2 },
        ])));
        render(withQueryClient(<WikiIndicesView />));
        await waitFor(() => expect(screen.getByTestId('admin-wiki-indices-hub')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('admin-wiki-indices-stat-projects')).toHaveTextContent('2');
        expect(screen.getByTestId('admin-wiki-indices-stat-pages')).toHaveTextContent('6');
        expect(screen.getByTestId('admin-wiki-indices-project-row-eng')).toHaveTextContent('eng');
        expect(screen.getByTestId('admin-wiki-indices-project-row-eng')).toHaveTextContent('4');
    });

    it('renders the operation log', async () => {
        mockGet.mockImplementation(routeGet(
            hub([{ project_key: 'eng', page_total: 1, concept_count: 0, auto_count: 0, human_count: 1 }]),
            ops([{ id: 7, project_key: 'eng', event_type: 'graph_rebuild' }]),
        ));
        render(withQueryClient(<WikiIndicesView />));
        expect(await screen.findByTestId('admin-wiki-indices-operation-7')).toHaveTextContent('graph_rebuild');
    });

    it('shows an empty operation log message when there are no operations', async () => {
        mockGet.mockImplementation(routeGet(hub([{ project_key: 'eng', page_total: 1, concept_count: 0, auto_count: 0, human_count: 1 }]), NO_OPS));
        render(withQueryClient(<WikiIndicesView />));
        expect(await screen.findByTestId('admin-wiki-indices-operations-empty')).toBeVisible();
    });

    it('rebuild POSTs and shows a success note', async () => {
        mockGet.mockImplementation(routeGet(EMPTY_HUB));
        mockPost.mockResolvedValue({ data: { data: { projects: ['eng'], hub_project_count: 1 } } });
        render(withQueryClient(<WikiIndicesView />));
        await userEvent.click(await screen.findByTestId('admin-wiki-indices-rebuild'));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/api/admin/kb/wiki-index', {}));
        expect(await screen.findByTestId('admin-wiki-indices-rebuild-note')).toHaveTextContent('Rebuilt 1');
    });

    it('surfaces a rebuild error in the DOM (not silent)', async () => {
        mockGet.mockImplementation(routeGet(EMPTY_HUB));
        mockPost.mockRejectedValue(new Error('rebuild boom 500'));
        render(withQueryClient(<WikiIndicesView />));
        await userEvent.click(await screen.findByTestId('admin-wiki-indices-rebuild'));
        expect(await screen.findByTestId('admin-wiki-indices-action-error')).toHaveTextContent('rebuild boom 500');
    });

    it('renders an error state (not empty) when the index query fails', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url === '/api/admin/kb/wiki-operations') return Promise.resolve(NO_OPS);
            return Promise.reject(new Error('index boom 500'));
        });
        render(withQueryClient(<WikiIndicesView />));
        const err = await screen.findByTestId('admin-wiki-indices-error');
        expect(err).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('admin-wiki-indices-empty')).not.toBeInTheDocument();
    });
});
