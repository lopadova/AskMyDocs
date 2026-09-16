import { useMemo, useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSearch } from '@tanstack/react-router';
import { Icon } from '../../components/Icons';
import { Button } from '../../components/Button';
import { Markdown } from '../../lib/markdown';
import { useTeamStore } from '../../lib/team-store';
import { TreeView, type TreeState } from '../admin/kb/TreeView';
import { extractFrontmatterPills } from '../admin/kb/PreviewTab';
import type { KbTreeMode, KbTreeNode } from '../admin/admin.api';
import { chatApi, type CitationDocument } from '../chat/chat.api';
import { kbBrowseApi } from './kb-browse.api';

/**
 * Browse KB — read-only knowledge-base explorer for EVERY authenticated
 * role, reachable from the Sessions workspace.
 *
 * Reuses the admin explorer's `TreeView` unchanged (it is already fully
 * presentational) and the reader document-preview endpoint the chat
 * citation modal uses, so nothing about tree building or document
 * rendering is reimplemented here. What differs from the admin page is
 * only what is ABSENT: no editing, no upload, no history, no graph, no
 * PDF export, no soft-deleted documents.
 *
 * Visibility is whatever the BE grants (R33 — enforced in SQL by
 * `AccessScopeScope`, not by this page): with `kb.project_isolation`
 * disabled, which is the default, a reader sees every live document in
 * the tenant, exactly as chat citations already do.
 */
