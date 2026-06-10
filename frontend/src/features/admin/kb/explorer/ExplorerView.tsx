import { useMemo, type MouseEvent, type ReactNode } from 'react';
import { Icon } from '../../../../components/Icons';
import type { KbTreeDocNode, KbTreeNode } from '../../admin.api';
import type { TreeState } from '../TreeView';
import { FolderTile } from './FolderTile';
import { DocCard } from './DocCard';
import type { ExplorerLayout, ExplorerTileSize } from './explorer-prefs';
import {
    breadcrumbSegments,
    childrenAtPath,
    deepFilterDocs,
    folderEntries,
} from './explorer-utils';

/*
 * Filesystem-style explorer over the KB tree — the media-gallery
 * desktop pattern adapted to AskMyDocs: breadcrumb + folder tiles +
 * document cards at the current virtual path, with a grid/list toggle
 * and adjustable tile size.
 *
 * Data comes from the SAME `useKbTree` cache the TreeView already
 * loads (zero extra fetches). "Folders" are virtual — derived from the
 * documents' source_path segments by KbTreeService.
 *
 * Selection + bulk toolbar are wired by the parent (KbView) and are
 * optional here so the view renders before they exist; when
 * `onToggleDoc` is absent the cards hide their checkboxes (no
 * multi-select). When `q` is non-empty the grid flattens to a global
 * search result across every folder (breadcrumb hidden).
 */

export interface ExplorerViewProps {
    tree: KbTreeNode[] | undefined;
    state: TreeState;
    path: string;
    onPathChange: (path: string) => void;
    /** Search term (reused from the shared filter bar). */
    q: string;
    layout: ExplorerLayout;
    size: ExplorerTileSize;
    onLayoutChange: (next: ExplorerLayout) => void;
    onSizeChange: (next: ExplorerTileSize) => void;
    focusedDocId: number | null;
    onOpenDoc: (doc: KbTreeDocNode) => void;
    // ── Selection (wired in WU8; optional so WU6 renders without it) ──
    selectedIds?: Set<number>;
    onActivateDoc?: (doc: KbTreeDocNode, e: MouseEvent) => void;
    onToggleDoc?: (doc: KbTreeDocNode) => void;
    onSelectAll?: (checked: boolean) => void;
    bulkToolbar?: ReactNode;
}

