import { useState } from 'react';
import { Icon } from '../../../components/Icons';
import { AdminShell } from '../shell/AdminShell';
import type { KbTreeMode, KbTreeNode } from '../admin.api';
import { TreeView, type TreeState } from './TreeView';
import { useKbTree } from './kb-tree.api';

/*
 * Phase G1 — KB Explorer shell.
 *
 * Intentionally narrow: a left panel holding the folder/doc tree and
 * a right panel carrying a placeholder prompt. G2 fills the right
 * panel with document detail (Preview / Source / Graph / Meta / History
 * tabs), G3 adds the source editor, G4 adds graph + PDF viewers.
 *
 * The split-panel layout is load-bearing now so the route is usable
 * without a follow-up FE re-shuffle; right-panel placeholder stays
 * until G2 lands the detail renderer.
 */

export function KbView() {
    const [project, setProject] = useState<string>('');
    const [mode, setMode] = useState<KbTreeMode>('all');
    const [q, setQ] = useState('');
    const [withTrashed, setWithTrashed] = useState(false);
    const [selectedPath, setSelectedPath] = useState<string | null>(null);
    const [selectedNode, setSelectedNode] = useState<KbTreeNode | null>(null);

    const treeQuery = useKbTree({
        project: project || null,
        mode,
        with_trashed: withTrashed || undefined,
    });

    const state: TreeState = treeQuery.isLoading
        ? 'loading'
        : treeQuery.isError
          ? 'error'
          : (treeQuery.data?.tree.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    function handleSelect(path: string | null, node: KbTreeNode | null) {
        setSelectedPath(path);
        setSelectedNode(node);
    }

    return (
        <AdminShell section="kb">
            <div
                data-testid="kb-view"
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 14,
                    minHeight: 0,
                    height: '100%',
                }}
            >
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        gap: 10,
                    }}
                >
                    <div>
                        <h1
                            style={{
                                fontSize: 20,
                                fontWeight: 600,
                                margin: '0 0 2px',
                                letterSpacing: '-0.02em',
                                color: 'var(--fg-0)',
                            }}
                        >
                            Knowledge Base
                        </h1>
                        <p
                            style={{
                                fontSize: 12.5,
                                color: 'var(--fg-3)',
                                margin: 0,
                            }}
                        >
                            Browse the canonical + raw document tree. Select a doc to preview it.
                        </p>
                    </div>
                    <div
                        data-testid="kb-project-picker"
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 6,
                        }}
                    >
                        <label
                            style={{
                                fontSize: 11,
                                color: 'var(--fg-3)',
                                textTransform: 'uppercase',
                                letterSpacing: '0.04em',
                            }}
                        >
                            Project
                        </label>
                        <select
                            data-testid="kb-project-select"
                            value={project}
                            onChange={(e) => setProject(e.target.value)}
                            style={{
                                padding: '6px 8px',
                                fontSize: 12.5,
                                background: 'var(--bg-0)',
                                border: '1px solid var(--hairline)',
                                borderRadius: 8,
                                color: 'var(--fg-1)',
                            }}
                        >
                            <option value="">All projects</option>
                            <option value="hr-portal">hr-portal</option>
                            <option value="engineering">engineering</option>
                        </select>
                    </div>
                </div>

                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'minmax(280px, 380px) 1fr',
                        gap: 14,
                        flex: 1,
                        minHeight: 0,
                    }}
                >
                    <TreeView
                        data={treeQuery.data}
                        state={state}
                        q={q}
                        onQChange={setQ}
                        mode={mode}
                        onModeChange={setMode}
                        withTrashed={withTrashed}
                        onWithTrashedChange={setWithTrashed}
                        selectedPath={selectedPath}
                        onSelect={handleSelect}
                    />

                    <div
                        data-testid="kb-detail-placeholder"
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 12,
                            padding: 16,
                            border: '1px solid var(--hairline)',
                            borderRadius: 10,
                            background: 'var(--bg-1)',
                            minHeight: 0,
                            overflow: 'auto',
                        }}
                    >
                        {selectedNode === null || selectedNode.type !== 'doc' ? (
                            <EmptyDetail />
                        ) : (
                            <DocSummary node={selectedNode} />
                        )}
                    </div>
                </div>
            </div>
        </AdminShell>
    );
}

function EmptyDetail() {
    return (
        <div
            style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 10,
                padding: 32,
                color: 'var(--fg-3)',
                flex: 1,
                textAlign: 'center',
            }}
        >
            <Icon.File size={28} />
            <div style={{ fontSize: 13, color: 'var(--fg-2)' }}>
                Select a document to view its details
            </div>
            <div style={{ fontSize: 11, color: 'var(--fg-3)', maxWidth: 360 }}>
                Preview, source editor, graph and PDF render land in Phases G2, G3 and G4.
            </div>
        </div>
    );
}

function DocSummary({ node }: { node: KbTreeNode }) {
    if (node.type !== 'doc') {
        return null;
    }
    const meta = node.meta;
    return (
        <div
            data-testid="kb-detail-summary"
            style={{ display: 'flex', flexDirection: 'column', gap: 10 }}
        >
            <div style={{ fontSize: 11, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>
                {node.path}
            </div>
            <div style={{ fontSize: 17, fontWeight: 600, color: 'var(--fg-0)' }}>{node.name}</div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, fontSize: 11 }}>
                <Chip label={`project: ${meta.project_key}`} />
                <Chip label={meta.is_canonical ? 'canonical' : 'raw'} accent={meta.is_canonical} />
                {meta.canonical_type ? <Chip label={`type: ${meta.canonical_type}`} /> : null}
                {meta.canonical_status ? <Chip label={`status: ${meta.canonical_status}`} /> : null}
                {meta.deleted_at ? <Chip label="deleted" danger /> : null}
            </div>
            <div style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>
                Detail view (preview / source / graph / history) lands in Phase G2.
            </div>
        </div>
    );
}

function Chip({ label, accent, danger }: { label: string; accent?: boolean; danger?: boolean }) {
    return (
        <span
            style={{
                padding: '2px 8px',
                borderRadius: 999,
                border: '1px solid var(--hairline)',
                background: accent
                    ? 'var(--grad-accent-soft)'
                    : danger
                      ? 'var(--danger-soft, rgba(220, 38, 38, 0.08))'
                      : 'var(--bg-0)',
                color: danger ? 'var(--danger-fg, #b91c1c)' : 'var(--fg-2)',
                fontSize: 11,
                fontFamily: 'var(--font-mono)',
            }}
        >
            {label}
        </span>
    );
}
