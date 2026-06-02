import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getContentGaps, resolveContentGap, reasonLabel, type ContentGap } from './content-gaps.api';

/**
 * v8.8/W4 — Content Gaps: the questions the KB could NOT answer, ranked by how
 * often they were asked. Editors use this to see what to write next and
 * dismiss a gap once an article covers it.
 *
 * R11 testids · R14 distinct loading/empty/error states + loud mutation errors
 * · R15 accessible controls.
 */
export function ContentGapsView(): ReactNode {
    const qc = useQueryClient();
    const [reason, setReason] = useState<string>('');
    const [includeResolved, setIncludeResolved] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const query = useQuery({
        queryKey: ['admin-content-gaps', reason, includeResolved],
        queryFn: () => getContentGaps({ reason: reason || undefined, includeResolved }),
        staleTime: 15_000,
    });

    const resolveMutation = useMutation({
        mutationFn: (id: number) => resolveContentGap(id),
        onSuccess: () => {
            setActionError(null);
            qc.invalidateQueries({ queryKey: ['admin-content-gaps'] });
        },
        onError: (err: unknown) => {
            setActionError(err instanceof Error ? err.message : 'Could not resolve this gap.');
        },
    });

    const rows = query.data?.data ?? [];
    const rootState = query.isLoading ? 'loading' : query.isError ? 'error' : rows.length === 0 ? 'empty' : 'ready';

    return (
        <div
            data-testid="admin-content-gaps-view"
            data-state={rootState}
            aria-busy={query.isLoading || resolveMutation.isPending}
            style={{ padding: 24 }}
        >
            <header style={{ display: 'flex', alignItems: 'baseline', gap: 12, flexWrap: 'wrap', marginBottom: 8 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Content Gaps</h1>
                {query.data && (
                    <span data-testid="admin-content-gaps-count" style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                        {query.data.meta.total} total
                    </span>
                )}
                <span style={{ flex: 1 }} />
                <label style={{ fontSize: 11.5, color: 'var(--fg-3)', display: 'flex', gap: 6, alignItems: 'center' }}>
                    Reason
                    <select
                        data-testid="admin-content-gaps-reason-filter"
                        aria-label="Filter by reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        style={{ fontSize: 11.5, padding: '2px 4px' }}
                    >
                        <option value="">All</option>
                        {/* R18 — options derived from available_reasons in the API response,
                            not a hard-coded literal subset, so new reason codes surface
                            automatically without a FE deploy. */}
                        {(query.data?.meta.available_reasons ?? []).map((r) => (
                            <option key={r} value={r}>{reasonLabel(r)}</option>
                        ))}
                    </select>
                </label>
                <label style={{ fontSize: 11.5, color: 'var(--fg-3)', display: 'flex', gap: 6, alignItems: 'center' }}>
                    <input
                        type="checkbox"
                        data-testid="admin-content-gaps-include-resolved"
                        checked={includeResolved}
                        onChange={(e) => setIncludeResolved(e.target.checked)}
                    />
                    Show resolved
                </label>
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 680 }}>
                Every time the assistant refuses a question (no grounded context, or the model declines),
                it's recorded here. The most-asked unanswered questions rank first — write an article to
                close the gap, then resolve it.
            </p>

            {actionError && (
                <p data-testid="admin-content-gaps-action-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, marginBottom: 8 }}>
                    {actionError}
                </p>
            )}

            {query.isLoading && (
                <p data-testid="admin-content-gaps-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>Loading…</p>
            )}
            {!query.isLoading && query.isError && (
                <p data-testid="admin-content-gaps-error" data-state="error" role="alert" style={{ color: 'var(--err)', padding: 24, textAlign: 'center', border: '1px dashed var(--err)', borderRadius: 8 }}>
                    Failed to load content gaps. {query.error instanceof Error ? query.error.message : ''}
                </p>
            )}
            {!query.isLoading && !query.isError && rows.length === 0 && (
                <p data-testid="admin-content-gaps-empty" data-state="empty" style={{ color: 'var(--fg-3)', padding: 24, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}>
                    No content gaps — every question so far had a grounded answer.
                </p>
            )}

            {rows.length > 0 && (
                <div data-testid="admin-content-gaps-list" data-state="ready" style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                    {rows.map((row) => (
                        <GapRow key={row.id} row={row} busy={resolveMutation.isPending} onResolve={() => resolveMutation.mutate(row.id)} />
                    ))}
                </div>
            )}
        </div>
    );
}

function GapRow({ row, busy, onResolve }: { row: ContentGap; busy: boolean; onResolve: () => void }): ReactNode {
    const resolved = row.resolved_at !== null;
    return (
        <div
            data-testid={`admin-content-gap-${row.id}`}
            data-resolved={resolved ? 'true' : 'false'}
            style={{
                display: 'flex', alignItems: 'center', gap: 12,
                border: '1px solid var(--panel-border, rgba(255,255,255,.1))',
                borderRadius: 8, padding: '8px 12px',
                opacity: resolved ? 0.6 : 1,
            }}
        >
            <span data-testid={`admin-content-gap-${row.id}-count`} title="times asked" style={{ fontFamily: 'var(--font-mono, monospace)', fontSize: 13, color: 'var(--accent, #6366f1)', minWidth: 32, textAlign: 'right' }}>
                {row.occurrences}×
            </span>
            <span style={{ fontSize: 13, color: 'var(--fg-0)', flex: 1 }}>{row.query_text}</span>
            <span style={{ fontSize: 11, color: 'var(--fg-3)' }}>{row.project_key || '—'}</span>
            <span style={{ fontSize: 10.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.04em' }}>{reasonLabel(row.reason)}</span>
            {!resolved && (
                <button
                    type="button"
                    data-testid={`admin-content-gap-${row.id}-resolve`}
                    onClick={onResolve}
                    disabled={busy}
                    aria-label={`Resolve gap: ${row.query_text}`}
                    style={{ padding: '3px 10px', borderRadius: 6, fontSize: 11.5, cursor: 'pointer', border: '1px solid var(--accent, #6366f1)', color: 'var(--accent, #6366f1)', background: 'transparent' }}
                >
                    Resolve
                </button>
            )}
            {resolved && <span data-testid={`admin-content-gap-${row.id}-resolved-badge`} style={{ fontSize: 11, color: 'var(--ok, #3fb950)' }}>resolved</span>}
        </div>
    );
}
