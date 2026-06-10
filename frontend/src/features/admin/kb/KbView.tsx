import { useEffect, useMemo, useState } from 'react';
import { Icon } from '../../../components/Icons';
import { AdminShell } from '../shell/AdminShell';
import type { KbTreeDocNode, KbTreeMode, KbTreeNode } from '../admin.api';
import { TreeView, type TreeState } from './TreeView';
import { useKbProjects, useKbTree } from './kb-tree.api';
import { useBulkDeleteKbDocuments, useBulkRestoreKbDocuments } from './kb-document.api';
import { DocumentDetail, type KbDetailTab } from './DocumentDetail';
import { ExplorerView } from './explorer/ExplorerView';
import { ExplorerPreviewPane } from './explorer/ExplorerPreviewPane';
import {
    loadExplorerPrefs,
    saveExplorerPrefs,
    type ExplorerLayout,
    type ExplorerPrefs,
    type ExplorerTileSize,
} from './explorer/explorer-prefs';
import { findDocById, resolveExistingPath } from './explorer/explorer-utils';
import { ToastHost, useToast } from '../shared/Toast';

type KbViewMode = 'tree' | 'explorer';

/*
 * Phase G1 + G2 + G3 — KB Explorer shell.
 *
 * G1 shipped the tree + placeholder. G2 replaced the placeholder with
 * the full DocumentDetail pane (Preview / Meta / History) plus header
 * actions (Download / Print / Restore / Delete / Force delete). G3
 * added the Source tab (CodeMirror editor + PATCH /raw save pipeline),
 * so `VALID_TABS` now covers `preview / source / meta / history`.
 *
 * Selection + tab state persist in the URL via `doc` and `tab` search
 * params so operators can deep-link to a specific view. We parse the
 * current URL once on mount and write back through history.replaceState
 * so TanStack Router stays in charge of navigation elsewhere.
 *
 * Phase G4 adds the Graph tab + Export-PDF header action. The tab is
 * deep-linkable via `?tab=graph`; the export button sits next to
 * Print and fires useExportPdf, surfacing 501 ("engine disabled") +
 * 500 errors as toasts.
 */

const VALID_TABS: KbDetailTab[] = ['preview', 'source', 'meta', 'history', 'graph'];

function parseInitialUrl(): {
    docId: number | null;
    tab: KbDetailTab;
    view: KbViewMode;
    path: string;
} {
    if (typeof window === 'undefined') {
        return { docId: null, tab: 'preview', view: 'tree', path: '' };
    }
    const params = new URLSearchParams(window.location.search);
    const rawDoc = params.get('doc');
    const rawTab = params.get('tab');
    const docId = rawDoc !== null && /^\d+$/.test(rawDoc) ? Number(rawDoc) : null;
    const tab = (VALID_TABS as string[]).includes(rawTab ?? '')
        ? (rawTab as KbDetailTab)
        : 'preview';
    const view: KbViewMode = params.get('view') === 'explorer' ? 'explorer' : 'tree';
    const path = params.get('path') ?? '';
    return { docId, tab, view, path };
}

