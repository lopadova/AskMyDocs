import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchWikiIndex, fetchWikiOperations, rebuildWikiIndex } from './wiki-indices.api';

/**
 * v8.11/P10 — Wiki Indices: the per-tenant index hub (the map), the per-project
 * roll-ups (page/concept/auto/human counts), and the append-only Auto-Wiki
 * operation log — with a one-click "Rebuild indices".
 *
 * R11 testids + observable data-state · R14 distinct loading/empty/error states
 * + loud mutation errors · R15 labelled controls. Tenant-wide (no project
 * selector): the hub endpoint returns every project for the active tenant.
 */
export function WikiIndicesView(): ReactNode {
    const qc = useQueryClient();
    const [actionError, setActionError] = useState<string | null>(null);
    const [rebuildNote, setRebuildNote] = useState<string | null>(null);

    const indexQuery = useQuery({
        queryKey: ['admin-wiki-index'],
        queryFn: () => fetchWikiIndex(),
        staleTime: 10_000,
    });

    const opsQuery = useQuery({
        queryKey: ['admin-wiki-operations'],
        queryFn: () => fetchWikiOperations(50),
        staleTime: 10_000,
    });

    const rebuildMutation = useMutation({
        mutationFn: () => rebuildWikiIndex(),
        onSuccess: (res) => {
            setActionError(null);
            setRebuildNote(`Rebuilt ${res.hub_project_count} project index(es).`);
            qc.invalidateQueries({ queryKey: ['admin-wiki-index'] });
            qc.invalidateQueries({ queryKey: ['admin-wiki-operations'] });
        },
        onError: (err: unknown) => {
            setRebuildNote(null);
            setActionError(err instanceof Error ? err.message : 'Could not rebuild the indices.');
        },
    });

    const data = indexQuery.data;
    const hub = data?.hub?.payload ?? null;
    const projects = data?.projects ?? [];
    const isEmpty = !indexQuery.isLoading && !indexQuery.isError && hub === null && projects.length === 0;
    const rootState =
        indexQuery.isLoading ? 'loading'
            : indexQuery.isError ? 'error'
                : isEmpty ? 'empty'
                    : 'ready';

    return (
        <div
            data-testid="admin-wiki-indices-view"
            data-state={rootState}
            aria-busy={indexQuery.isLoading || indexQuery.isFetching || rebuildMutation.isPending}
            style={{ padding: 24 }}
        >
            <header style={{ display: 'flex', alignItems: 'baseline', gap: 12, flexWrap: 'wrap', marginBottom: 8 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Wiki Indices</h1>
                <span style={{ flex: 1 }} />
                <button
                    type="button"
                    data-testid="admin-wiki-indices-rebuild"
                    onClick={() => rebuildMutation.mutate()}
                    disabled={rebuildMutation.isPending}
                    aria-label="Rebuild every project index and the tenant hub"
                    style={{ padding: '4px 12px', borderRadius: 6, fontSize: 12, cursor: rebuildMutation.isPending ? 'wait' : 'pointer', border: '1px solid var(--accent, #6366f1)', color: 'var(--accent, #6366f1)', background: 'transparent' }}
                >
                    {rebuildMutation.isPending ? 'Rebuilding…' : 'Rebuild indices'}
                </button>
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 680 }}>
                The Auto-Wiki map for this tenant: a roll-up of every project's typed pages and
                central concepts, plus an append-only log of every auto-wiki operation. Rebuilding
                recomputes each project index and the cross-project hub from the current corpus.
            </p>

            {actionError && (
                <p data-testid="admin-wiki-indices-action-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, marginBottom: 8 }}>
                    {actionError}
                </p>
            )}
            {rebuildNote && (
                <p data-testid="admin-wiki-indices-rebuild-note" role="status" style={{ color: 'var(--ok, #3fb950)', fontSize: 12, marginBottom: 8 }}>
                    {rebuildNote}
                </p>
            )}

            {indexQuery.isLoading && (
                <p data-testid="admin-wiki-indices-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>Loading indices…</p>
            )}
            {!indexQuery.isLoading && indexQuery.isError && (
                <p data-testid="admin-wiki-indices-error" data-state="error" role="alert" style={{ color: 'var(--err)', padding: 24, textAlign: 'center', border: '1px dashed var(--err)', borderRadius: 8 }}>
                    Failed to load the wiki indices. {indexQuery.error instanceof Error ? indexQuery.error.message : ''}
                </p>
            )}
            {isEmpty && (
                <p data-testid="admin-wiki-indices-empty" data-state="empty" style={{ color: 'var(--fg-3)', padding: 24, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}>
                    No indices yet — rebuild to compile the wiki map for this tenant.
                </p>
            )}

            {!isEmpty && !indexQuery.isLoading && !indexQuery.isError && (
                <div data-testid="admin-wiki-indices-hub" data-state="ready">
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 16 }}>
                        <Stat testid="admin-wiki-indices-stat-projects" label="Projects" value={hub?.project_count ?? projects.length} />
                        <Stat testid="admin-wiki-indices-stat-pages" label="Total pages" value={hub?.total_pages ?? projects.reduce((s, p) => s + p.payload.page_total, 0)} />
                        <Stat testid="admin-wiki-indices-stat-concepts" label="Total concepts" value={hub?.total_concepts ?? projects.reduce((s, p) => s + p.payload.concept_count, 0)} />
                    </div>

                    {projects.length > 0 && (
                        <table data-testid="admin-wiki-indices-projects" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginBottom: 20 }}>
                            <thead>
                                <tr style={{ textAlign: 'left', color: 'var(--fg-3)' }}>
                                    <th style={{ padding: '4px 8px' }}>Project</th>
                                    <th style={{ padding: '4px 8px' }}>Pages</th>
                                    <th style={{ padding: '4px 8px' }}>Concepts</th>
                                    <th style={{ padding: '4px 8px' }}>Auto</th>
                                    <th style={{ padding: '4px 8px' }}>Human</th>
                                </tr>
                            </thead>
                            <tbody>
                                {projects.map((p) => (
                                    <tr key={p.project_key} data-testid={`admin-wiki-indices-project-row-${p.project_key}`} style={{ borderTop: '1px solid var(--panel-border)', color: 'var(--fg-2)' }}>
                                        <td style={{ padding: '4px 8px', color: 'var(--fg-0)' }}>{p.project_key}</td>
                                        <td style={{ padding: '4px 8px' }}>{p.payload.page_total}</td>
                                        <td style={{ padding: '4px 8px' }}>{p.payload.concept_count}</td>
                                        <td style={{ padding: '4px 8px' }}>{p.payload.auto_count}</td>
                                        <td style={{ padding: '4px 8px' }}>{p.payload.human_count}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    <OperationLog
                        loading={opsQuery.isLoading}
                        error={opsQuery.isError}
                        operations={opsQuery.data ?? []}
                    />
                </div>
            )}
        </div>
    );
}

function Stat({ testid, label, value }: { testid: string; label: string; value: number }): ReactNode {
    return (
        <span data-testid={testid} style={{ fontSize: 12, color: 'var(--fg-3)', border: '1px solid var(--panel-border)', borderRadius: 6, padding: '3px 8px' }}>
            {label}: <strong style={{ color: 'var(--fg-0)' }}>{value}</strong>
        </span>
    );
}

function OperationLog({ loading, error, operations }: { loading: boolean; error: boolean; operations: Array<{ id: number; project_key: string; event_type: string; slug: string | null; created_at: string | null }> }): ReactNode {
    return (
        <section data-testid="admin-wiki-indices-operations" style={{ marginTop: 8 }}>
            <h2 style={{ fontSize: 13, color: 'var(--fg-0)', margin: '0 0 6px' }}>Recent operations</h2>
            {loading && <p data-testid="admin-wiki-indices-operations-loading" style={{ color: 'var(--fg-3)', fontSize: 12 }}>Loading log…</p>}
            {!loading && error && (
                <p data-testid="admin-wiki-indices-operations-error" role="alert" style={{ color: 'var(--err)', fontSize: 12 }}>
                    Failed to load the operation log.
                </p>
            )}
            {!loading && !error && operations.length === 0 && (
                <p data-testid="admin-wiki-indices-operations-empty" style={{ color: 'var(--fg-3)', fontSize: 12 }}>
                    No auto-wiki operations recorded yet.
                </p>
            )}
            {!loading && !error && operations.length > 0 && (
                <ul style={{ margin: 0, paddingLeft: 18, color: 'var(--fg-2)', fontSize: 12 }}>
                    {operations.map((op) => (
                        <li key={op.id} data-testid={`admin-wiki-indices-operation-${op.id}`}>
                            <strong style={{ color: 'var(--fg-0)' }}>{op.event_type}</strong>
                            {' · '}{op.project_key}{op.slug ? ` · ${op.slug}` : ''}
                            {op.created_at ? <span style={{ color: 'var(--fg-3)' }}>{' · '}{op.created_at}</span> : null}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
