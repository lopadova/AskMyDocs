import { useMemo, useState } from 'react';
import { Button } from '../../../components/Button';
import { FileTypeIcon, fileKind } from './FileTypeIcon';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Icon } from '../../../components/Icons';
import type {
    KbTreeMode,
    KbTreeNode,
    KbTreeResponse,
} from '../admin.api';

/* The admin explorer requests bounded server pages. Reader callers can still
 * supply a complete tree and use the local search fallback. */

export type TreeState = 'loading' | 'ready' | 'error' | 'empty';

export interface TreeViewProps {
    serverSearch?: boolean;
    pageNumber?: number;
    onPreviousPage?: () => void;
    onNextPage?: () => void;
    data: KbTreeResponse | undefined;
    state: TreeState;
    q: string;
    onQChange: (next: string) => void;
    mode: KbTreeMode;
    onModeChange: (next: KbTreeMode) => void;
    withTrashed: boolean;
    onWithTrashedChange: (next: boolean) => void;
    /**
     * Offer the "Include deleted" checkbox. Default true keeps the admin
     * explorer unchanged; the reader-side Browse KB page passes false,
     * because its endpoint never returns soft-deleted documents (R2) and
     * a control that cannot change the result is worse than no control.
     */
    allowTrashedToggle?: boolean;
    selectedPath: string | null;
    onSelect: (path: string | null, meta: KbTreeNode | null) => void;
}

export function TreeView(props: TreeViewProps) {
    const {
        data,
        serverSearch = false,
        pageNumber = 1,
        onPreviousPage,
        onNextPage,
        state,
        q,
        onQChange,
        mode,
        onModeChange,
        withTrashed,
        onWithTrashedChange,
        allowTrashedToggle = true,
        selectedPath,
        onSelect,
    } = props;

    const visible = useMemo(() => {
        if (!data) {
            return [];
        }
        const term = q.trim().toLowerCase();
        if (serverSearch || term === '') {
            return data.tree;
        }
        return filterTree(data.tree, term);
    }, [data, q, serverSearch]);

    return (
        <div
            data-testid="kb-tree"
            data-state={state}
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
                minHeight: 0,
                height: '100%',
            }}
        >
            <div
                data-testid="kb-tree-filter-bar"
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 8,
                    padding: 10,
                    border: '1px solid var(--hairline)',
                    borderRadius: 10,
                    background: 'var(--bg-1)',
                }}
            >
                <div style={{ position: 'relative' }}>
                    <Icon.Search
                        size={14}
                        style={{
                            position: 'absolute',
                            left: 10,
                            top: 9,
                            color: 'var(--fg-3)',
                        }}
                    />
                    <input
                        data-testid="kb-tree-q"
                        type="search"
                        maxLength={200}
                        aria-label="Search path or file name"
                        value={q}
                        onChange={(e) => onQChange(e.target.value)}
                        placeholder="Search path or file name…"
                        style={{
                            width: '100%',
                            padding: '7px 10px 7px 30px',
                            fontSize: 13,
                            background: 'var(--bg-0)',
                            border: '1px solid var(--hairline)',
                            borderRadius: 8,
                            color: 'var(--fg-0)',
                        }}
                    />
                </div>
                <div
                    style={{
                        display: 'flex',
                        gap: 8,
                        alignItems: 'center',
                        flexWrap: 'wrap',
                    }}
                >
                    <select
                        data-testid="kb-tree-mode"
                        aria-label="Document mode filter"
                        value={mode}
                        onChange={(e) => onModeChange(e.target.value as KbTreeMode)}
                        style={{
                            padding: '6px 8px',
                            fontSize: 12.5,
                            background: 'var(--bg-0)',
                            border: '1px solid var(--hairline)',
                            borderRadius: 8,
                            color: 'var(--fg-1)',
                        }}
                    >
                        <option value="all">All documents</option>
                        <option value="canonical">Canonical only</option>
                        <option value="raw">Raw only</option>
                    </select>
                    {allowTrashedToggle ? (
                        <label
                            style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6,
                                fontSize: 12,
                                color: 'var(--fg-2)',
                            }}
                        >
                            <input
                                type="checkbox"
                                data-testid="kb-tree-with-trashed"
                                checked={withTrashed}
                                onChange={(e) => onWithTrashedChange(e.target.checked)}
                            />
                            Include deleted
                        </label>
                    ) : null}
                    {data ? (
                        <span
                            data-testid="kb-tree-counts"
                            style={{
                                marginLeft: 'auto',
                                fontSize: 11,
                                color: 'var(--fg-3)',
                                fontFamily: 'var(--font-mono)',
                            }}
                        >
                            {data.counts.docs.toLocaleString()} docs · {data.counts.canonical} canonical
                            {data.counts.trashed > 0 ? ` · ${data.counts.trashed} trashed` : ''}
                        </span>
                    ) : null}
                </div>
            </div>

            <div
                style={{
                    flex: 1,
                    minHeight: 0,
                    overflow: 'auto',
                    border: '1px solid var(--hairline)',
                    borderRadius: 10,
                    background: 'var(--bg-1)',
                    padding: 8,
                }}
            >
                {state === 'loading' ? (
                    <div
                        data-testid="kb-tree-skeleton"
                        style={{ padding: 12, color: 'var(--fg-3)', fontSize: 12 }}
                    >
                        Loading tree…
                    </div>
                ) : null}
                {state === 'error' ? (
                    <div
                        data-testid="kb-tree-error"
                        style={{ padding: 12, color: 'var(--danger-fg)', fontSize: 12 }}
                    >
                        Could not load the KB tree. Try refreshing.
                    </div>
                ) : null}
                {state === 'empty' ? (
                    <div
                        data-testid="kb-tree-empty"
                        style={{ padding: 12, color: 'var(--fg-3)', fontSize: 12 }}
                    >
                        No documents match the current filter.
                    </div>
                ) : null}
                {state === 'ready' ? (
                    <ul
                        role="tree"
                        style={{
                            listStyle: 'none',
                            padding: 0,
                            margin: 0,
                        }}
                    >
                        {visible.map((node) => (
                            <TreeNode
                                key={`${pageNumber}:${q}:${node.path}`}
                                node={node}
                                depth={0}
                                expandAll={q.trim() !== ''}
                                selectedPath={selectedPath}
                                onSelect={onSelect}
                            />
                        ))}
                    </ul>
                ) : null}
            </div>
            {serverSearch && (
                <div className="kb-tree-pagination" aria-label="Document pages">
                    <span>{data?.pagination ? `${data.pagination.loaded} shown · Page ${pageNumber}` : `Page ${pageNumber}`}</span>
                    <Button variant="quiet" size="sm" iconOnly aria-label="Previous documents"
                        disabled={pageNumber <= 1 || state === 'loading'} onClick={onPreviousPage}><ChevronLeft size={16} /></Button>
                    <Button variant="quiet" size="sm" iconOnly aria-label="Next documents"
                        disabled={!data?.pagination?.has_more || state === 'loading'} onClick={onNextPage}><ChevronRight size={16} /></Button>
                </div>
            )}
        </div>
    );
}