export function ExplorerView(props: ExplorerViewProps) {
    const {
        tree,
        state,
        path,
        onPathChange,
        q,
        layout,
        size,
        onLayoutChange,
        onSizeChange,
        focusedDocId,
        onOpenDoc,
        selectedIds,
        onActivateDoc,
        onToggleDoc,
        onSelectAll,
        bulkToolbar,
    } = props;

    const searching = q.trim() !== '';
    const selectable = onToggleDoc !== undefined;
    const selected = selectedIds ?? EMPTY;

    // Current-folder contents, OR flattened search results across the
    // whole tree when a search term is present.
    const { folders, docs } = useMemo(() => {
        if (!tree) {
            return { folders: [], docs: [] };
        }
        if (searching) {
            return { folders: [], docs: deepFilterDocs(tree, q) };
        }
        const level = childrenAtPath(tree, path);
        if (level === null) {
            return { folders: [], docs: [] };
        }
        return folderEntries(level);
    }, [tree, path, q, searching]);

    const isEmpty = folders.length === 0 && docs.length === 0;
    const crumbs = breadcrumbSegments(path);
    const docIds = docs.map((d) => d.meta.id);
    const allSelected = docIds.length > 0 && docIds.every((id) => selected.has(id));

    const activate = onActivateDoc ?? ((doc: KbTreeDocNode) => onOpenDoc(doc));
    const toggle = onToggleDoc ?? (() => {});

    const renderState: TreeState = state === 'ready' && isEmpty ? 'empty' : state;

    return (
        <div
            data-testid="kb-explorer"
            data-state={renderState}
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
                minHeight: 0,
                height: '100%',
                border: '1px solid var(--hairline)',
                borderRadius: 10,
                background: 'var(--bg-1)',
                overflow: 'hidden',
            }}
        >
            <Toolbar
                searching={searching}
                crumbs={crumbs}
                onPathChange={onPathChange}
                layout={layout}
                size={size}
                onLayoutChange={onLayoutChange}
                onSizeChange={onSizeChange}
                selectable={selectable}
                allSelected={allSelected}
                someSelected={!allSelected && docIds.some((id) => selected.has(id))}
                onSelectAll={onSelectAll}
            />

            {bulkToolbar}

            <div style={{ flex: 1, minHeight: 0, overflow: 'auto', padding: 12 }}>
                {state === 'loading' ? (
                    <div data-testid="kb-explorer-skeleton" style={mutedStyle}>
                        Loading…
                    </div>
                ) : null}
                {state === 'error' ? (
                    <div data-testid="kb-explorer-error" style={{ ...mutedStyle, color: 'var(--danger-fg)' }}>
                        Could not load the KB tree. Try refreshing.
                    </div>
                ) : null}
                {state === 'ready' && isEmpty ? (
                    <div data-testid="kb-explorer-empty" style={mutedStyle}>
                        {searching ? 'No documents match your search.' : 'This folder is empty.'}
                    </div>
                ) : null}
                {state === 'ready' && !isEmpty ? (
                    layout === 'list' ? (
                        <div
                            role="listbox"
                            aria-label="Documents"
                            data-testid="kb-explorer-grid"
                            data-layout="list"
                            style={{ display: 'flex', flexDirection: 'column' }}
                        >
                            {folders.map((folder) => (
                                <FolderTile
                                    key={folder.path}
                                    folder={folder}
                                    layout="list"
                                    size={size}
                                    onOpen={onPathChange}
                                />
                            ))}
                            {docs.map((doc) => (
                                <DocCard
                                    key={doc.meta.id}
                                    doc={doc}
                                    layout="list"
                                    size={size}
                                    selectable={selectable}
                                    selected={selected.has(doc.meta.id)}
                                    focused={doc.meta.id === focusedDocId}
                                    onActivate={activate}
                                    onToggle={toggle}
                                    onOpen={onOpenDoc}
                                />
                            ))}
                        </div>
                    ) : (
                        <div
                            role="listbox"
                            aria-label="Documents"
                            data-testid="kb-explorer-grid"
                            data-layout="grid"
                            style={{
                                display: 'flex',
                                flexWrap: 'wrap',
                                gap: 12,
                                alignContent: 'flex-start',
                            }}
                        >
                            {folders.map((folder) => (
                                <FolderTile
                                    key={folder.path}
                                    folder={folder}
                                    layout="grid"
                                    size={size}
                                    onOpen={onPathChange}
                                />
                            ))}
                            {docs.map((doc) => (
                                <DocCard
                                    key={doc.meta.id}
                                    doc={doc}
                                    layout="grid"
                                    size={size}
                                    selectable={selectable}
                                    selected={selected.has(doc.meta.id)}
                                    focused={doc.meta.id === focusedDocId}
                                    onActivate={activate}
                                    onToggle={toggle}
                                    onOpen={onOpenDoc}
                                />
                            ))}
                        </div>
                    )
                ) : null}
            </div>
        </div>
    );
}

const EMPTY: Set<number> = new Set();

const mutedStyle = {
    padding: 16,
    color: 'var(--fg-3)',
    fontSize: 12.5,
} as const;