export function KnowledgeBrowseView(): ReactNode {
    const currentTeam = useTeamStore((s) => s.currentTeam);
    const teams = useTeamStore((s) => s.teams);

    // R18: the project options are the user's real memberships in the
    // active team, from /api/auth/me — never a literal list, and never
    // the admin-only project catalogue.
    const projects = useMemo(
        () =>
            (teams.find((t) => t.tenant_id === currentTeam)?.projects ?? [])
                .map((p) => p.project_key)
                .sort((a, b) => a.localeCompare(b)),
        [teams, currentTeam],
    );

    // A citation chip in the Sessions thread deep-links here with the
    // document it cited, so the reader lands on that document rather than
    // on an empty pane and a tree to hunt through.
    const search = useSearch({ strict: false }) as { doc?: number };
    const deepLinkedDocumentId =
        typeof search.doc === 'number' && Number.isFinite(search.doc) ? search.doc : null;

    const [project, setProject] = useState<string>('');
    const [mode, setMode] = useState<KbTreeMode>('all');
    const [q, setQ] = useState('');
    const [selected, setSelected] = useState<{ path: string; documentId: number | null } | null>(null);
    // Tracked separately from `selected`, because `null` cannot tell
    // "never clicked" apart from "clicked, but the node selects no
    // document". TreeView only ever fires onSelect for a DOC node today
    // (a folder click merely expands), so the second case is currently
    // unreachable — but its prop type permits a null, and conflating the
    // two would silently re-open the `?doc=` document the reader had
    // navigated away from.
    const [deepLinkConsumed, setDeepLinkConsumed] = useState(false);

    const treeQuery = useQuery({
        queryKey: ['kb-browse-tree', currentTeam, project === '' ? 'all-projects' : project, mode],
        queryFn: () => kbBrowseApi.tree(project === '' ? null : project, mode),
    });

    // An explicit click always wins over the URL: once the reader touches
    // the tree at all, the deep link has served its purpose.
    const documentId = deepLinkConsumed ? selected?.documentId ?? null : deepLinkedDocumentId;

    const docQuery = useQuery<CitationDocument>({
        queryKey: ['kb-browse-document', documentId],
        queryFn: () => chatApi.fetchCitationDocument(documentId as number),
        enabled: documentId !== null,
    });

    const treeState: TreeState = treeQuery.isLoading
        ? 'loading'
        : treeQuery.isError
          ? 'error'
          : (treeQuery.data?.counts.docs ?? 0) === 0
            ? 'empty'
            : 'ready';

    const { pills, body } = useMemo(
        () => extractFrontmatterPills(docQuery.data?.content ?? ''),
        [docQuery.data?.content],
    );

    const docState = documentId === null
        ? 'idle'
        : docQuery.isLoading
          ? 'loading'
          : docQuery.isError
            ? 'error'
            : body.trim() === ''
              ? 'empty'
              : 'ready';

    return (
        <div data-testid="kb-browse-view" className="kb-browse-layout">
            <div className="kb-browse-tree">
                <div className="kb-browse-scope">
                    <label className="kb-browse-scope-label" htmlFor="kb-browse-project">
                        Project
                    </label>
                    <select
                        id="kb-browse-project"
                        data-testid="kb-browse-project"
                        value={project}
                        onChange={(e) => {
                            setProject(e.target.value);
                            setSelected(null);
                        }}
                    >
                        <option value="">All projects</option>
                        {projects.map((key) => (
                            <option key={key} value={key}>
                                {key}
                            </option>
                        ))}
                    </select>
                </div>

                <TreeView
                    data={treeQuery.data}
                    state={treeState}
                    q={q}
                    onQChange={setQ}
                    mode={mode}
                    onModeChange={setMode}
                    // The reader endpoint never returns soft-deleted
                    // documents, so the toggle is hidden rather than shown
                    // as a control that cannot change the result (R2/R14).
                    withTrashed={false}
                    onWithTrashedChange={() => undefined}
                    allowTrashedToggle={false}
                    selectedPath={selected?.path ?? null}
                    onSelect={(path: string | null, meta: KbTreeNode | null) => {
                        setDeepLinkConsumed(true);
                        // Contract-defensive, not a live path: see above.
                        if (path === null || meta === null || meta.type !== 'doc') {
                            setSelected(null);
                            return;
                        }
                        setSelected({ path, documentId: meta.meta.id });
                    }}
                />
            </div>

            <div className="kb-browse-detail" data-testid="kb-browse-detail" data-state={docState}>
                {docState === 'idle' && (
                    <p className="kb-browse-hint" data-testid="kb-browse-detail-idle">
                        Select a document to read it.
                    </p>
                )}

                {docState === 'loading' && (
                    <p className="kb-browse-hint" data-testid="kb-browse-detail-loading">
                        Loading document…
                    </p>
                )}

                {docState === 'error' && (
                    <div className="kb-browse-hint" role="alert" data-testid="kb-browse-detail-error">
                        <p>This document could not be opened.</p>
                        <Button
                            variant="secondary"
                            size="sm"
                            data-testid="kb-browse-detail-retry"
                            onClick={() => void docQuery.refetch()}
                        >
                            Retry
                        </Button>
                    </div>
                )}

                {(docState === 'ready' || docState === 'empty') && docQuery.data && (
                    <article className="kb-browse-document">
                        <header className="kb-browse-document-head">
                            <span className="kb-browse-document-icon" aria-hidden="true">
                                <Icon.File size={15} />
                            </span>
                            <div>
                                <h1 data-testid="kb-browse-detail-title">
                                    {docQuery.data.title ?? docQuery.data.source_path ?? 'Untitled'}
                                </h1>
                                <p className="kb-browse-document-path" data-testid="kb-browse-detail-path">
                                    {docQuery.data.source_path}
                                </p>
                            </div>
                        </header>

                        {pills.length > 0 && (
                            <dl className="kb-browse-pills" data-testid="kb-browse-detail-pills">
                                {pills.map((pill) => (
                                    <div key={pill.key} className="kb-browse-pill">
                                        <dt>{pill.key}</dt>
                                        <dd>{pill.value}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}

                        {docState === 'empty' ? (
                            <p className="kb-browse-hint" data-testid="kb-browse-detail-empty">
                                This document has no indexed content yet.
                            </p>
                        ) : (
                            <div data-testid="kb-browse-detail-body">
                                <Markdown
                                    source={body}
                                    project={docQuery.data.project_key ?? undefined}
                                />
                            </div>
                        )}
                    </article>
                )}
            </div>
        </div>
    );
}
