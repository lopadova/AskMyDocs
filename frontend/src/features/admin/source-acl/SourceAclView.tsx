import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    getSourceAcl,
    setPrincipalStatus,
    principalTypeLabel,
    type TriageStatus,
    type UnmappedPrincipal,
} from './source-acl.api';

const QUERY_KEY = ['admin-source-acl'];

/**
 * v8.33 / ADR 0028 phase 2 — Source Permissions.
 *
 * Two things live on this screen, and keeping them distinct is the point.
 *
 * The counter says how many documents have their readers dictated by their
 * source rather than by project membership. That number going UP is the
 * feature working, so it is presented as a fact and not as a warning.
 *
 * The list underneath is the queue of people a source named that this
 * application could not place — an external collaborator, a group with no
 * internal counterpart. Those are ordinary and expected. Dismissing one
 * records that no internal subject should be granted; it does not grant
 * anything, and nothing on this screen does. Granting is an ACL row created
 * deliberately elsewhere, which is what keeps a triage screen from quietly
 * becoming a one-click access-granting screen.
 *
 * R11 testids · R14 distinct loading/empty/error states + loud mutation
 * errors · R15 accessible controls.
 */
export function SourceAclView(): ReactNode {
    const qc = useQueryClient();
    const [status, setStatus] = useState<TriageStatus>('pending');
    const [actionError, setActionError] = useState<string | null>(null);

    const query = useQuery({
        queryKey: [...QUERY_KEY, status],
        queryFn: () => getSourceAcl({ status }),
        staleTime: 15_000,
    });

    const decide = useMutation({
        mutationFn: ({ id, next }: { id: number; next: TriageStatus }) => setPrincipalStatus(id, next),
        onSuccess: async () => {
            setActionError(null);
            // Awaited so the row is gone from the list before the button
            // re-enables; otherwise a second click races the refetch.
            await qc.invalidateQueries({ queryKey: QUERY_KEY });
        },
        onError: (err: unknown) => {
            setActionError(err instanceof Error ? err.message : 'Could not record that decision.');
        },
    });

    const rows = query.data?.data ?? [];
    const summary = query.data?.summary;
    const rootState = query.isLoading
        ? 'loading'
        : query.isError
          ? 'error'
          : rows.length === 0
            ? 'empty'
            : 'ready';

    return (
        <div
            data-testid="admin-source-acl-view"
            data-state={rootState}
            aria-busy={query.isLoading || query.isFetching || decide.isPending}
            style={{ padding: 24 }}
        >
            <header style={{ marginBottom: 16 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Source Permissions</h1>
                <p style={{ margin: '6px 0 0', fontSize: 12, color: 'var(--fg-3)', maxWidth: 640 }}>
                    When a connected source reports who may read a file, those permissions are mirrored
                    onto the document and project membership stops being enough on its own. People the
                    source named but this application could not recognise are listed below, so nobody
                    loses access silently.
                </p>
            </header>

            {summary && (
                <dl
                    data-testid="admin-source-acl-summary"
                    style={{ display: 'flex', gap: 24, margin: '0 0 20px', flexWrap: 'wrap' }}
                >
                    <Stat
                        testId="admin-source-acl-restricted"
                        label="Documents governed by their source"
                        value={summary.documents_restricted}
                    />
                    <Stat
                        testId="admin-source-acl-pending"
                        label="Awaiting a decision"
                        value={summary.pending}
                    />
                    <Stat
                        testId="admin-source-acl-ignored"
                        label="Dismissed"
                        value={summary.ignored}
                    />
                </dl>
            )}

            <label style={{ fontSize: 11.5, color: 'var(--fg-3)', display: 'flex', gap: 6, alignItems: 'center', marginBottom: 12 }}>
                Showing
                <select
                    data-testid="admin-source-acl-status-filter"
                    aria-label="Filter by decision status"
                    value={status}
                    onChange={(e) => setStatus(e.target.value as TriageStatus)}
                    style={{ fontSize: 11.5, padding: '2px 4px' }}
                >
                    <option value="pending">Awaiting a decision</option>
                    <option value="ignored">Dismissed</option>
                </select>
            </label>

            {actionError && (
                <p data-testid="admin-source-acl-action-error" role="alert" style={{ color: 'var(--danger)', fontSize: 12 }}>
                    {actionError}
                </p>
            )}

            {query.isLoading && (
                <p data-testid="admin-source-acl-loading" style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                    Loading…
                </p>
            )}

            {query.isError && (
                <p data-testid="admin-source-acl-error" role="alert" style={{ color: 'var(--danger)', fontSize: 12 }}>
                    Could not load source permissions.
                </p>
            )}

            {!query.isLoading && !query.isError && rows.length === 0 && (
                <p data-testid="admin-source-acl-empty" style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                    {status === 'pending'
                        ? 'Nothing is waiting on a decision.'
                        : 'Nothing has been dismissed.'}
                </p>
            )}

            {rows.length > 0 && (
                <table data-testid="admin-source-acl-table" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                    <thead>
                        <tr style={{ textAlign: 'left', color: 'var(--fg-3)' }}>
                            <th scope="col" style={{ padding: '6px 8px' }}>Named by the source</th>
                            <th scope="col" style={{ padding: '6px 8px' }}>Kind</th>
                            <th scope="col" style={{ padding: '6px 8px' }}>Document</th>
                            <th scope="col" style={{ padding: '6px 8px' }}>Project</th>
                            <th scope="col" style={{ padding: '6px 8px' }}>Last seen</th>
                            <th scope="col" style={{ padding: '6px 8px' }}>Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row: UnmappedPrincipal) => (
                            <tr key={row.id} data-testid={`admin-source-acl-row-${row.id}`}>
                                <td style={{ padding: '6px 8px', color: 'var(--fg-0)' }}>
                                    {row.principal || '—'}
                                </td>
                                <td style={{ padding: '6px 8px', color: 'var(--fg-2)' }}>
                                    {principalTypeLabel(row.principal_type)}
                                </td>
                                <td style={{ padding: '6px 8px', color: 'var(--fg-2)' }}>
                                    {row.document_title ?? row.source_path ?? `#${row.document_id}`}
                                </td>
                                <td style={{ padding: '6px 8px', color: 'var(--fg-2)' }}>{row.project_key}</td>
                                <td style={{ padding: '6px 8px', color: 'var(--fg-3)' }}>
                                    {row.last_seen_at ? new Date(row.last_seen_at).toLocaleDateString() : '—'}
                                </td>
                                <td style={{ padding: '6px 8px' }}>
                                    <button
                                        type="button"
                                        data-testid={`admin-source-acl-row-${row.id}-${row.status === 'pending' ? 'dismiss' : 'reopen'}`}
                                        disabled={decide.isPending}
                                        onClick={() =>
                                            decide.mutate({
                                                id: row.id,
                                                next: row.status === 'pending' ? 'ignored' : 'pending',
                                            })
                                        }
                                        style={{ fontSize: 11.5, padding: '2px 8px' }}
                                    >
                                        {row.status === 'pending' ? 'Dismiss' : 'Reopen'}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

function Stat({ testId, label, value }: { testId: string; label: string; value: number }): ReactNode {
    return (
        <div>
            <dt style={{ fontSize: 11, color: 'var(--fg-3)' }}>{label}</dt>
            <dd data-testid={testId} style={{ margin: 0, fontSize: 20, color: 'var(--fg-0)' }}>
                {value}
            </dd>
        </div>
    );
}
