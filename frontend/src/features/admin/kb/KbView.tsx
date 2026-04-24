import { useEffect, useMemo, useState } from 'react';
import { Icon } from '../../../components/Icons';
import { AdminShell } from '../shell/AdminShell';
import type { KbTreeMode, KbTreeNode } from '../admin.api';
import { TreeView, type TreeState } from './TreeView';
import { useKbProjects, useKbTree } from './kb-tree.api';
import { DocumentDetail, type KbDetailTab } from './DocumentDetail';

/*
 * Phase G1 + G2 — KB Explorer shell.
 *
 * G1 shipped the tree + placeholder. G2 replaces the placeholder with
 * the full DocumentDetail pane: Preview / Meta / History tabs, header
 * actions (Download / Print / Restore / Delete / Force delete).
 *
 * Selection + tab state persist in the URL via `doc` and `tab` search
 * params so operators can deep-link to a specific view. We parse the
 * current URL once on mount and write back through history.replaceState
 * so TanStack Router stays in charge of navigation elsewhere.
 *
 * Editor (G3) + graph/PDF (G4) slot next to the existing tabs when
 * those microphases land.
 */

const VALID_TABS: KbDetailTab[] = ['preview', 'meta', 'history'];

function parseInitialUrl(): { docId: number | null; tab: KbDetailTab } {
    if (typeof window === 'undefined') {
        return { docId: null, tab: 'preview' };
    }
    const params = new URLSearchParams(window.location.search);
    const rawDoc = params.get('doc');
    const rawTab = params.get('tab');
    const docId = rawDoc !== null && /^\d+$/.test(rawDoc) ? Number(rawDoc) : null;
    const tab = (VALID_TABS as string[]).includes(rawTab ?? '')
        ? (rawTab as KbDetailTab)
        : 'preview';
    return { docId, tab };
}

function syncUrl(docId: number | null, tab: KbDetailTab) {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    if (docId === null) {
        params.delete('doc');
    } else {
        params.set('doc', String(docId));
    }
    params.set('tab', tab);
    const next = `${window.location.pathname}?${params.toString()}`;
    window.history.replaceState(null, '', next);
}

export function KbView() {
    const initial = useMemo(parseInitialUrl, []);

    const [project, setProject] = useState<string>('');
    const [mode, setMode] = useState<KbTreeMode>('all');
    const [q, setQ] = useState('');
    const [withTrashed, setWithTrashed] = useState(false);
    const [selectedPath, setSelectedPath] = useState<string | null>(null);
    const [selectedNode, setSelectedNode] = useState<KbTreeNode | null>(null);
    const [selectedDocId, setSelectedDocId] = useState<number | null>(initial.docId);
    const [activeTab, setActiveTab] = useState<KbDetailTab>(initial.tab);

    const treeQuery = useKbTree({
        project: project || null,
        mode,
        with_trashed: withTrashed || undefined,
    });
    // Copilot #5 fix: the project list comes from the DB (distinct
    // `project_key` across all tenants, soft-deleted rows included
    // so a restore path sees its project). No more hard-coded
    // `hr-portal` / `engineering` pair that would hide every other
    // tenant.
    const projectsQuery = useKbProjects();
    const projectOptions = projectsQuery.data?.projects ?? [];

    // Keep the URL in sync with (selectedDocId, activeTab). Runs on every
    // state change — history.replaceState is cheap and idempotent, so
    // this also covers the restore→trash toggle round-trip.
    useEffect(() => {
        syncUrl(selectedDocId, activeTab);
    }, [selectedDocId, activeTab]);

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
        if (node !== null && node.type === 'doc') {
            setSelectedDocId(node.meta.id);
            // Default to preview when jumping to a new doc.
            setActiveTab('preview');
        } else {
            setSelectedDocId(null);
        }
    }

    function handleDeleted() {
        // Force the tree to refetch so the deleted row disappears (or
        // flips to trashed badge when with_trashed is on).
        treeQuery.refetch();
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
                            {projectOptions.map((key) => (
                                <option key={key} value={key}>
                                    {key}
                                </option>
                            ))}
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
                        data-testid="kb-detail-pane"
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 12,
                            minHeight: 0,
                            overflow: 'hidden',
                        }}
                    >
                        {selectedDocId === null ? (
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
                        ) : (
                            <DocumentDetail
                                documentId={selectedDocId}
                                activeTab={activeTab}
                                onTabChange={setActiveTab}
                                onDeleted={handleDeleted}
                            />
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
                Source editor lands in Phase G3; graph + PDF renderers in G4.
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
