import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactElement } from 'react';
import type { KbTreeResponse } from '../admin/admin.api';
import { chatApi, type CitationDocument } from '../chat/chat.api';
import { useTeamStore } from '../../lib/team-store';
import { kbBrowseApi } from './kb-browse.api';
import { KnowledgeBrowseView } from './KnowledgeBrowseView';

let search: { doc?: number } = {};

vi.mock('@tanstack/react-router', () => ({
    useSearch: () => search,
}));

// Markdown pulls remark/unified; a passthrough keeps the test on this
// component's behaviour rather than markdown internals.
vi.mock('../../lib/markdown', () => ({
    Markdown: ({ source }: { source: string }) => <div data-testid="md">{source}</div>,
}));

function tree(docs: Array<{ id: number; name: string; path: string }>): KbTreeResponse {
    return {
        tree: docs.map((d) => ({
            type: 'doc' as const,
            name: d.name,
            path: d.path,
            meta: {
                id: d.id,
                project_key: 'engineering',
                slug: null,
                canonical_type: null,
                canonical_status: null,
                is_canonical: false,
                indexed_at: null,
                deleted_at: null,
            },
        })),
        counts: { docs: docs.length, canonical: 0, trashed: 0 },
        generated_at: '2026-09-16T10:00:00Z',
    } as unknown as KbTreeResponse;
}

function document(overrides: Partial<CitationDocument> = {}): CitationDocument {
    return {
        document_id: 1,
        title: 'Cache ADR',
        source_path: 'engineering/adr/0001-cache.md',
        slug: 'dec-cache-v2',
        project_key: 'engineering',
        source_type: 'markdown',
        canonical_type: 'decision',
        canonical_status: 'accepted',
        is_canonical: true,
        content: '---\nslug: dec-cache-v2\n---\nWe chose Redis.',
        ...overrides,
    };
}

function renderView(ui: ReactElement) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
    search = {};
    useTeamStore.setState({
        teams: [
            {
                tenant_id: 'acme',
                hash: 'h-acme',
                name: 'Acme',
                projects: [
                    { project_key: 'engineering', role: 'member', scope: null },
                    { project_key: 'accounting', role: 'member', scope: null },
                ],
            },
        ],
        currentTeam: 'acme',
        userId: 1,
    });
    vi.spyOn(kbBrowseApi, 'tree').mockResolvedValue(
        tree([{ id: 1, name: '0001-cache.md', path: 'engineering/adr/0001-cache.md' }]),
    );
    vi.spyOn(chatApi, 'fetchCitationDocument').mockResolvedValue(document());
});

afterEach(() => vi.restoreAllMocks());

describe('KnowledgeBrowseView', () => {
    it('hides the "Include deleted" toggle — the reader endpoint never returns trashed docs', async () => {
        renderView(<KnowledgeBrowseView />);

        await screen.findByTestId('kb-browse-view');
        // A control that cannot change the result is worse than none (R14).
        expect(screen.queryByTestId('kb-tree-with-trashed')).not.toBeInTheDocument();
    });

    it('never calls the admin tree endpoint', async () => {
        renderView(<KnowledgeBrowseView />);

        await waitFor(() => expect(kbBrowseApi.tree).toHaveBeenCalled());
        // The admin route is role-gated; a reader would get a 403.
        expect(kbBrowseApi.tree).toHaveBeenCalledWith(null, 'all');
    });

    it('derives the project options from the team membership, not a literal', async () => {
        renderView(<KnowledgeBrowseView />);

        const select = await screen.findByTestId('kb-browse-project');
        // R18: All projects + the two memberships from /api/auth/me.
        expect(Array.from(select.querySelectorAll('option')).map((o) => o.textContent)).toEqual([
            'All projects',
            'accounting',
            'engineering',
        ]);
    });

    it('scopes the tree to the chosen project', async () => {
        renderView(<KnowledgeBrowseView />);

        await userEvent.selectOptions(
            await screen.findByTestId('kb-browse-project'),
            'engineering',
        );

        await waitFor(() => expect(kbBrowseApi.tree).toHaveBeenCalledWith('engineering', 'all'));
    });

    it('invites the reader to pick a document before one is open', async () => {
        renderView(<KnowledgeBrowseView />);

        expect(await screen.findByTestId('kb-browse-detail-idle')).toBeInTheDocument();
        expect(screen.getByTestId('kb-browse-detail')).toHaveAttribute('data-state', 'idle');
    });

    it('renders the selected document with its frontmatter pills', async () => {
        renderView(<KnowledgeBrowseView />);

        await userEvent.click(await screen.findByText('0001-cache.md'));

        await waitFor(() =>
            expect(screen.getByTestId('kb-browse-detail')).toHaveAttribute('data-state', 'ready'),
        );
        expect(screen.getByTestId('kb-browse-detail-title')).toHaveTextContent('Cache ADR');
        expect(screen.getByTestId('kb-browse-detail-pills')).toHaveTextContent('slug');
        // The frontmatter fence is stripped from the rendered body.
        expect(screen.getByTestId('md')).toHaveTextContent('We chose Redis.');
        expect(screen.getByTestId('md')).not.toHaveTextContent('slug: dec-cache-v2');
    });

    it('opens the deep-linked document straight away', async () => {
        search = { doc: 42 };
        renderView(<KnowledgeBrowseView />);

        await waitFor(() => expect(chatApi.fetchCitationDocument).toHaveBeenCalledWith(42));
        expect(await screen.findByTestId('kb-browse-detail-title')).toBeInTheDocument();
    });

    it('lets an explicit tree click override the deep link', async () => {
        search = { doc: 42 };
        renderView(<KnowledgeBrowseView />);
        await waitFor(() => expect(chatApi.fetchCitationDocument).toHaveBeenCalledWith(42));

        await userEvent.click(await screen.findByText('0001-cache.md'));

        await waitFor(() => expect(chatApi.fetchCitationDocument).toHaveBeenCalledWith(1));
    });

    it('surfaces a document failure with a retry instead of a blank pane', async () => {
        vi.mocked(chatApi.fetchCitationDocument).mockRejectedValue(new Error('403'));
        renderView(<KnowledgeBrowseView />);

        await userEvent.click(await screen.findByText('0001-cache.md'));

        expect(await screen.findByTestId('kb-browse-detail-error')).toBeInTheDocument();
        expect(screen.getByTestId('kb-browse-detail')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('kb-browse-detail-retry')).toBeInTheDocument();
    });

    it('distinguishes a document with no indexed content from a load error', async () => {
        vi.mocked(chatApi.fetchCitationDocument).mockResolvedValue(document({ content: '' }));
        renderView(<KnowledgeBrowseView />);

        await userEvent.click(await screen.findByText('0001-cache.md'));

        expect(await screen.findByTestId('kb-browse-detail-empty')).toBeInTheDocument();
        expect(screen.getByTestId('kb-browse-detail')).toHaveAttribute('data-state', 'empty');
        // Still shows WHICH document is empty.
        expect(screen.getByTestId('kb-browse-detail-title')).toBeInTheDocument();
    });

    it('reports an empty knowledge base through the tree state', async () => {
        vi.mocked(kbBrowseApi.tree).mockResolvedValue(tree([]));
        renderView(<KnowledgeBrowseView />);

        await waitFor(() =>
            expect(screen.getByTestId('kb-tree')).toHaveAttribute('data-state', 'empty'),
        );
    });
});
