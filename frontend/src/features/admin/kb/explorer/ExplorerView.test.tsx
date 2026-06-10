import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ExplorerView } from './ExplorerView';
import type { KbTreeNode, KbTreeResponse } from '../../admin.api';

function doc(name: string, path: string, id: number): KbTreeNode {
    return {
        type: 'doc',
        name,
        path,
        meta: {
            id,
            project_key: 'hr-portal',
            title: name.replace('.md', ''),
            slug: null,
            canonical_type: null,
            canonical_status: null,
            is_canonical: false,
            indexed_at: null,
            updated_at: null,
            deleted_at: null,
        },
    };
}

const tree: KbTreeNode[] = [
    {
        type: 'folder',
        name: 'policies',
        path: 'policies',
        children: [doc('remote.md', 'policies/remote.md', 1), doc('pto.md', 'policies/pto.md', 2)],
    },
    doc('readme.md', 'readme.md', 3),
];

const data: KbTreeResponse = {
    tree,
    counts: { docs: 3, canonical: 0, trashed: 0 },
    generated_at: '',
};

function renderExplorer(overrides: Partial<Parameters<typeof ExplorerView>[0]> = {}) {
    const props: Parameters<typeof ExplorerView>[0] = {
        tree: data.tree,
        state: 'ready',
        path: '',
        onPathChange: vi.fn(),
        q: '',
        layout: 'grid',
        size: 'md',
        onLayoutChange: vi.fn(),
        onSizeChange: vi.fn(),
        focusedDocId: null,
        onOpenDoc: vi.fn(),
        ...overrides,
    };
    return { props, ...render(<ExplorerView {...props} />) };
}

