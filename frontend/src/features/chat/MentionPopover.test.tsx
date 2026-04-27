import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { MentionPopover } from './MentionPopover';
import { api } from '../../lib/api';

/**
 * MentionPopover lives behind a TanStack Query hook (use-mention-search)
 * so the test wrapper provides a fresh client and stubs the
 * `api.get` axios call. Ergonomics: each test mounts in a fresh
 * QueryClient so cache state from one test never leaks into the next.
 */

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const mockGet = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    // Stub the shared axios instance's `get` method.
    vi.spyOn(api, 'get').mockImplementation(mockGet);
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('MentionPopover', () => {
    it('renders nothing when open=false', () => {
        const { container } = render(
            withQueryClient(
                <MentionPopover query="policy" open={false} onSelect={() => {}} onClose={() => {}} />,
            ),
        );
        expect(container.firstChild).toBeNull();
    });

    it('shows a loading state while the query is in flight', async () => {
        // Axios get returns a never-resolving promise → query stays loading.
        mockGet.mockImplementation(() => new Promise(() => {}));
        render(
            withQueryClient(
                <MentionPopover query="policy" open onSelect={() => {}} onClose={() => {}} />,
            ),
        );
        const popover = await screen.findByTestId('mention-popover');
        expect(popover).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('mention-popover-loading')).toBeVisible();
    });

    it('renders one option per result (sample list of 3 docs)', async () => {
        mockGet.mockResolvedValue({
            data: {
                data: [
                    { id: 1, project_key: 'hr', title: 'Policy Alpha', source_path: 'p/a.md', source_type: 'md', canonical_type: null },
                    { id: 2, project_key: 'hr', title: 'Policy Beta', source_path: 'p/b.md', source_type: 'md', canonical_type: null },
                    { id: 3, project_key: 'hr', title: 'Other Doc', source_path: 'o.md', source_type: 'md', canonical_type: null },
                ],
            },
        });

        render(
            withQueryClient(
                <MentionPopover query="policy" open onSelect={() => {}} onClose={() => {}} />,
            ),
        );

        await waitFor(() => {
            expect(screen.getByTestId('mention-option-1')).toBeVisible();
        });
        expect(screen.getByTestId('mention-option-1')).toHaveTextContent('Policy Alpha');
        expect(screen.getByTestId('mention-option-2')).toHaveTextContent('Policy Beta');
        expect(screen.getByTestId('mention-option-3')).toHaveTextContent('Other Doc');
    });

    it('shows an empty-state message when there are zero results', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(
            withQueryClient(
                <MentionPopover query="zzz" open onSelect={() => {}} onClose={() => {}} />,
            ),
        );
        const empty = await screen.findByTestId('mention-popover-empty');
        expect(empty).toBeVisible();
        // Echoes the query so the user knows what didn't match.
        expect(empty).toHaveTextContent('zzz');
    });

    it('marks the first result active by default (data-active=true + aria-selected=true)', async () => {
        mockGet.mockResolvedValue({
            data: {
                data: [
                    { id: 10, project_key: 'hr', title: 'A', source_path: 'a.md', source_type: 'md', canonical_type: null },
                    { id: 11, project_key: 'hr', title: 'B', source_path: 'b.md', source_type: 'md', canonical_type: null },
                ],
            },
        });
        render(
            withQueryClient(
                <MentionPopover query="x" open onSelect={() => {}} onClose={() => {}} />,
            ),
        );
        await waitFor(() => expect(screen.getByTestId('mention-option-10')).toBeVisible());
        expect(screen.getByTestId('mention-option-10')).toHaveAttribute('data-active', 'true');
        expect(screen.getByTestId('mention-option-10')).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByTestId('mention-option-11')).toHaveAttribute('data-active', 'false');
    });

    it('excludes already-selected ids from the visible results', async () => {
        mockGet.mockResolvedValue({
            data: {
                data: [
                    { id: 1, project_key: 'hr', title: 'A', source_path: 'a.md', source_type: 'md', canonical_type: null },
                    { id: 2, project_key: 'hr', title: 'B', source_path: 'b.md', source_type: 'md', canonical_type: null },
                ],
            },
        });
        render(
            withQueryClient(
                <MentionPopover query="x" open excludeIds={[1]} onSelect={() => {}} onClose={() => {}} />,
            ),
        );
        await waitFor(() => expect(screen.getByTestId('mention-option-2')).toBeVisible());
        // Already selected id is filtered out — no duplicate selection possible.
        expect(screen.queryByTestId('mention-option-1')).not.toBeInTheDocument();
    });

    it('respects the `limit` prop (caps visible options)', async () => {
        // Five results, limit=2 → only the first 2 render.
        const data = Array.from({ length: 5 }, (_, i) => ({
            id: 100 + i,
            project_key: 'hr',
            title: `Doc ${i}`,
            source_path: `${i}.md`,
            source_type: 'md',
            canonical_type: null,
        }));
        mockGet.mockResolvedValue({ data: { data } });
        render(
            withQueryClient(
                <MentionPopover query="x" open limit={2} onSelect={() => {}} onClose={() => {}} />,
            ),
        );
        await waitFor(() => expect(screen.getByTestId('mention-option-100')).toBeVisible());
        expect(screen.getByTestId('mention-option-101')).toBeVisible();
        expect(screen.queryByTestId('mention-option-102')).not.toBeInTheDocument();
    });

    it('exposes role=listbox + aria-activedescendant for screen readers', async () => {
        mockGet.mockResolvedValue({
            data: {
                data: [
                    { id: 7, project_key: 'hr', title: 'A', source_path: 'a.md', source_type: 'md', canonical_type: null },
                ],
            },
        });
        render(
            withQueryClient(
                <MentionPopover query="x" open onSelect={() => {}} onClose={() => {}} />,
            ),
        );
        await waitFor(() => expect(screen.getByTestId('mention-option-7')).toBeVisible());
        const popover = screen.getByTestId('mention-popover');
        expect(popover).toHaveAttribute('role', 'listbox');
        expect(popover).toHaveAttribute('aria-activedescendant', 'mention-option-7');
    });
});
