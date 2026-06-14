import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { WikiHealthView } from './WikiHealthView';
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

const PROJECTS = { data: { projects: ['docs-v3', 'hr'] } };

function report(over: Partial<{ healthy: boolean; counts: Record<string, number>; findings: Record<string, unknown> }> = {}) {
    return {
        data: {
            data: {
                project_key: 'docs-v3',
                findings: { dangling: [], orphan: [], stale_cross_ref: [], missing_index: false, ...(over.findings ?? {}) },
                counts: { dangling: 0, orphan: 0, stale_cross_ref: 0, missing_index: 0, ...(over.counts ?? {}) },
                healthy: over.healthy ?? true,
            },
        },
    };
}

/** Select a project once its option has loaded from the (async) projects query. */
async function selectProject(value = 'docs-v3') {
    const sel = await screen.findByTestId('admin-wiki-health-project');
    await screen.findByRole('option', { name: value });
    await userEvent.selectOptions(sel, value);
}

/** Route api.get by URL: projects vs wiki-lint. */
function routeGet(lint: unknown) {
    return (url: string) => {
        if (url === '/api/admin/kb/projects') return Promise.resolve(PROJECTS);
        if (url === '/api/admin/kb/wiki-lint') return Promise.resolve(lint);
        return Promise.reject(new Error(`unexpected GET ${url}`));
    };
}

describe('WikiHealthView', () => {
    it('starts idle until a project is selected', async () => {
        mockGet.mockImplementation(routeGet(report()));
        render(withQueryClient(<WikiHealthView />));
        const idle = await screen.findByTestId('admin-wiki-health-idle');
        expect(idle).toHaveAttribute('data-state', 'idle');
    });

    it('project options derive from the DB (R18)', async () => {
        mockGet.mockImplementation(routeGet(report()));
        render(withQueryClient(<WikiHealthView />));
        await waitFor(() => {
            const sel = screen.getByTestId('admin-wiki-health-project') as HTMLSelectElement;
            expect(Array.from(sel.options).map((o) => o.value)).toEqual(['', 'docs-v3', 'hr']);
        });
    });

    it('shows the healthy empty state for a clean project', async () => {
        mockGet.mockImplementation(routeGet(report({ healthy: true })));
        render(withQueryClient(<WikiHealthView />));
        await selectProject('docs-v3');
        const empty = await screen.findByTestId('admin-wiki-health-empty');
        expect(empty).toHaveAttribute('data-state', 'empty');
    });

    it('renders findings + counts for an unhealthy project', async () => {
        mockGet.mockImplementation(routeGet(report({
            healthy: false,
            counts: { dangling: 2, orphan: 1, stale_cross_ref: 1, missing_index: 1 },
            findings: {
                dangling: ['ghost-a', 'ghost-b'],
                orphan: ['lonely'],
                stale_cross_ref: [{ edge: 'a->dep:related_to', target: 'dep', reason: 'deprecated' }],
                missing_index: true,
            },
        })));
        render(withQueryClient(<WikiHealthView />));
        await selectProject('docs-v3');
        await waitFor(() => expect(screen.getByTestId('admin-wiki-health-report')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('admin-wiki-health-count-dangling')).toHaveTextContent('2');
        expect(screen.getByTestId('admin-wiki-health-dangling')).toHaveTextContent('ghost-a');
        expect(screen.getByTestId('admin-wiki-health-stale')).toHaveTextContent('deprecated');
        expect(screen.getByTestId('admin-wiki-health-missing-index')).toBeVisible();
    });

    it('auto-fix POSTs and shows a success note', async () => {
        mockGet.mockImplementation(routeGet(report({ healthy: false, counts: { dangling: 1, orphan: 0, stale_cross_ref: 0, missing_index: 0 }, findings: { dangling: ['ghost'] } })));
        mockPost.mockResolvedValue({ data: { data: { pruned_dangling: 1, pruned: ['ghost'] } } });
        render(withQueryClient(<WikiHealthView />));
        await selectProject('docs-v3');
        await userEvent.click(await screen.findByTestId('admin-wiki-health-fix'));
        await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/api/admin/kb/wiki-lint/fix', { project_key: 'docs-v3' }));
        expect(await screen.findByTestId('admin-wiki-health-fix-note')).toHaveTextContent('Pruned 1');
    });

    it('surfaces a fix error in the DOM (not silent)', async () => {
        mockGet.mockImplementation(routeGet(report({ healthy: false, counts: { dangling: 1, orphan: 0, stale_cross_ref: 0, missing_index: 0 }, findings: { dangling: ['ghost'] } })));
        mockPost.mockRejectedValue(new Error('fix boom 500'));
        render(withQueryClient(<WikiHealthView />));
        await selectProject('docs-v3');
        await userEvent.click(await screen.findByTestId('admin-wiki-health-fix'));
        expect(await screen.findByTestId('admin-wiki-health-action-error')).toHaveTextContent('fix boom 500');
    });

    it('renders an error state (not empty) when the lint query fails', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url === '/api/admin/kb/projects') return Promise.resolve(PROJECTS);
            return Promise.reject(new Error('lint boom 500'));
        });
        render(withQueryClient(<WikiHealthView />));
        await selectProject('docs-v3');
        const err = await screen.findByTestId('admin-wiki-health-error');
        expect(err).toHaveAttribute('data-state', 'error');
        expect(screen.queryByTestId('admin-wiki-health-empty')).not.toBeInTheDocument();
    });
});
