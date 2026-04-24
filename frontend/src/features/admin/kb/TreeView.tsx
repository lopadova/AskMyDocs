import { useMemo, useState } from 'react';
import { Icon } from '../../../components/Icons';
import type {
    KbTreeMode,
    KbTreeNode,
    KbTreeResponse,
} from '../admin.api';

/*
 * Phase G1 — KB tree panel.
 *
 * Filter bar (mode picker / search / with_trashed) + an expandable
 * tree rendered with nested <ul> so screen readers announce the
 * hierarchy without an ARIA grid.
 *
 * No virtualization — expected canonical corpus tops out in the low
 * thousands in G1 and the node DOM cost is cheap. G3/G4 may revisit
 * once the editor renders inline previews.
 *
 * Props `q` / `onQ` / etc. are controlled by KbView so the URL /
 * query cache key can be hoisted later (G2 adds deep links to doc
 * paths). Filter changes call the setters directly; no debouncing
 * — search is client-side filtering and re-fires on every keystroke,
 * which is fine because TanStack Query memoises by query key anyway.
 */

export type TreeState = 'loading' | 'ready' | 'error' | 'empty';

export interface TreeViewProps {
    data: KbTreeResponse | undefined;
    state: TreeState;
    q: string;
    onQChange: (next: string) => void;
    mode: KbTreeMode;
    onModeChange: (next: KbTreeMode) => void;
    withTrashed: boolean;
    onWithTrashedChange: (next: boolean) => void;
    selectedPath: string | null;
    onSelect: (path: string | null, meta: KbTreeNode | null) => void;
}

export function TreeView(props: TreeViewProps) {
    const {
        data,
        state,
        q,
        onQChange,
        mode,
        onModeChange,
        withTrashed,
        onWithTrashedChange,
        selectedPath,
        onSelect,
    } = props;

    const visible = useMemo(() => {
        if (!data) {
            return [];
        }
        const term = q.trim().toLowerCase();
        if (term === '') {
            return data.tree;
        }
        return filterTree(data.tree, term);
    }, [data, q]);

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
                            {data.counts.docs} docs · {data.counts.canonical} canonical
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
                                key={node.path}
                                node={node}
                                depth={0}
                                selectedPath={selectedPath}
                                onSelect={onSelect}
                            />
                        ))}
                    </ul>
                ) : null}
            </div>
        </div>
    );
}

interface TreeNodeProps {
    node: KbTreeNode;
    depth: number;
    selectedPath: string | null;
    onSelect: (path: string | null, meta: KbTreeNode | null) => void;
}

function TreeNode({ node, depth, selectedPath, onSelect }: TreeNodeProps) {
    const [open, setOpen] = useState(depth < 1);

    if (node.type === 'folder') {
        return (
            <li role="treeitem" aria-expanded={open}>
                <button
                    type="button"
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
                    <span>{node.name}</span>
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
        <li role="treeitem" aria-selected={active}>
            <button
                type="button"
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
                <Icon.File size={13} />
                <span style={{ flex: 1 }}>{node.name}</span>
                {canonical ? (
                    <span
                        data-testid={`kb-tree-badge-canonical-${node.path}`}
                        style={{
                            fontSize: 10,
                            padding: '1px 6px',
                            borderRadius: 999,
                            background: 'var(--grad-accent-soft)',
                            color: 'var(--accent-fg)',
                            textTransform: 'uppercase',
                            letterSpacing: '0.04em',
                        }}
                    >
                        {node.meta.canonical_type ?? 'canonical'}
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
