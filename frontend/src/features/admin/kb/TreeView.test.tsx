import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TreeView } from './TreeView';
import type { KbTreeResponse } from '../admin.api';

const seed: KbTreeResponse = {
    tree: [
        {
            type: 'folder',
            name: 'policies',
            path: 'policies',
            children: [
                {
                    type: 'doc',
                    name: 'remote-work-policy.md',
                    path: 'policies/remote-work-policy.md',
                    meta: {
                        id: 1,
                        project_key: 'hr-portal',
                        title: 'Remote Work Policy',
                        slug: 'remote-work-policy',
                        canonical_type: 'policy',
                        canonical_status: 'accepted',
                        is_canonical: true,
                        indexed_at: '2026-04-24T10:00:00Z',
                        updated_at: '2026-04-24T10:00:00Z',
                        deleted_at: null,
                    },
                },
            ],
        },
    ],
    counts: { docs: 1, canonical: 1, trashed: 0 },
    generated_at: '2026-04-24T10:00:00Z',
};

function renderTree(overrides: Partial<Parameters<typeof TreeView>[0]> = {}) {
    const props: Parameters<typeof TreeView>[0] = {
        data: seed,
        state: 'ready',
        q: '',
        onQChange: vi.fn(),
        mode: 'all',
        onModeChange: vi.fn(),
        withTrashed: false,
        onWithTrashedChange: vi.fn(),
        selectedPath: null,
        onSelect: vi.fn(),
        ...overrides,
    };
    return { props, ...render(<TreeView {...props} />) };
}

describe('TreeView', () => {
    it('renders loading state via data-state=loading + skeleton', () => {
        renderTree({ data: undefined, state: 'loading' });
        const wrapper = screen.getByTestId('kb-tree');
        expect(wrapper).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('kb-tree-skeleton')).toBeInTheDocument();
    });

    it('renders empty state via data-state=empty + empty marker', () => {
        renderTree({
            data: { tree: [], counts: { docs: 0, canonical: 0, trashed: 0 }, generated_at: '' },
            state: 'empty',
        });
        expect(screen.getByTestId('kb-tree')).toHaveAttribute('data-state', 'empty');
        expect(screen.getByTestId('kb-tree-empty')).toBeInTheDocument();
    });

    it('renders error state via data-state=error + error marker', () => {
        renderTree({ data: undefined, state: 'error' });
        expect(screen.getByTestId('kb-tree')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('kb-tree-error')).toBeInTheDocument();
    });

    it('renders doc node with canonical badge + testid', () => {
        renderTree();
        expect(screen.getByTestId('kb-tree')).toHaveAttribute('data-state', 'ready');

        const folder = screen.getByTestId('kb-tree-node-policies');
        expect(folder).toHaveAttribute('data-type', 'folder');

        const doc = screen.getByTestId('kb-tree-node-policies/remote-work-policy.md');
        expect(doc).toHaveAttribute('data-type', 'doc');
        expect(doc).toHaveAttribute('data-canonical', 'true');
        expect(doc).toHaveAttribute('data-trashed', 'false');

        expect(
            screen.getByTestId('kb-tree-badge-canonical-policies/remote-work-policy.md'),
        ).toBeInTheDocument();
    });

    it('invokes onSelect when a doc leaf is clicked', () => {
        const onSelect = vi.fn();
        renderTree({ onSelect });

        fireEvent.click(screen.getByTestId('kb-tree-node-policies/remote-work-policy.md'));

        expect(onSelect).toHaveBeenCalledTimes(1);
        const [path, node] = onSelect.mock.calls[0] ?? [];
        expect(path).toBe('policies/remote-work-policy.md');
        expect(node?.type).toBe('doc');
    });

    it('fires onFolderSelect AND still toggles expansion when a folder is clicked', () => {
        const onFolderSelect = vi.fn();
        renderTree({ onFolderSelect });

        const folder = screen.getByTestId('kb-tree-node-policies');
        // Seeded folder renders open (depth < 1) so the child doc is visible.
        expect(screen.getByTestId('kb-tree-node-policies/remote-work-policy.md')).toBeInTheDocument();

        fireEvent.click(folder);

        // Navigation callback fired with the folder path…
        expect(onFolderSelect).toHaveBeenCalledTimes(1);
        expect(onFolderSelect).toHaveBeenCalledWith('policies');
        // …and the toggle still ran (folder collapsed → child gone).
        expect(screen.queryByTestId('kb-tree-node-policies/remote-work-policy.md')).toBeNull();
    });

    it('does not break folder toggling when onFolderSelect is omitted', () => {
        renderTree();
        const folder = screen.getByTestId('kb-tree-node-policies');
        fireEvent.click(folder);
        expect(screen.queryByTestId('kb-tree-node-policies/remote-work-policy.md')).toBeNull();
    });

    it('fires onModeChange when filter dropdown changes (drives query key)', () => {
        const onModeChange = vi.fn();
        renderTree({ onModeChange });

        fireEvent.change(screen.getByTestId('kb-tree-mode'), { target: { value: 'canonical' } });

        expect(onModeChange).toHaveBeenCalledWith('canonical');
    });

    it('filters visible nodes by q (client-side name match)', () => {
        renderTree({ q: 'pto-guidelines' });
        expect(screen.queryByTestId('kb-tree-node-policies/remote-work-policy.md')).toBeNull();
    });

    it('renders trashed doc with data-trashed=true', () => {
        renderTree({
            data: {
                tree: [
                    {
                        type: 'doc',
                        name: 'dead.md',
                        path: 'dead.md',
                        meta: {
                            id: 9,
                            project_key: 'hr-portal',
                            title: null,
                            slug: null,
                            canonical_type: null,
                            canonical_status: null,
                            is_canonical: false,
                            indexed_at: null,
                            updated_at: null,
                            deleted_at: '2026-04-01T00:00:00Z',
                        },
                    },
                ],
                counts: { docs: 1, canonical: 0, trashed: 1 },
                generated_at: '',
            },
            state: 'ready',
            withTrashed: true,
        });

        const doc = screen.getByTestId('kb-tree-node-dead.md');
        expect(doc).toHaveAttribute('data-trashed', 'true');
        expect(doc).toHaveAttribute('data-canonical', 'false');
    });
});