interface TreeNodeProps {
    node: KbTreeNode;
    depth: number;
    expandAll?: boolean;
    selectedPath: string | null;
    onSelect: (path: string | null, meta: KbTreeNode | null) => void;
}

function TreeNode({ node, depth, selectedPath, onSelect, expandAll = false }: TreeNodeProps) {
    const [open, setOpen] = useState(expandAll || depth < 1);

    if (node.type === 'folder') {
        // Copilot #4 a11y fix: treeitem role + aria-expanded belong on
        // the focusable button, not the structural <li>. Screen readers
        // announce "expanded/collapsed" on the element the user tabs
        // to; leaving them on <li> meant the state was mute.
        return (
            <li role="none">
                <button
                    type="button"
                    role="treeitem"
                    aria-expanded={open}
                    className="focus-ring"
                    data-testid={`kb-tree-node-${node.path}`}
                    data-type="folder"
                    onClick={() => setOpen(!open)}
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 6,
                        width: '100%',
                        textAlign: 'left',
                        padding: '4px 6px',
                        paddingLeft: 6 + depth * 14,
                        border: '1px solid transparent',
                        background: 'transparent',
                        color: 'var(--fg-1)',
                        fontSize: 13,
                        borderRadius: 6,
                        cursor: 'pointer',
                    }}
                >
                    {open ? (
                        <Icon.ChevronDown size={12} />
                    ) : (
                        <Icon.Chevron size={12} />
                    )}
                    <Icon.Folder size={14} />
                    <span className="kb-tree-name" title={node.path}>{node.name}</span>
                </button>
                {open ? (
                    <ul
                        role="group"
                        style={{
                            listStyle: 'none',
                            padding: 0,
                            margin: 0,
                        }}
                    >
                        {node.children.map((child) => (
                            <TreeNode
                                key={child.path}
                                node={child}
                                depth={depth + 1}
                                expandAll={expandAll}
                                selectedPath={selectedPath}
                                onSelect={onSelect}
                            />
                        ))}
                    </ul>
                ) : null}
            </li>
        );
    }

    const active = selectedPath === node.path;
    const trashed = node.meta.deleted_at !== null;
    const canonical = node.meta.is_canonical;

    return (
        <li role="none">
            <button
                type="button"
                role="treeitem"
                title={`${node.path} · ${fileKind(node)}${node.meta.canonical_type ? ` · ${node.meta.canonical_type}` : ''}`}
                aria-selected={active}
                className="focus-ring"
                data-testid={`kb-tree-node-${node.path}`}
                data-type="doc"
                data-canonical={canonical ? 'true' : 'false'}
                data-trashed={trashed ? 'true' : 'false'}
                data-active={active ? 'true' : 'false'}
                onClick={() => onSelect(node.path, node)}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                    width: '100%',
                    textAlign: 'left',
                    padding: '4px 6px',
                    paddingLeft: 6 + depth * 14 + 12,
                    border: '1px solid ' + (active ? 'var(--accent)' : 'transparent'),
                    background: active ? 'var(--grad-accent-soft)' : 'transparent',
                    color: trashed ? 'var(--fg-3)' : 'var(--fg-1)',
                    fontSize: 12.5,
                    borderRadius: 6,
                    cursor: 'pointer',
                    textDecoration: trashed ? 'line-through' : 'none',
                }}
            >
                <FileTypeIcon node={node} />
                <span className="kb-tree-name">{node.name}</span>
                {canonical ? (
                    <span
                        data-testid={`kb-tree-badge-canonical-${node.path}`}
                        className="kb-tree-canonical-marker"
                        aria-label={node.meta.canonical_type ?? 'Canonical document'}
                    >
                        <span aria-hidden />
                    </span>
                ) : null}
            </button>
        </li>
    );
}

function filterTree(nodes: KbTreeNode[], term: string): KbTreeNode[] {
    const out: KbTreeNode[] = [];
    for (const node of nodes) {
        if (node.type === 'doc') {
            if (node.path.toLowerCase().includes(term) || node.name.toLowerCase().includes(term)) {
                out.push(node);
            }
            continue;
        }
        const children = filterTree(node.children, term);
        if (children.length > 0 || node.name.toLowerCase().includes(term)) {
            out.push({ ...node, children });
        }
    }
    return out;
}
