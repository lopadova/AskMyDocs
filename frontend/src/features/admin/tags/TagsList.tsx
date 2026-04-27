import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminTagsApi, type AdminTag, type CreateTagPayload, type UpdateTagPayload } from './admin-tags.api';
import { TagFormDialog } from './TagFormDialog';

/**
 * T2.10 — Admin Tags list view.
 *
 * Renders the full kb_tags catalogue with create/edit/delete actions.
 * Filtering by project is via a top text input (clientside — the BE
 * supports `project_keys[]` query param but client-side filter is
 * sufficient at the v3.0 scale where tag count is bounded).
 *
 * Two operations require confirm steps:
 *   1. Edit + save → BE rejects duplicate slug (422); error surfaces
 *      inline next to the input.
 *   2. Delete → inline confirm (Delete | Cancel) on the row, NOT a
 *      modal-on-top-of-the-page. Keeps focus near the action.
 *
 * R11: every interactive surface has `data-testid`. R15: dialogs
 * carry `role="dialog"` + `aria-modal`. Bound `<label>` per field.
 */
export function TagsList(): ReactNode {
    const qc = useQueryClient();
    const [filter, setFilter] = useState('');
    const [editingTag, setEditingTag] = useState<AdminTag | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    // Map of `tag.id` → confirming-state (true when the row is showing
    // the inline Delete/Cancel buttons). Keyed dictionary so multiple
    // rows don't share the same confirm state by accident.
    const [confirmingDelete, setConfirmingDelete] = useState<Record<number, boolean>>({});

    const tagsQuery = useQuery({
        queryKey: ['admin-kb-tags'],
        queryFn: () => adminTagsApi.list(),
        staleTime: 30_000,
    });

    const createMutation = useMutation({
        mutationFn: (payload: CreateTagPayload) => adminTagsApi.create(payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-kb-tags'] });
            setCreateOpen(false);
            setSubmitError(null);
        },
        onError: (err: unknown) => {
            const message = err instanceof Error ? err.message : 'Could not create tag.';
            setSubmitError(message);
        },
    });

    const updateMutation = useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateTagPayload }) =>
            adminTagsApi.update(id, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-kb-tags'] });
            setEditingTag(null);
            setSubmitError(null);
        },
        onError: (err: unknown) => {
            const message = err instanceof Error ? err.message : 'Could not update tag.';
            setSubmitError(message);
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => adminTagsApi.delete(id),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-kb-tags'] }),
    });

    const tags = tagsQuery.data ?? [];
    const filteredTags = useMemo(() => {
        const needle = filter.trim().toLowerCase();
        if (needle === '') return tags;
        return tags.filter(
            (t) =>
                t.project_key.toLowerCase().includes(needle) ||
                t.slug.toLowerCase().includes(needle) ||
                t.label.toLowerCase().includes(needle),
        );
    }, [tags, filter]);

    return (
        <div data-testid="admin-tags-view" style={{ padding: 24 }}>
            <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Tags</h1>
                <span data-testid="admin-tags-count" style={{ color: 'var(--fg-3)', fontSize: 12 }}>
                    {tags.length} total
                </span>
                <span style={{ flex: 1 }} />
                <input
                    data-testid="admin-tags-filter"
                    type="text"
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                    placeholder="Filter by project / slug / label"
                    aria-label="Filter tags"
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
                    data-testid="admin-tags-create"
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
                    + New tag
                </button>
            </header>

            {tagsQuery.isLoading && (
                <p data-testid="admin-tags-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>
                    Loading…
                </p>
            )}
            {!tagsQuery.isLoading && tags.length === 0 && (
                <p
                    data-testid="admin-tags-empty"
                    data-state="empty"
                    style={{ color: 'var(--fg-3)', padding: 24, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}
                >
                    No tags yet. Click <code>+ New tag</code> to create one.
                </p>
            )}
            {!tagsQuery.isLoading && tags.length > 0 && filteredTags.length === 0 && (
                <p
                    data-testid="admin-tags-no-match"
                    style={{ color: 'var(--fg-3)' }}
                >
                    No tags match the filter.
                </p>
            )}
            {filteredTags.length > 0 && (
                <table
                    data-testid="admin-tags-table"
                    data-state="ready"
                    style={{
                        width: '100%',
                        borderCollapse: 'collapse',
                        fontSize: 12.5,
                    }}
                >
                    <thead>
                        <tr style={{ textAlign: 'left', color: 'var(--fg-2)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em' }}>
                            <th style={cellStyle}>Color</th>
                            <th style={cellStyle}>Project</th>
                            <th style={cellStyle}>Slug</th>
                            <th style={cellStyle}>Label</th>
                            <th style={{ ...cellStyle, textAlign: 'right' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredTags.map((tag) => {
                            const isConfirming = confirmingDelete[tag.id] === true;
                            return (
                                <tr
                                    key={tag.id}
                                    data-testid={`admin-tag-row-${tag.id}`}
                                    data-tag-slug={tag.slug}
                                    data-tag-project={tag.project_key}
                                    style={{ borderTop: '1px solid var(--panel-border, rgba(255,255,255,.06))' }}
                                >
                                    <td style={cellStyle}>
                                        <span
                                            data-testid={`admin-tag-row-${tag.id}-color`}
                                            aria-label={tag.color ? `Color ${tag.color}` : 'No color set'}
                                            style={{
                                                display: 'inline-block',
                                                width: 14,
                                                height: 14,
                                                borderRadius: 99,
                                                background: tag.color ?? 'transparent',
                                                border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                                            }}
                                        />
                                    </td>
                                    <td style={{ ...cellStyle, color: 'var(--fg-2)' }}>{tag.project_key}</td>
                                    <td style={{ ...cellStyle, fontFamily: 'var(--font-mono, monospace)', color: 'var(--fg-1)' }}>{tag.slug}</td>
                                    <td style={{ ...cellStyle, color: 'var(--fg-0)' }}>{tag.label}</td>
                                    <td style={{ ...cellStyle, textAlign: 'right' }}>
                                        {!isConfirming && (
                                            <>
                                                <button
                                                    type="button"
                                                    data-testid={`admin-tag-row-${tag.id}-edit`}
                                                    aria-label={`Edit tag ${tag.label}`}
                                                    onClick={() => {
                                                        setSubmitError(null);
                                                        setEditingTag(tag);
                                                    }}
                                                    style={iconButtonStyle()}
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    data-testid={`admin-tag-row-${tag.id}-delete`}
                                                    aria-label={`Delete tag ${tag.label}`}
                                                    onClick={() =>
                                                        setConfirmingDelete((prev) => ({ ...prev, [tag.id]: true }))
                                                    }
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
                                                    data-testid={`admin-tag-row-${tag.id}-delete-confirm`}
                                                    onClick={() => {
                                                        deleteMutation.mutate(tag.id);
                                                        setConfirmingDelete((prev) => ({ ...prev, [tag.id]: false }));
                                                    }}
                                                    style={confirmButtonStyle('danger')}
                                                >
                                                    Confirm
                                                </button>
                                                <button
                                                    type="button"
                                                    data-testid={`admin-tag-row-${tag.id}-delete-cancel`}
                                                    onClick={() =>
                                                        setConfirmingDelete((prev) => ({ ...prev, [tag.id]: false }))
                                                    }
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
                <TagFormDialog
                    tag={null}
                    onClose={() => setCreateOpen(false)}
                    onSubmit={(payload) => createMutation.mutate(payload as CreateTagPayload)}
                    submitError={submitError}
                    isSubmitting={createMutation.isPending}
                />
            )}
            {editingTag !== null && (
                <TagFormDialog
                    tag={editingTag}
                    onClose={() => setEditingTag(null)}
                    onSubmit={(payload) =>
                        updateMutation.mutate({
                            id: editingTag.id,
                            payload: payload as UpdateTagPayload,
                        })
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
