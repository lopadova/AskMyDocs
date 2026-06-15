import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminKbApi } from '../admin.api';
import { discardWikiPage, listWikiPages, promoteWikiPage, type WikiPage, type WikiTier } from './wiki-explorer.api';

/**
 * v8.11/P10 — Wiki Explorer: browse a project's typed wiki pages filtered by
 * provenance tier (auto / human), each with its backlink + outgoing-edge counts,
 * and PROMOTE an auto page to the human-vouched tier or DISCARD (soft-delete) it.
 * Both writes only act on auto pages — the firewall never lets the explorer touch
 * human-vouched content (ADR 0003).
 *
 * R11 testids + observable data-state · R14 distinct loading/empty/error states
 * + loud mutation errors (a 200-with-refusal surfaced distinctly) · R15 labelled
 * controls · R18 project options derived from the DB.
 */
export function WikiExplorerView(): ReactNode {
    const qc = useQueryClient();
    const [project, setProject] = useState<string>('');
    const [tier, setTier] = useState<WikiTier>('all');
    const [actionError, setActionError] = useState<string | null>(null);
    const [note, setNote] = useState<string | null>(null);
    const [pendingId, setPendingId] = useState<number | null>(null);

    const projectsQuery = useQuery({
        queryKey: ['admin-kb-projects'],
        queryFn: () => adminKbApi.projects(),
        staleTime: 60_000,
    });

    const pagesQuery = useQuery({
        queryKey: ['admin-wiki-pages', project, tier],
        queryFn: () => listWikiPages(project, tier),
        enabled: project !== '',
        staleTime: 10_000,
    });

    const refetchPages = () => qc.invalidateQueries({ queryKey: ['admin-wiki-pages', project, tier] });

    const promoteMutation = useMutation({
        mutationFn: (id: number) => promoteWikiPage(id),
        onMutate: (id) => { setPendingId(id); setActionError(null); setNote(null); },
        onSuccess: (res) => {
            res.promoted
                ? (setNote(`Promoted ${res.slug ?? 'page'} to the human-vouched tier.`), refetchPages())
                : setActionError(`Not promoted — ${res.reason ?? 'refused'}.`);
        },
        onError: (err: unknown) => setActionError(err instanceof Error ? err.message : 'Could not promote the page.'),
        onSettled: () => setPendingId(null),
    });

    const discardMutation = useMutation({
        mutationFn: (id: number) => discardWikiPage(id),
        onMutate: (id) => { setPendingId(id); setActionError(null); setNote(null); },
        onSuccess: (res) => {
            res.discarded
                ? (setNote(`Discarded ${res.slug ?? 'page'}.`), refetchPages())
                : setActionError(`Not discarded — ${res.reason ?? 'refused'}.`);
        },
        onError: (err: unknown) => setActionError(err instanceof Error ? err.message : 'Could not discard the page.'),
        onSettled: () => setPendingId(null),
    });

    const data = pagesQuery.data;
    const pages = data?.pages ?? [];
    const rootState =
        project === '' ? 'idle'
            : pagesQuery.isLoading ? 'loading'
                : pagesQuery.isError ? 'error'
                    : pages.length === 0 ? 'empty'
                        : 'ready';

    return (
        <div
            data-testid="admin-wiki-explorer-view"
            data-state={rootState}
            aria-busy={pagesQuery.isLoading || pagesQuery.isFetching || promoteMutation.isPending || discardMutation.isPending}
            style={{ padding: 24 }}
        >
            <header style={{ display: 'flex', alignItems: 'baseline', gap: 12, flexWrap: 'wrap', marginBottom: 8 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Wiki Explorer</h1>
                <span style={{ flex: 1 }} />
                <label style={{ fontSize: 11.5, color: 'var(--fg-3)', display: 'flex', gap: 6, alignItems: 'center' }}>
                    Project
                    <select
                        data-testid="admin-wiki-explorer-project"
                        aria-label="Select project to explore"
                        value={project}
                        onChange={(e) => { setProject(e.target.value); setActionError(null); setNote(null); }}
                        style={{ fontSize: 11.5, padding: '2px 4px' }}
                    >
                        <option value="">Select a project…</option>
                        {(projectsQuery.data?.projects ?? []).map((p) => (
                            <option key={p} value={p}>{p}</option>
                        ))}
                    </select>
                </label>
                <label style={{ fontSize: 11.5, color: 'var(--fg-3)', display: 'flex', gap: 6, alignItems: 'center' }}>
                    Tier
                    <select
                        data-testid="admin-wiki-explorer-tier"
                        aria-label="Filter by provenance tier"
                        value={tier}
                        onChange={(e) => setTier(e.target.value as WikiTier)}
                        style={{ fontSize: 11.5, padding: '2px 4px' }}
                    >
                        <option value="all">All</option>
                        <option value="auto">Auto</option>
                        <option value="human">Human</option>
                    </select>
                </label>
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 680 }}>
                Every typed wiki page in the project, with its backlink + outgoing-edge counts. Pages
                compiled by the Auto-Wiki carry an <strong>auto</strong> badge and rank below human-vouched
                content. <strong>Promote</strong> an auto page once reviewed; <strong>discard</strong> removes
                it. Human pages are read-only here — the firewall never lets the explorer touch them.
            </p>

            {actionError && (
                <p data-testid="admin-wiki-explorer-action-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, marginBottom: 8 }}>
                    {actionError}
                </p>
            )}
            {note && (
                <p data-testid="admin-wiki-explorer-note" role="status" style={{ color: 'var(--ok, #3fb950)', fontSize: 12, marginBottom: 8 }}>
                    {note}
                </p>
            )}

            {project === '' && (
                <p data-testid="admin-wiki-explorer-idle" data-state="idle" style={{ color: 'var(--fg-3)', padding: 24, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}>
                    Select a project to explore its wiki pages.
                </p>
            )}
            {project !== '' && pagesQuery.isLoading && (
                <p data-testid="admin-wiki-explorer-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>Loading pages…</p>
            )}
            {project !== '' && !pagesQuery.isLoading && pagesQuery.isError && (
                <p data-testid="admin-wiki-explorer-error" data-state="error" role="alert" style={{ color: 'var(--err)', padding: 24, textAlign: 'center', border: '1px dashed var(--err)', borderRadius: 8 }}>
                    Failed to load wiki pages. {pagesQuery.error instanceof Error ? pagesQuery.error.message : ''}
                </p>
            )}
            {project !== '' && !pagesQuery.isLoading && !pagesQuery.isError && pages.length === 0 && (
                <p data-testid="admin-wiki-explorer-empty" data-state="empty" style={{ color: 'var(--fg-3)', padding: 24, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}>
                    No wiki pages for this project and tier.
                </p>
            )}

            {pages.length > 0 && (
                <table data-testid="admin-wiki-explorer-table" data-state="ready" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                    <thead>
                        <tr style={{ textAlign: 'left', color: 'var(--fg-3)' }}>
                            <th style={{ padding: '4px 8px' }}>Page</th>
                            <th style={{ padding: '4px 8px' }}>Type</th>
                            <th style={{ padding: '4px 8px' }}>Tier</th>
                            <th style={{ padding: '4px 8px' }}>Backlinks</th>
                            <th style={{ padding: '4px 8px' }}>Outgoing</th>
                            <th style={{ padding: '4px 8px' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {pages.map((p) => (
                            <PageRow
                                key={p.id}
                                page={p}
                                pending={pendingId === p.id}
                                onPromote={() => promoteMutation.mutate(p.id)}
                                onDiscard={() => discardMutation.mutate(p.id)}
                            />
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

function PageRow({ page, pending, onPromote, onDiscard }: { page: WikiPage; pending: boolean; onPromote: () => void; onDiscard: () => void }): ReactNode {
    const isAuto = page.generation_source === 'auto';
    return (
        <tr data-testid={`admin-wiki-explorer-row-${page.id}`} data-tier={page.generation_source} style={{ borderTop: '1px solid var(--panel-border)', color: 'var(--fg-2)' }}>
            <td style={{ padding: '4px 8px', color: 'var(--fg-0)' }}>{page.title || page.slug}<span style={{ color: 'var(--fg-3)' }}> · {page.slug}</span></td>
            <td style={{ padding: '4px 8px' }}>{page.canonical_type ?? '—'}</td>
            <td style={{ padding: '4px 8px' }}>
                <span
                    data-testid={`admin-wiki-explorer-row-${page.id}-tier`}
                    style={{ fontSize: 10.5, padding: '1px 6px', borderRadius: 5, border: '1px solid var(--panel-border)', color: isAuto ? 'var(--warn, #d29922)' : 'var(--ok, #3fb950)' }}
                >
                    {page.generation_source}
                </span>
            </td>
            <td style={{ padding: '4px 8px' }}>{page.backlinks}</td>
            <td style={{ padding: '4px 8px' }}>{page.outgoing_edges}</td>
            <td style={{ padding: '4px 8px' }}>
                {isAuto ? (
                    <span style={{ display: 'inline-flex', gap: 6 }}>
                        <button
                            type="button"
                            data-testid={`admin-wiki-explorer-row-${page.id}-promote`}
                            onClick={onPromote}
                            disabled={pending}
                            aria-label={`Promote ${page.slug} to the human tier`}
                            style={{ fontSize: 10.5, padding: '1px 8px', borderRadius: 5, cursor: pending ? 'wait' : 'pointer', border: '1px solid var(--accent, #6366f1)', color: 'var(--accent, #6366f1)', background: 'transparent' }}
                        >
                            {pending ? '…' : 'Promote'}
                        </button>
                        <button
                            type="button"
                            data-testid={`admin-wiki-explorer-row-${page.id}-discard`}
                            onClick={onDiscard}
                            disabled={pending}
                            aria-label={`Discard ${page.slug}`}
                            style={{ fontSize: 10.5, padding: '1px 8px', borderRadius: 5, cursor: pending ? 'wait' : 'pointer', border: '1px solid var(--err, #c4391d)', color: 'var(--err, #c4391d)', background: 'transparent' }}
                        >
                            {pending ? '…' : 'Discard'}
                        </button>
                    </span>
                ) : (
                    <span data-testid={`admin-wiki-explorer-row-${page.id}-readonly`} style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>read-only</span>
                )}
            </td>
        </tr>
    );
}