describe('ExplorerView', () => {
    it('renders folder tiles + top-level doc cards at root', () => {
        renderExplorer();
        expect(screen.getByTestId('kb-explorer')).toHaveAttribute('data-state', 'ready');
        // Root has one folder (policies) and one doc (readme).
        expect(screen.getByTestId('kb-explorer-folder-policies')).toBeInTheDocument();
        expect(screen.getByTestId('kb-explorer-doc-3')).toBeInTheDocument();
        // The nested docs are NOT at root.
        expect(screen.queryByTestId('kb-explorer-doc-1')).toBeNull();
    });

    it('navigates into a folder via onPathChange when a folder tile is clicked', () => {
        const onPathChange = vi.fn();
        renderExplorer({ onPathChange });
        fireEvent.click(screen.getByTestId('kb-explorer-folder-policies'));
        expect(onPathChange).toHaveBeenCalledWith('policies');
    });

    it('shows the folder contents + breadcrumb when path is set', () => {
        renderExplorer({ path: 'policies' });
        expect(screen.getByTestId('kb-explorer-doc-1')).toBeInTheDocument();
        expect(screen.getByTestId('kb-explorer-doc-2')).toBeInTheDocument();
        expect(screen.getByTestId('kb-explorer-crumb-policies')).toBeInTheDocument();
    });

    it('breadcrumb root crumb navigates back to root', () => {
        const onPathChange = vi.fn();
        renderExplorer({ path: 'policies', onPathChange });
        fireEvent.click(screen.getByTestId('kb-explorer-crumb-root'));
        expect(onPathChange).toHaveBeenCalledWith('');
    });

    it('renders empty-state marker (not ready content) for an empty folder', () => {
        renderExplorer({
            tree: [{ type: 'folder', name: 'empty', path: 'empty', children: [] }],
            path: 'empty',
        });
        expect(screen.getByTestId('kb-explorer')).toHaveAttribute('data-state', 'empty');
        expect(screen.getByTestId('kb-explorer-empty')).toBeInTheDocument();
        expect(screen.queryByTestId('kb-explorer-grid')).toBeNull();
    });

    it('flattens search results across folders when q is set', () => {
        renderExplorer({ q: 'pto' });
        // Deep match surfaces the nested doc even though path is root.
        expect(screen.getByTestId('kb-explorer-doc-2')).toBeInTheDocument();
        expect(screen.queryByTestId('kb-explorer-doc-1')).toBeNull();
        // Breadcrumb collapses to "Search results".
        expect(screen.getByText('Search results')).toBeInTheDocument();
    });

    it('fires onOpenDoc when a doc card is double-clicked', () => {
        const onOpenDoc = vi.fn();
        renderExplorer({ onOpenDoc });
        fireEvent.doubleClick(screen.getByTestId('kb-explorer-doc-3'));
        expect(onOpenDoc).toHaveBeenCalledTimes(1);
        expect(onOpenDoc.mock.calls[0]?.[0]?.meta.id).toBe(3);
    });

    it('hides selection checkboxes when no selection model is wired', () => {
        renderExplorer();
        expect(screen.queryByTestId('kb-explorer-doc-checkbox')).toBeNull();
        expect(screen.queryByTestId('kb-explorer-select-all')).toBeNull();
    });

    it('switches layout via the toolbar toggle', () => {
        const onLayoutChange = vi.fn();
        renderExplorer({ onLayoutChange });
        fireEvent.click(screen.getByTestId('kb-explorer-layout-list'));
        expect(onLayoutChange).toHaveBeenCalledWith('list');
    });

    // ── Selection + bulk (enabled when onBulkDelete is supplied) ──

    it('shows checkboxes and select-all when bulk handlers are wired', () => {
        renderExplorer({ path: 'policies', onBulkDelete: vi.fn(), onBulkRestore: vi.fn() });
        expect(screen.getByTestId('kb-explorer-select-all')).toBeInTheDocument();
        expect(screen.getAllByTestId('kb-explorer-doc-checkbox').length).toBe(2);
    });

    it('bulk toolbar is hidden until at least one doc is selected', () => {
        renderExplorer({ path: 'policies', onBulkDelete: vi.fn(), onBulkRestore: vi.fn() });
        expect(screen.queryByTestId('kb-explorer-bulk-toolbar')).toBeNull();

        fireEvent.click(screen.getByTestId('kb-explorer-doc-1'));
        expect(screen.getByTestId('kb-explorer-bulk-toolbar')).toBeInTheDocument();
        expect(screen.getByTestId('kb-explorer-bulk-count')).toHaveTextContent('1 selected');
    });

    it('zip href contains every selected id', () => {
        renderExplorer({ path: 'policies', onBulkDelete: vi.fn(), onBulkRestore: vi.fn() });
        fireEvent.click(screen.getByTestId('kb-explorer-select-all'));
        const zip = screen.getByTestId('kb-explorer-bulk-zip');
        const href = zip.getAttribute('href') ?? '';
        expect(href).toContain('ids[]=1');
        expect(href).toContain('ids[]=2');
    });

    it('restore button is absent when no selected doc is trashed', () => {
        renderExplorer({ path: 'policies', onBulkDelete: vi.fn(), onBulkRestore: vi.fn() });
        fireEvent.click(screen.getByTestId('kb-explorer-select-all'));
        expect(screen.queryByTestId('kb-explorer-bulk-restore')).toBeNull();
    });

    it('cancelling the delete confirm does not fire the mutation', () => {
        const onBulkDelete = vi.fn();
        renderExplorer({ path: 'policies', onBulkDelete, onBulkRestore: vi.fn() });
        fireEvent.click(screen.getByTestId('kb-explorer-doc-1'));
        fireEvent.click(screen.getByTestId('kb-explorer-bulk-delete'));
        // Confirm dialog up; cancel it.
        fireEvent.click(screen.getByTestId('kb-explorer-confirm-cancel'));
        expect(onBulkDelete).not.toHaveBeenCalled();
    });

    it('confirming the delete fires onBulkDelete with the selected ids', () => {
        const onBulkDelete = vi.fn();
        renderExplorer({ path: 'policies', onBulkDelete, onBulkRestore: vi.fn() });
        fireEvent.click(screen.getByTestId('kb-explorer-doc-1'));
        fireEvent.click(screen.getByTestId('kb-explorer-bulk-delete'));
        fireEvent.click(screen.getByTestId('kb-explorer-confirm-submit'));
        expect(onBulkDelete).toHaveBeenCalledWith([1], false);
    });
});
