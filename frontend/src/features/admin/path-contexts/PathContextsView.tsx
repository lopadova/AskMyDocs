import { useMemo, useState, type CSSProperties, type ReactNode } from 'react';
import { SelectField, SelectOption } from '@/components/ui/select-field';
import { useUiTranslation } from '../../../lib/ui-locale';
import { useKbProjects } from '../kb/kb-tree.api';
import { toAdminError } from '../shared/errors';
import { ToastHost, useToast } from '../shared/Toast';
import { PathContextForm } from './PathContextForm';
import {
    isPathContextsDisabled,
    pathContextsErrorMessage,
    useDeletePathContext,
    usePathContexts,
    useUpsertPathContext,
    type PathContext,
    type PathContextFieldErrors,
    type UpsertPathContextPayload,
} from './path-contexts.api';

/**
 * Admin "Path contexts" screen.
 *
 * An admin describes what a part of the corpus is (project `hr`, prefix
 * `policies/` → "Current HR policies, approved by the DPO"); the backend
 * injects the chain of matching descriptions into the RAG prompt as
 * untrusted data. This view lists, upserts and deletes them.
 *
 * R11/R29: stable testids, observable `data-state` on the page container
 * (`idle|loading|ready|empty|error|unavailable`) + `aria-busy`.
 * R14: a failed list is the `error` state, never `empty`.
 * R18: project choices come from the projects API, never a literal list.
 * R43: with `KB_PATH_CONTEXTS_ENABLED=false` every endpoint answers
 * 404 `path_contexts_disabled`; the page then shows one clean
 * "unavailable" panel (no table, no form, no raw error).
 */

type PageState = 'idle' | 'loading' | 'ready' | 'empty' | 'error' | 'unavailable';

const FIELDS = ['project_key', 'path_prefix', 'description'] as const;

