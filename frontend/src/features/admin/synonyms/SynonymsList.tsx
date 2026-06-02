import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    adminSynonymsApi,
    type AdminSynonym,
    type CreateSynonymPayload,
    type UpdateSynonymPayload,
} from './synonyms.api';
import { SynonymFormDialog } from './SynonymFormDialog';

/**
 * v8.7/W1 — Admin Synonyms list view.
 *
 * Renders the per-(tenant, project) synonym groups with create/edit/delete
 * actions. Each group expands queries bidirectionally at retrieval time so
 * in-house jargon connects to its plain-language equivalents.
 *
 * R11: every interactive surface has a `data-testid`. R15: dialogs carry
 * `role="dialog"` + bound `<label>`s. Delete uses an inline confirm
 * (Confirm | Cancel) on the row, not a modal-on-top-of-the-page.
 */
export function SynonymsList(): ReactNode {
    const qc = useQueryClient();
    const [filter, setFilter] = useState('');
    const [editing, setEditing] = useState<AdminSynonym | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const [confirmingDelete, setConfirmingDelete] = useState<Record<number, boolean>>({});

    const query = useQuery({
        queryKey: ['admin-kb-synonyms'],
        queryFn: () => adminSynonymsApi.list(),
        staleTime: 30_000,
    });

    const createMutation = useMutation({
        mutationFn: (payload: CreateSynonymPayload) => adminSynonymsApi.create(payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-kb-synonyms'] });
            setCreateOpen(false);
            setSubmitError(null);
        },
        onError: (err: unknown) => {
            setSubmitError(err instanceof Error ? err.message : 'Could not create synonym group.');
        },
    });

    const updateMutation = useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateSynonymPayload }) =>
            adminSynonymsApi.update(id, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-kb-synonyms'] });
            setEditing(null);
            setSubmitError(null);
        },
        onError: (err: unknown) => {
            setSubmitError(err instanceof Error ? err.message : 'Could not update synonym group.');
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => adminSynonymsApi.delete(id),
        onSuccess: () => {
            setDeleteError(null);
            qc.invalidateQueries({ queryKey: ['admin-kb-synonyms'] });
        },
        // R14 — a failed DELETE must NOT be swallowed silently; surface it
        // so the row staying put is explained instead of looking like a
        // no-op.
        onError: (err: unknown) => {
            setDeleteError(err instanceof Error ? err.message : 'Could not delete synonym group.');
        },
    });

    const rows = query.data ?? [];
    const filtered = useMemo(() => {
        const needle = filter.trim().toLowerCase();
        if (needle === '') return rows;
        return rows.filter(
            (r) =>
                r.project_key.toLowerCase().includes(needle) ||
                r.term.toLowerCase().includes(needle) ||
                r.synonyms.some((s) => s.toLowerCase().includes(needle)),
        );
    }, [rows, filter]);

    return (
        <div data-testid="admin-synonyms-view" style={{ padding: 24 }}>
            <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 8 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Synonyms</h1>
                <span data-testid="admin-synonyms-count" style={{ color: 'var(--fg-3)', fontSize: 12 }}>
                    {rows.length} total
                </span>
                <span style={{ flex: 1 }} />
                <input
                    data-testid="admin-synonyms-filter"
                    type="text"
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                    placeholder="Filter by project / term / synonym"
                    aria-label="Filter synonym groups"
                    style={{
                        padding: '5px 10px',
                        borderRadius: 6,
                        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                        background: 'var(--bg-3, rgba(255,255,255,.04))',
                        color: 'var(--fg-0)',
                        fontSize: 12,
                        minWidth: 240,
                    }}
                />
                <button
                    type="button"
                    data-testid="admin-synonyms-create"
                    onClick={() => {
                        setSubmitError(null);
                        setCreateOpen(true);
                    }}
                    style={{
                        padding: '5px 12px',
                        borderRadius: 6,
                        border: '1px solid var(--accent, #6366f1)',
                        background: 'var(--accent, #6366f1)',
                        color: 'white',
                        fontSize: 12,
                        cursor: 'pointer',
                    }}
                >
                    + New group
                </button>
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 640 }}>
                A query mentioning any member of a group also searches every other member, so industry
                jargon, acronyms, and product codenames connect to their plain-language equivalents.
            </p>

            {deleteError && (
                <p data-testid="admin-synonyms-delete-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, marginBottom: 8 }}>
                    {deleteError}
                </p>
            )}

            {query.isLoading && (
                <p data-testid="admin-synonyms-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>
                    Loading…
                </p>
            )}
            {/* R14 — a failed list query must NOT render as the empty state;
                a network error / 500 is visually distinct from "no groups yet". */}
            {!query.isLoading && query.isError && (
                <p
                    data-testid="admin-synonyms-error"
                    data-state="error"
                    role="alert"
                    style={{ color: 'var(--err)', padding: 24, textAlign: 'center', border: '1px dashed var(--err)', borderRadius: 8 }}
                >
                    Failed to load synonym groups.{' '}
                    {query.error instanceof Error ? query.error.message : 'Please retry.'}
                </p>
            )}
            {!query.isLoading && !query.isError && rows.length === 0 && (
                <p
                    data-testid="admin-synonyms-empty"
                    data-state="empty"
                    style={{
                        color: 'var(--fg-3)',
                        padding: 24,
                        textAlign: 'center',
                        border: '1px dashed var(--panel-border)',
                        borderRadius: 8,
                    }}
                >
                    No synonym groups yet. Click <code>+ New group</code> to create one.
                </p>
            )}
            {!query.isLoading && rows.length > 0 && filtered.length === 0 && (
                <p data-testid="admin-synonyms-no-match" style={{ color: 'var(--fg-3)' }}>
                    No groups match the filter.
                </p>
            )}
            {filtered.length > 0 && (
                <table
                    data-testid="admin-synonyms-table"
                    data-state="ready"
                    style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}
                >
                    <thead>
                        <tr style={{ textAlign: 'left', color: 'var(--fg-2)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em' }}>
                            <th style={cellStyle}>Project</th>
                            <th style={cellStyle}>Term</th>
                            <th style={cellStyle}>Synonyms</th>
                            <th style={cellStyle}>Enabled</th>
                            <th style={{ ...cellStyle, textAlign: 'right' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filtered.map((row) => {
                            const isConfirming = confirmingDelete[row.id] === true;
                            return (
                                <tr
                                    key={row.id}
                                    data-testid={`admin-synonym-row-${row.id}`}
                                    data-synonym-term={row.term}
                                    data-synonym-project={row.project_key}
                                    style={{ borderTop: '1px solid var(--panel-border, rgba(255,255,255,.06))' }}
                                >
                                    <td style={{ ...cellStyle, color: 'var(--fg-2)' }}>{row.project_key}</td>
                                    <td style={{ ...cellStyle, fontFamily: 'var(--font-mono, monospace)', color: 'var(--fg-0)' }}>{row.term}</td>
                                    <td style={{ ...cellStyle, color: 'var(--fg-1)' }}>{row.synonyms.join(', ')}</td>
                                    <td style={cellStyle}>
                                        <span
                                            data-testid={`admin-synonym-row-${row.id}-enabled`}
                                            data-enabled={row.enabled ? 'true' : 'false'}
                                            style={{ color: row.enabled ? 'var(--ok, #3fb950)' : 'var(--fg-3)' }}
                                        >
                                            {row.enabled ? 'On' : 'Off'}
                                        </span>
                                    </td>
                                    <td style={{ ...cellStyle, textAlign: 'right' }}>
                                        {!isConfirming && (
                                            <>
                                                <button
                                                    type="button"
                                                    data-testid={`admin-synonym-row-${row.id}-edit`}
                                                    aria-label={`Edit synonym group ${row.term}`}
                                                    onClick={() => {
                                                        setSubmitError(null);
                                                        setEditing(row);
                                                    }}
                                                    style={iconButtonStyle()}
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    data-testid={`admin-synonym-row-${row.id}-delete`}
                                                    aria-label={`Delete synonym group ${row.term}`}
                                                    onClick={() => setConfirmingDelete((prev) => ({ ...prev, [row.id]: true }))}
                                                    style={iconButtonStyle()}
                                                >
                                                    Delete
                                                </button>
                                            </>
                                        )}
                                        {isConfirming && (
                                            <>
                                                <button
                                                    type="button"
                                                    data-testid={`admin-synonym-row-${row.id}-delete-confirm`}
                                                    onClick={() => {
                                                        deleteMutation.mutate(row.id);
                                                        setConfirmingDelete((prev) => ({ ...prev, [row.id]: false }));
                                                    }}
                                                    style={confirmButtonStyle('danger')}
                                                >
                                                    Confirm
                                                </button>
                                                <button
                                                    type="button"
                                                    data-testid={`admin-synonym-row-${row.id}-delete-cancel`}
                                                    onClick={() => setConfirmingDelete((prev) => ({ ...prev, [row.id]: false }))}
                                                    style={confirmButtonStyle('neutral')}
                                                >
                                                    Cancel
                                                </button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            )}

            {createOpen && (
                <SynonymFormDialog
                    synonym={null}
                    onClose={() => setCreateOpen(false)}
                    onSubmit={(payload) => createMutation.mutate(payload as CreateSynonymPayload)}
                    submitError={submitError}
                    isSubmitting={createMutation.isPending}
                />
            )}
            {editing !== null && (
                <SynonymFormDialog
                    synonym={editing}
                    onClose={() => setEditing(null)}
                    onSubmit={(payload) =>
                        updateMutation.mutate({ id: editing.id, payload: payload as UpdateSynonymPayload })
                    }
                    submitError={submitError}
                    isSubmitting={updateMutation.isPending}
                />
            )}
        </div>
    );
}

const cellStyle: React.CSSProperties = { padding: '8px 10px', verticalAlign: 'middle' };

function iconButtonStyle(): React.CSSProperties {
    return {
        marginLeft: 6,
        padding: '4px 10px',
        border: '1px solid var(--panel-border, rgba(255,255,255,.12))',
        borderRadius: 6,
        background: 'transparent',
        color: 'var(--fg-1)',
        fontSize: 11.5,
        cursor: 'pointer',
    };
}

function confirmButtonStyle(variant: 'danger' | 'neutral'): React.CSSProperties {
    const isDanger = variant === 'danger';
    return {
        marginLeft: 6,
        padding: '4px 10px',
        borderRadius: 6,
        border: '1px solid ' + (isDanger ? 'var(--err, #c4391d)' : 'var(--panel-border, rgba(255,255,255,.15))'),
        background: isDanger ? 'var(--err, #c4391d)' : 'transparent',
        color: isDanger ? 'white' : 'var(--fg-2)',
        fontSize: 11.5,
        cursor: 'pointer',
    };
}