function Toolbar({
    searching,
    crumbs,
    onPathChange,
    layout,
    size,
    onLayoutChange,
    onSizeChange,
    selectable,
    allSelected,
    someSelected,
    onSelectAll,
}: {
    searching: boolean;
    crumbs: { name: string; path: string }[];
    onPathChange: (path: string) => void;
    layout: ExplorerLayout;
    size: ExplorerTileSize;
    onLayoutChange: (next: ExplorerLayout) => void;
    onSizeChange: (next: ExplorerTileSize) => void;
    selectable: boolean;
    allSelected: boolean;
    someSelected: boolean;
    onSelectAll?: (checked: boolean) => void;
}) {
    return (
        <div
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                padding: '8px 12px',
                borderBottom: '1px solid var(--hairline)',
                background: 'var(--bg-1)',
            }}
        >
            {selectable ? (
                <input
                    type="checkbox"
                    data-testid="kb-explorer-select-all"
                    aria-label="Select all documents in this folder"
                    checked={allSelected}
                    ref={(el) => {
                        if (el) el.indeterminate = someSelected;
                    }}
                    onChange={(e) => onSelectAll?.(e.target.checked)}
                    style={{ cursor: 'pointer' }}
                />
            ) : null}

            <nav
                aria-label="Breadcrumb"
                data-testid="kb-explorer-breadcrumb"
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 4,
                    flex: 1,
                    minWidth: 0,
                    overflow: 'hidden',
                    fontSize: 12.5,
                }}
            >
                {searching ? (
                    <span style={{ color: 'var(--fg-3)' }}>Search results</span>
                ) : (
                    <>
                        <button
                            type="button"
                            data-testid="kb-explorer-crumb-root"
                            className="focus-ring"
                            onClick={() => onPathChange('')}
                            style={crumbBtnStyle}
                        >
                            <Icon.Folder size={13} /> Root
                        </button>
                        {crumbs.map((c) => (
                            <span key={c.path} style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                <Icon.Chevron size={11} />
                                <button
                                    type="button"
                                    data-testid={`kb-explorer-crumb-${c.path}`}
                                    className="focus-ring"
                                    onClick={() => onPathChange(c.path)}
                                    style={crumbBtnStyle}
                                >
                                    {c.name}
                                </button>
                            </span>
                        ))}
                    </>
                )}
            </nav>

            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <button
                    type="button"
                    data-testid="kb-explorer-layout-grid"
                    aria-label="Grid layout"
                    aria-pressed={layout === 'grid'}
                    className="focus-ring"
                    onClick={() => onLayoutChange('grid')}
                    style={iconToggleStyle(layout === 'grid')}
                >
                    <Icon.Grid size={14} />
                </button>
                <button
                    type="button"
                    data-testid="kb-explorer-layout-list"
                    aria-label="List layout"
                    aria-pressed={layout === 'list'}
                    className="focus-ring"
                    onClick={() => onLayoutChange('list')}
                    style={iconToggleStyle(layout === 'list')}
                >
                    <Icon.List size={14} />
                </button>
                <select
                    data-testid="kb-explorer-size"
                    aria-label="Tile size"
                    value={size}
                    onChange={(e) => onSizeChange(e.target.value as ExplorerTileSize)}
                    style={{
                        padding: '5px 7px',
                        fontSize: 12,
                        background: 'var(--bg-0)',
                        border: '1px solid var(--hairline)',
                        borderRadius: 8,
                        color: 'var(--fg-1)',
                    }}
                >
                    <option value="sm">Small</option>
                    <option value="md">Medium</option>
                    <option value="lg">Large</option>
                </select>
            </div>
        </div>
    );
}

const crumbBtnStyle = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 4,
    padding: '3px 6px',
    border: '1px solid transparent',
    background: 'transparent',
    color: 'var(--fg-2)',
    borderRadius: 6,
    cursor: 'pointer',
    fontSize: 12.5,
} as const;

function iconToggleStyle(active: boolean) {
    return {
        display: 'inline-flex',
        padding: 5,
        border: '1px solid ' + (active ? 'var(--accent)' : 'var(--hairline)'),
        background: active ? 'var(--grad-accent-soft)' : 'var(--bg-0)',
        color: active ? 'var(--accent-fg)' : 'var(--fg-2)',
        borderRadius: 8,
        cursor: 'pointer',
    } as const;
}