function syncUrl(docId: number | null, tab: KbDetailTab, view: KbViewMode, path: string) {
    if (typeof window === 'undefined') return;
    const params = new URLSearchParams(window.location.search);
    if (docId === null) {
        params.delete('doc');
    } else {
        params.set('doc', String(docId));
    }
    params.set('tab', tab);
    if (view === 'explorer') {
        params.set('view', 'explorer');
    } else {
        params.delete('view');
    }
    if (view === 'explorer' && path !== '') {
        params.set('path', path);
    } else {
        params.delete('path');
    }
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

    // Explorer mode state. `view` + `path` deep-link via the URL;
    // layout/size are per-operator prefs in localStorage. `focusedDocId`
    // is the doc shown in the explorer preview pane (distinct from the
    // tree's selectedDocId which drives the full detail view).
    const [view, setView] = useState<KbViewMode>(initial.view);
    const [path, setPath] = useState<string>(initial.path);
    const [prefs, setPrefs] = useState<ExplorerPrefs>(() => loadExplorerPrefs());
    const [focusedDocId, setFocusedDocId] = useState<number | null>(null);

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

    const toast = useToast();
    const bulkDeleteMut = useBulkDeleteKbDocuments();
    const bulkRestoreMut = useBulkRestoreKbDocuments();
    const bulkBusy = bulkDeleteMut.isPending || bulkRestoreMut.isPending;

    // Keep the URL in sync with (selectedDocId, activeTab, view, path).
    // Runs on every state change — history.replaceState is cheap and
    // idempotent, so this also covers the restore→trash toggle round-trip.
    useEffect(() => {
        syncUrl(selectedDocId, activeTab, view, path);
    }, [selectedDocId, activeTab, view, path]);

    // R17 — when the tree refetches (e.g. a folder is emptied by a bulk
    // delete) or a deep-link points at a path that never existed, snap
    // the explorer to the nearest live ancestor instead of rendering a
    // dead folder.
    useEffect(() => {
        if (view !== 'explorer' || !treeQuery.data) {
            return;
        }
        const resolved = resolveExistingPath(treeQuery.data.tree, path);
        if (resolved !== path) {
            setPath(resolved);
        }
    }, [view, treeQuery.data, path]);

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
            if (view === 'explorer') {
                // In explorer mode the tree drives the preview pane, not
                // the full detail view.
                setFocusedDocId(node.meta.id);
            } else {
                setSelectedDocId(node.meta.id);
                // Default to preview when jumping to a new doc.
                setActiveTab('preview');
            }
        } else if (view !== 'explorer') {
            setSelectedDocId(null);
        }
    }

    function handleOpenDoc(doc: KbTreeDocNode) {
        setFocusedDocId(doc.meta.id);
    }

    // Resolve the focused doc node from the tree for the preview pane.
    // Returns null if the doc vanished from the tree (e.g. deleted under
    // a filter that now hides it) — the pane just closes.
    const focusedNode = useMemo(() => {
        if (focusedDocId === null || !treeQuery.data) {
            return null;
        }
        return findDocById(treeQuery.data.tree, focusedDocId);
    }, [focusedDocId, treeQuery.data]);

    function handleOpenDetail(docId: number) {
        // Hand off from the lightweight preview to the full tree+detail
        // view with this doc selected.
        setView('tree');
        setSelectedDocId(docId);
        setActiveTab('preview');
    }

    function updatePrefs(next: Partial<ExplorerPrefs>) {
        setPrefs((prev) => {
            const merged = { ...prev, ...next };
            saveExplorerPrefs(merged);
            return merged;
        });
    }

    function handleDeleted() {
        // Force the tree to refetch so the deleted row disappears (or
        // flips to trashed badge when with_trashed is on).
        treeQuery.refetch();
    }

    function handleBulkDelete(ids: number[], force: boolean) {
        if (ids.length === 0) {
            return;
        }
        bulkDeleteMut.mutate(
            { ids, force },
            {
                onSuccess: (res) => {
                    const s = res.summary;
                    const verb = force ? 'hard-deleted' : 'deleted';
                    if (s.failed > 0) {
                        toast.error(
                            `${s.deleted} ${verb}, ${s.failed} failed.`,
                            'kb-explorer-bulk-toast',
                        );
                    } else {
                        toast.success(
                            `${s.deleted} document${s.deleted === 1 ? '' : 's'} ${verb}.`,
                            'kb-explorer-bulk-toast',
                        );
                    }
                    treeQuery.refetch();
                },
                onError: () => toast.error('Bulk delete failed.', 'kb-explorer-bulk-toast'),
            },
        );
    }

    function handleBulkRestore(ids: number[]) {
        if (ids.length === 0) {
            return;
        }
        bulkRestoreMut.mutate(
            { ids },
            {
                onSuccess: (res) => {
                    const s = res.summary;
                    toast.success(
                        `${s.restored} document${s.restored === 1 ? '' : 's'} restored.`,
                        'kb-explorer-bulk-toast',
                    );
                    treeQuery.refetch();
                },
                onError: () => toast.error('Bulk restore failed.', 'kb-explorer-bulk-toast'),
            },
        );
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
                            Browse the canonical + raw document tree, or switch to the
                            filesystem explorer to grid-browse, preview, and bulk-manage docs.
                        </p>
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 12,
                        }}
                    >
                        <div
                            data-testid="kb-view-toggle"
                            role="group"
                            aria-label="View mode"
                            style={{ display: 'flex', gap: 4 }}
                        >
                            <button
                                type="button"
                                data-testid="kb-view-toggle-tree"
                                aria-label="Tree view"
                                aria-pressed={view === 'tree'}
                                className="focus-ring"
                                onClick={() => setView('tree')}
                                style={viewToggleStyle(view === 'tree')}
                            >
                                <Icon.List size={14} /> Tree
                            </button>
                            <button
                                type="button"
                                data-testid="kb-view-toggle-explorer"
                                aria-label="Explorer view"
                                aria-pressed={view === 'explorer'}
                                className="focus-ring"
                                onClick={() => setView('explorer')}
                                style={viewToggleStyle(view === 'explorer')}
                            >
                                <Icon.Grid size={14} /> Explorer
                            </button>
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
                </div>

                {view === 'explorer' ? (
                    <div
                        style={{
                            display: 'grid',
                            gridTemplateColumns: focusedNode
                                ? 'minmax(220px, 280px) 1fr minmax(280px, 380px)'
                                : 'minmax(240px, 320px) 1fr',
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
                            onFolderSelect={setPath}
                        />
                        <ExplorerView
                            tree={treeQuery.data?.tree}
                            state={state}
                            path={path}
                            onPathChange={setPath}
                            q={q}
                            layout={prefs.layout}
                            size={prefs.size}
                            onLayoutChange={(next: ExplorerLayout) => updatePrefs({ layout: next })}
                            onSizeChange={(next: ExplorerTileSize) => updatePrefs({ size: next })}
                            focusedDocId={focusedDocId}
                            onOpenDoc={handleOpenDoc}
                            onBulkDelete={handleBulkDelete}
                            onBulkRestore={handleBulkRestore}
                            bulkBusy={bulkBusy}
                        />
                        {focusedNode ? (
                            <ExplorerPreviewPane
                                node={focusedNode}
                                onClose={() => setFocusedDocId(null)}
                                onOpenDetail={handleOpenDetail}
                            />
                        ) : null}
                    </div>
                ) : (
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
                )}
            </div>
            <ToastHost />
        </AdminShell>
    );
}

function viewToggleStyle(active: boolean) {
    return {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        padding: '6px 10px',
        fontSize: 12,
        border: '1px solid ' + (active ? 'var(--accent)' : 'var(--hairline)'),
        background: active ? 'var(--grad-accent-soft)' : 'var(--bg-0)',
        color: active ? 'var(--accent-fg)' : 'var(--fg-2)',
        borderRadius: 8,
        cursor: 'pointer',
    } as const;
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