export function PathContextsView(): ReactNode {
    const { t } = useUiTranslation();
    const toast = useToast();
    const [projectFilter, setProjectFilter] = useState('');
    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<PathContext | null>(null);
    const [serverErrors, setServerErrors] = useState<PathContextFieldErrors>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [confirmingId, setConfirmingId] = useState<number | null>(null);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const projectsQuery = useKbProjects();
    const query = usePathContexts(projectFilter);
    const upsert = useUpsertPathContext();
    const remove = useDeletePathContext();

    const rows = query.data ?? [];
    const unavailable = isPathContextsDisabled(query.error);
    const state: PageState = pageState(query, unavailable, rows.length);

    // R18 — derive the choices from the API; also keep any project already
    // referenced by a row so an edit never loses its own value.
    const projectOptions = useMemo(() => {
        const keys = new Set<string>(projectsQuery.data?.projects ?? []);
        rows.forEach((r) => keys.add(r.project_key));
        return [...keys].sort((a, b) => a.localeCompare(b));
    }, [projectsQuery.data, rows]);

    const formOpen = createOpen || editing !== null;

    const resetFormFeedback = () => {
        setServerErrors({});
        setSubmitError(null);
    };
    const closeForm = () => {
        setCreateOpen(false);
        setEditing(null);
        resetFormFeedback();
    };

    const handleSubmit = (payload: UpsertPathContextPayload) => {
        resetFormFeedback();
        upsert.mutate(payload, {
            onSuccess: () => {
                toast.success(editing ? t('Path context updated.') : t('Path context saved.'), 'admin-path-contexts-saved');
                closeForm();
            },
            onError: (err: unknown) => {
                const mapped = toAdminError(err);
                const fields = fieldErrorsOf(mapped.fieldErrors);
                setServerErrors(fields);
                // Field-level 422s render next to their inputs; anything else
                // (500, network, flag flipped off mid-session) is surfaced loudly.
                setSubmitError(Object.keys(fields).length > 0 ? null : pathContextsErrorMessage(err));
            },
        });
    };

    const handleDelete = (id: number) => {
        setDeleteError(null);
        setConfirmingId(null);
        remove.mutate(id, {
            onSuccess: () => toast.success(t('Path context deleted.'), 'admin-path-contexts-deleted'),
            // R14 — a failed DELETE is explained, not swallowed.
            onError: (err: unknown) => setDeleteError(pathContextsErrorMessage(err)),
        });
    };

    return (
        <div
            data-testid="admin-path-contexts-page"
            data-state={state}
            aria-busy={state === 'loading'}
            style={{ padding: 24 }}
        >
            <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 8, flexWrap: 'wrap' }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>{t('Path contexts')}</h1>
                {state !== 'unavailable' && (
                    <span data-testid="admin-path-contexts-count" style={{ color: 'var(--fg-3)', fontSize: 12 }}>
                        {t('{count} total', { count: rows.length })}
                    </span>
                )}
                <span style={{ flex: 1 }} />
                {state !== 'unavailable' && (
                    <>
                        <label htmlFor="admin-path-contexts-project-filter" style={{ color: 'var(--fg-2)', fontSize: 12 }}>
                            {t('Project')}
                        </label>
                        <SelectField
                            id="admin-path-contexts-project-filter"
                            data-testid="admin-path-contexts-project-filter"
                            value={projectFilter}
                            onValueChange={setProjectFilter}
                            style={selectStyle}
                        >
                            <SelectOption value="">{t('All projects')}</SelectOption>
                            {projectOptions.map((key) => (
                                <SelectOption key={key} value={key}>
                                    {key}
                                </SelectOption>
                            ))}
                        </SelectField>
                        <button
                            type="button"
                            data-testid="admin-path-contexts-add"
                            onClick={() => {
                                resetFormFeedback();
                                setEditing(null);
                                setCreateOpen(true);
                            }}
                            style={primaryButtonStyle}
                        >
                            {t('+ Add path context')}
                        </button>
                    </>
                )}
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 640 }}>
                {t('Describe what a part of the corpus is. When an answer cites a document under a prefix, the matching descriptions are given to the model as context (treated as untrusted data).')}
            </p>

            {state === 'unavailable' && (
                <div
                    data-testid="admin-path-contexts-unavailable"
                    data-state="unavailable"
                    role="status"
                    style={panelStyle('var(--panel-border)')}
                >
                    {t('Path contexts are disabled on this deployment.')}
                </div>
            )}

            {state !== 'unavailable' && formOpen && (
                <PathContextForm
                    context={editing}
                    projectOptions={projectOptions}
                    defaultProjectKey={projectFilter}
                    onSubmit={handleSubmit}
                    onCancel={closeForm}
                    serverErrors={serverErrors}
                    submitError={submitError}
                    isSubmitting={upsert.isPending}
                />
            )}

            {deleteError && (
                <p data-testid="admin-path-contexts-delete-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, marginBottom: 8 }}>
                    {deleteError}
                </p>
            )}

            {state === 'loading' && (
                <p data-testid="admin-path-contexts-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>
                    {t('Loading…')}
                </p>
            )}

            {state === 'error' && (
                <div
                    data-testid="admin-path-contexts-error"
                    data-state="error"
                    role="alert"
                    style={panelStyle('var(--err)', 'var(--err)')}
                >
                    <p style={{ margin: '0 0 8px' }}>
                        {t('Failed to load path contexts.')} {pathContextsErrorMessage(query.error)}
                    </p>
                    <button type="button" data-testid="admin-path-contexts-retry" onClick={() => query.refetch()} style={ghostButtonStyle}>
                        {t('Retry')}
                    </button>
                </div>
            )}

            {state === 'empty' && (
                <p data-testid="admin-path-contexts-empty" data-state="empty" style={panelStyle('var(--panel-border)')}>
                    {t('No path contexts yet. Click')} <code>{t('+ Add path context')}</code>{' '}
                    {t('to describe a part of the corpus.')}
                </p>
            )}

            {state === 'ready' && (
                <table
                    data-testid="admin-path-contexts-table"
                    data-state="ready"
                    style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}
                >
                    <thead>
                        <tr style={{ textAlign: 'left', color: 'var(--fg-2)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em' }}>
                            <th style={cellStyle}>{t('Project')}</th>
                            <th style={cellStyle}>{t('Prefix')}</th>
                            <th style={cellStyle}>{t('Description')}</th>
                            <th style={cellStyle}>{t('Updated')}</th>
                            <th style={{ ...cellStyle, textAlign: 'right' }}>{t('Actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <PathContextRow
                                key={row.id}
                                row={row}
                                confirming={confirmingId === row.id}
                                onEdit={() => {
                                    resetFormFeedback();
                                    setCreateOpen(false);
                                    setEditing(row);
                                }}
                                onAskDelete={() => setConfirmingId(row.id)}
                                onCancelDelete={() => setConfirmingId(null)}
                                onConfirmDelete={() => handleDelete(row.id)}
                            />
                        ))}
                    </tbody>
                </table>
            )}

            <ToastHost />
        </div>
    );
}

interface PathContextRowProps {
    row: PathContext;
    confirming: boolean;
    onEdit: () => void;
    onAskDelete: () => void;
    onCancelDelete: () => void;
    onConfirmDelete: () => void;
}

function PathContextRow({ row, confirming, onEdit, onAskDelete, onCancelDelete, onConfirmDelete }: PathContextRowProps): ReactNode {
    const { t } = useUiTranslation();
    const labelValues = { prefix: row.path_prefix, project: row.project_key };
    return (
        <tr
            data-testid={`admin-path-context-row-${row.id}`}
            data-path-prefix={row.path_prefix}
            data-project-key={row.project_key}
            style={{ borderTop: '1px solid var(--panel-border, rgba(255,255,255,.06))' }}
        >
            <td style={{ ...cellStyle, color: 'var(--fg-2)' }}>{row.project_key}</td>
            <td style={{ ...cellStyle, fontFamily: 'var(--font-mono, monospace)', color: 'var(--fg-0)' }}>{row.path_prefix}</td>
            <td style={{ ...cellStyle, color: 'var(--fg-1)', whiteSpace: 'pre-wrap' }}>{row.description}</td>
            <td style={{ ...cellStyle, color: 'var(--fg-3)', whiteSpace: 'nowrap' }}>{formatUpdated(row.updated_at)}</td>
            <td style={{ ...cellStyle, textAlign: 'right', whiteSpace: 'nowrap' }}>
                {!confirming && (
                    <>
                        <button
                            type="button"
                            data-testid={`admin-path-context-row-${row.id}-edit`}
                            aria-label={t('Edit path context {prefix} in {project}', labelValues)}
                            onClick={onEdit}
                            style={smallButtonStyle()}
                        >
                            {t('Edit')}
                        </button>
                        <button
                            type="button"
                            data-testid={`admin-path-context-row-${row.id}-delete`}
                            aria-label={t('Delete path context {prefix} in {project}', labelValues)}
                            onClick={onAskDelete}
                            style={smallButtonStyle()}
                        >
                            {t('Delete')}
                        </button>
                    </>
                )}
                {confirming && (
                    <>
                        <button
                            type="button"
                            data-testid={`admin-path-context-row-${row.id}-delete-confirm`}
                            aria-label={t('Confirm deleting path context {prefix} in {project}', labelValues)}
                            onClick={onConfirmDelete}
                            style={smallButtonStyle('danger')}
                        >
                            {t('Confirm')}
                        </button>
                        <button
                            type="button"
                            data-testid={`admin-path-context-row-${row.id}-delete-cancel`}
                            onClick={onCancelDelete}
                            style={smallButtonStyle()}
                        >
                            {t('Cancel')}
                        </button>
                    </>
                )}
            </td>
        </tr>
    );
}

function pageState(
    query: { isPending: boolean; isError: boolean; fetchStatus: string },
    unavailable: boolean,
    rowCount: number,
): PageState {
    if (unavailable) return 'unavailable';
    if (query.isError) return 'error';
    if (query.isPending) return query.fetchStatus === 'idle' ? 'idle' : 'loading';
    return rowCount === 0 ? 'empty' : 'ready';
}

function fieldErrorsOf(errors: Record<string, string>): PathContextFieldErrors {
    const out: PathContextFieldErrors = {};
    for (const field of FIELDS) {
        if (errors[field]) out[field] = errors[field];
    }
    return out;
}

/** `2026-11-05T10:00:00Z` → `2026-11-05 10:00 UTC` (deterministic, no locale). */
function formatUpdated(value?: string | null): string {
    if (!value) return '—';
    const match = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/.exec(value);
    return match ? `${match[1]} ${match[2]} UTC` : value;
}

const cellStyle: CSSProperties = { padding: '8px 10px', verticalAlign: 'top' };

// The shadcn trigger carries the theme (border, background, focus ring);
// only size it to fit the header row.
const selectStyle: CSSProperties = { fontSize: 12, minWidth: 160 };

const primaryButtonStyle: CSSProperties = {
    padding: '5px 12px',
    borderRadius: 6,
    border: '1px solid var(--accent, #6366f1)',
    background: 'var(--accent, #6366f1)',
    color: 'white',
    fontSize: 12,
    cursor: 'pointer',
};

const ghostButtonStyle: CSSProperties = {
    padding: '4px 10px',
    borderRadius: 6,
    border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
    background: 'transparent',
    color: 'var(--fg-1)',
    fontSize: 11.5,
    cursor: 'pointer',
};

function panelStyle(border: string, color = 'var(--fg-3)'): CSSProperties {
    return { color, padding: 24, textAlign: 'center', border: `1px dashed ${border}`, borderRadius: 8 };
}

function smallButtonStyle(variant: 'neutral' | 'danger' = 'neutral'): CSSProperties {
    const danger = variant === 'danger';
    return {
        marginLeft: 6,
        padding: '4px 10px',
        borderRadius: 6,
        border: '1px solid ' + (danger ? 'var(--err, #c4391d)' : 'var(--panel-border, rgba(255,255,255,.12))'),
        background: danger ? 'var(--err, #c4391d)' : 'transparent',
        color: danger ? 'white' : 'var(--fg-1)',
        fontSize: 11.5,
        cursor: 'pointer',
    };
}
