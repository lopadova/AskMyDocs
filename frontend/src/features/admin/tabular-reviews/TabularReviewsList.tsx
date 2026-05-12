import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '../../../lib/auth-store';
import { adminTabularReviewsApi, type ColumnConfig, type CreateReviewPayload, type FormatType, type TabularReview } from './admin-tabular-reviews.api';

/**
 * v4.7/W3 — Admin Tabular Reviews list view.
 *
 * Renders the paginated tabular_reviews catalogue with a Create button
 * that opens an inline form. Click a row to navigate to the show page.
 *
 * R11: every actionable element has `data-testid`.
 * R14: API errors surface as inline error messages, not silent.
 * R18: project_key dropdown is omitted here (free text in create form)
 *      to keep this PR's surface bounded; W3.X polish wires the
 *      `/api/admin/projects/keys` endpoint.
 */
export function TabularReviewsList(): ReactNode {
    const qc = useQueryClient();
    const [createOpen, setCreateOpen] = useState(false);
    const [createError, setCreateError] = useState<string | null>(null);
    const [activeId, setActiveId] = useState<number | null>(null);
    // BE Gate `viewTabularReviews` admits viewer for READ-ONLY; mutation
    // routes also enforce `denyMutationForViewer()`. Mirror that client-
    // side so a viewer never sees a button that 403s.
    const { roles } = useAuthStore();
    const canMutate = roles.includes('admin') || roles.includes('super-admin');

    const reviewsQuery = useQuery({
        queryKey: ['admin-tabular-reviews'],
        queryFn: () => adminTabularReviewsApi.list(),
        staleTime: 30_000,
    });

    const createMutation = useMutation({
        mutationFn: (payload: CreateReviewPayload) => adminTabularReviewsApi.create(payload),
        onSuccess: (created) => {
            qc.invalidateQueries({ queryKey: ['admin-tabular-reviews'] });
            setCreateOpen(false);
            setCreateError(null);
            setActiveId(created.id);
        },
        onError: (err: unknown) => {
            setCreateError(err instanceof Error ? err.message : 'Could not create review.');
        },
    });

    const [mutationError, setMutationError] = useState<string | null>(null);
    const deleteMutation = useMutation({
        mutationFn: (id: number) => adminTabularReviewsApi.destroy(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-tabular-reviews'] });
            setMutationError(null);
        },
        onError: (err: unknown) => {
            setMutationError(err instanceof Error ? err.message : 'Could not delete review.');
        },
    });

    const reviews = reviewsQuery.data?.data ?? [];

    let dataState: 'loading' | 'ready' | 'error' | 'empty' = 'loading';
    if (reviewsQuery.isLoading) dataState = 'loading';
    else if (reviewsQuery.isError) dataState = 'error';
    else if (reviews.length === 0) dataState = 'empty';
    else dataState = 'ready';

    if (activeId !== null) {
        return (
            <TabularReviewShow
                id={activeId}
                onBack={() => setActiveId(null)}
            />
        );
    }

    return (
        <div
            data-testid="admin-tabular-reviews"
            data-state={dataState}
            aria-busy={dataState === 'loading'}
        >
            <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 }}>
                <h2 style={{ fontSize: 18, margin: 0 }}>Tabular Reviews</h2>
                {canMutate && (
                    <button
                        type="button"
                        data-testid="admin-tabular-reviews-create"
                        onClick={() => setCreateOpen(true)}
                        style={{
                            padding: '7px 14px',
                            borderRadius: 6,
                            border: '1px solid var(--hairline)',
                            background: 'var(--bg-2)',
                            cursor: 'pointer',
                        }}
                    >
                        + New review
                    </button>
                )}
            </header>

            {dataState === 'loading' && <p data-testid="admin-tabular-reviews-loading">Loading…</p>}
            {dataState === 'error' && (
                <p data-testid="admin-tabular-reviews-error">
                    Failed to load reviews: {reviewsQuery.error instanceof Error ? reviewsQuery.error.message : 'unknown error'}
                </p>
            )}
            {dataState === 'empty' && <p data-testid="admin-tabular-reviews-empty">No tabular reviews yet. Create one to get started.</p>}
            {mutationError && (
                <p
                    data-testid="admin-tabular-reviews-mutation-error"
                    role="alert"
                    style={{ color: 'var(--danger, #c00)', marginBottom: 12 }}
                >
                    {mutationError}
                </p>
            )}
            {dataState === 'ready' && (
                <table
                    data-testid="admin-tabular-reviews-table"
                    style={{ width: '100%', borderCollapse: 'collapse' }}
                >
                    <thead>
                        <tr style={{ textAlign: 'left', borderBottom: '1px solid var(--hairline)' }}>
                            <th style={{ padding: '8px 10px' }}>Title</th>
                            <th style={{ padding: '8px 10px' }}>Project</th>
                            <th style={{ padding: '8px 10px' }}>Columns</th>
                            <th style={{ padding: '8px 10px' }}>Updated</th>
                            <th style={{ padding: '8px 10px' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {reviews.map((r: TabularReview) => (
                            <tr
                                key={r.id}
                                data-testid={`admin-tabular-review-row-${r.id}`}
                                style={{ borderBottom: '1px solid var(--hairline)' }}
                            >
                                <td style={{ padding: '8px 10px' }}>
                                    <button
                                        type="button"
                                        data-testid={`admin-tabular-review-row-${r.id}-open`}
                                        onClick={() => setActiveId(r.id)}
                                        style={{ background: 'none', border: 'none', color: 'var(--accent)', cursor: 'pointer', padding: 0 }}
                                    >
                                        {r.title}
                                    </button>
                                </td>
                                <td style={{ padding: '8px 10px' }}>{r.project_key}</td>
                                <td style={{ padding: '8px 10px' }}>{r.columns_config?.length ?? 0}</td>
                                <td style={{ padding: '8px 10px' }}>{r.updated_at ?? '—'}</td>
                                <td style={{ padding: '8px 10px' }}>
                                    {canMutate && (
                                        <button
                                            type="button"
                                            data-testid={`admin-tabular-review-row-${r.id}-delete`}
                                            disabled={deleteMutation.isPending}
                                            onClick={() => deleteMutation.mutate(r.id)}
                                            style={{ background: 'none', border: 'none', color: 'var(--danger, #c00)', cursor: 'pointer' }}
                                        >
                                            Delete
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {createOpen && (
                <CreateReviewDialog
                    onClose={() => {
                        setCreateOpen(false);
                        setCreateError(null);
                    }}
                    onSubmit={(payload) => createMutation.mutate(payload)}
                    submitting={createMutation.isPending}
                    error={createError}
                />
            )}
        </div>
    );
}

interface DialogProps {
    onClose: () => void;
    onSubmit: (payload: CreateReviewPayload) => void;
    submitting: boolean;
    error: string | null;
}

function CreateReviewDialog({ onClose, onSubmit, submitting, error }: DialogProps): ReactNode {
    const [title, setTitle] = useState('');
    const [projectKey, setProjectKey] = useState('');
    const [columns, setColumns] = useState<ColumnConfig[]>([{ name: '', prompt: '', format: 'text' }]);

    const updateColumn = (i: number, patch: Partial<ColumnConfig>) => {
        setColumns(columns.map((c, idx) => (idx === i ? { ...c, ...patch } : c)));
    };

    const addColumn = () => setColumns([...columns, { name: '', prompt: '', format: 'text' }]);
    const removeColumn = (i: number) => setColumns(columns.filter((_, idx) => idx !== i));

    return (
        <div
            data-testid="admin-tabular-review-create-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="create-tabular-review-title"
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0,0,0,0.4)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 50,
            }}
        >
            <div
                style={{
                    background: 'var(--bg-1)',
                    padding: 24,
                    borderRadius: 8,
                    width: 'min(640px, 90vw)',
                    maxHeight: '85vh',
                    overflow: 'auto',
                }}
            >
                <h3 id="create-tabular-review-title" style={{ marginTop: 0 }}>New Tabular Review</h3>

                <label style={{ display: 'block', marginBottom: 6 }} htmlFor="create-tabular-review-title-input">
                    Title
                </label>
                <input
                    id="create-tabular-review-title-input"
                    data-testid="admin-tabular-review-create-title"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    style={{ width: '100%', padding: 8, marginBottom: 12 }}
                />

                <label style={{ display: 'block', marginBottom: 6 }} htmlFor="create-tabular-review-project-input">
                    Project key
                </label>
                <input
                    id="create-tabular-review-project-input"
                    data-testid="admin-tabular-review-create-project"
                    value={projectKey}
                    onChange={(e) => setProjectKey(e.target.value)}
                    style={{ width: '100%', padding: 8, marginBottom: 12 }}
                />

                <div style={{ marginBottom: 12 }}>
                    <strong>Columns</strong>
                    {columns.map((c, i) => (
                        <div
                            key={i}
                            data-testid={`admin-tabular-review-create-column-${i}`}
                            style={{ border: '1px solid var(--hairline)', borderRadius: 6, padding: 10, marginTop: 8 }}
                        >
                            <input
                                placeholder="Name"
                                aria-label={`Column ${i + 1} name`}
                                data-testid={`admin-tabular-review-create-column-${i}-name`}
                                value={c.name}
                                onChange={(e) => updateColumn(i, { name: e.target.value })}
                                style={{ width: '100%', padding: 6, marginBottom: 4 }}
                            />
                            <input
                                placeholder="Extraction prompt"
                                aria-label={`Column ${i + 1} prompt`}
                                data-testid={`admin-tabular-review-create-column-${i}-prompt`}
                                value={c.prompt ?? ''}
                                onChange={(e) => updateColumn(i, { prompt: e.target.value })}
                                style={{ width: '100%', padding: 6, marginBottom: 4 }}
                            />
                            <select
                                aria-label={`Column ${i + 1} format`}
                                data-testid={`admin-tabular-review-create-column-${i}-format`}
                                value={c.format}
                                onChange={(e) => updateColumn(i, { format: e.target.value as FormatType })}
                                style={{ width: '100%', padding: 6 }}
                            >
                                {/*
                                 * Full FormatType domain — keep in sync with
                                 * app/Support/TabularReview/FormatType.php
                                 * (Copilot iter 3 caught a 7-of-17 subset
                                 * that prevented creating most valid review
                                 * configs from the admin UI). 17 cases as
                                 * of v4.7 GA.
                                 */}
                                <option value="text">text</option>
                                <option value="free_text">free_text</option>
                                <option value="number">number</option>
                                <option value="currency">currency</option>
                                <option value="percent">percent</option>
                                <option value="date">date</option>
                                <option value="duration">duration</option>
                                <option value="person">person</option>
                                <option value="entity">entity</option>
                                <option value="boolean">boolean</option>
                                <option value="choice">choice</option>
                                <option value="enum">enum</option>
                                <option value="enum_status">enum_status</option>
                                <option value="flag">flag</option>
                                <option value="list">list</option>
                                <option value="json_path">json_path</option>
                            </select>
                            {columns.length > 1 && (
                                <button
                                    type="button"
                                    data-testid={`admin-tabular-review-create-column-${i}-remove`}
                                    onClick={() => removeColumn(i)}
                                    style={{ marginTop: 6, background: 'none', border: 'none', color: 'var(--danger, #c00)', cursor: 'pointer' }}
                                >
                                    Remove column
                                </button>
                            )}
                        </div>
                    ))}
                    <button
                        type="button"
                        data-testid="admin-tabular-review-create-add-column"
                        onClick={addColumn}
                        style={{ marginTop: 8, padding: '5px 10px', borderRadius: 4, border: '1px solid var(--hairline)', background: 'var(--bg-2)' }}
                    >
                        + Add column
                    </button>
                </div>

                {error && (
                    <p data-testid="admin-tabular-review-create-error" style={{ color: 'var(--danger, #c00)' }}>
                        {error}
                    </p>
                )}

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
                    <button type="button" data-testid="admin-tabular-review-create-cancel" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        data-testid="admin-tabular-review-create-submit"
                        disabled={submitting || title.trim() === '' || projectKey.trim() === ''}
                        onClick={() =>
                            onSubmit({
                                title: title.trim(),
                                project_key: projectKey.trim(),
                                columns_config: columns,
                            })
                        }
                        style={{
                            padding: '6px 14px',
                            borderRadius: 4,
                            border: '1px solid var(--accent)',
                            background: 'var(--accent)',
                            color: 'white',
                            cursor: 'pointer',
                        }}
                    >
                        {submitting ? 'Creating…' : 'Create'}
                    </button>
                </div>
            </div>
        </div>
    );
}

interface ShowProps {
    id: number;
    onBack: () => void;
}

function TabularReviewShow({ id, onBack }: ShowProps): ReactNode {
    const qc = useQueryClient();
    const [showMutationError, setShowMutationError] = useState<string | null>(null);
    const { roles } = useAuthStore();
    const canMutate = roles.includes('admin') || roles.includes('super-admin');
    const showQuery = useQuery({
        queryKey: ['admin-tabular-review', id],
        queryFn: () => adminTabularReviewsApi.show(id),
    });

    const generateMutation = useMutation({
        mutationFn: (max?: number) => adminTabularReviewsApi.generate(id, max),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-tabular-review', id] });
            setShowMutationError(null);
        },
        onError: (err: unknown) => {
            setShowMutationError(err instanceof Error ? err.message : 'Generation failed.');
        },
    });

    const clearMutation = useMutation({
        mutationFn: () => adminTabularReviewsApi.clearCells(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-tabular-review', id] });
            setShowMutationError(null);
        },
        onError: (err: unknown) => {
            setShowMutationError(err instanceof Error ? err.message : 'Clear failed.');
        },
    });

    if (showQuery.isLoading) {
        return (
            <div data-testid="admin-tabular-review-show" data-state="loading">
                Loading…
            </div>
        );
    }
    if (showQuery.isError || !showQuery.data) {
        return (
            <div data-testid="admin-tabular-review-show" data-state="error">
                <button type="button" onClick={onBack}>← Back</button>
                <p>Could not load review: {showQuery.error instanceof Error ? showQuery.error.message : 'unknown'}</p>
            </div>
        );
    }

    const { data: review, cells } = showQuery.data;
    const columns = review.columns_config ?? [];
    const cellsByDoc = new Map<number, Map<number, typeof cells[number]>>();
    for (const c of cells) {
        if (!cellsByDoc.has(c.document_id)) cellsByDoc.set(c.document_id, new Map());
        cellsByDoc.get(c.document_id)!.set(c.column_index, c);
    }

    return (
        <div data-testid="admin-tabular-review-show" data-state="ready" data-review-id={review.id}>
            <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
                <div>
                    <button type="button" data-testid="admin-tabular-review-show-back" onClick={onBack}>
                        ← Back
                    </button>
                    <h2 style={{ display: 'inline-block', marginLeft: 12 }}>{review.title}</h2>
                    <span style={{ marginLeft: 12, color: 'var(--fg-3)' }}>project: {review.project_key}</span>
                </div>
                {canMutate && (
                    <div style={{ display: 'flex', gap: 10 }}>
                        <button
                            type="button"
                            data-testid="admin-tabular-review-show-generate"
                            onClick={() => generateMutation.mutate(undefined)}
                            disabled={generateMutation.isPending}
                        >
                            {generateMutation.isPending ? 'Generating…' : 'Generate cells'}
                        </button>
                        <button
                            type="button"
                            data-testid="admin-tabular-review-show-clear"
                            onClick={() => clearMutation.mutate()}
                            disabled={clearMutation.isPending}
                        >
                            Clear cells
                        </button>
                    </div>
                )}
            </header>

            {showMutationError && (
                <p
                    data-testid="admin-tabular-review-show-mutation-error"
                    role="alert"
                    style={{ color: 'var(--danger, #c00)', marginBottom: 12 }}
                >
                    {showMutationError}
                </p>
            )}

            {cells.length === 0 ? (
                <p data-testid="admin-tabular-review-show-empty">
                    No cells yet. Press “Generate cells” to run the extractor.
                </p>
            ) : (
                <table data-testid="admin-tabular-review-show-grid" style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <thead>
                        <tr>
                            <th style={{ borderBottom: '1px solid var(--hairline)', padding: '6px 8px', textAlign: 'left' }}>Document</th>
                            {columns.map((c, i) => (
                                <th
                                    key={i}
                                    style={{ borderBottom: '1px solid var(--hairline)', padding: '6px 8px', textAlign: 'left' }}
                                    data-testid={`admin-tabular-review-show-col-${i}`}
                                >
                                    {c.name || `Column ${i + 1}`}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {Array.from(cellsByDoc.entries()).map(([docId, byCol]) => (
                            <tr key={docId} data-testid={`admin-tabular-review-show-row-${docId}`}>
                                <td style={{ padding: '6px 8px', borderBottom: '1px solid var(--hairline)' }}>#{docId}</td>
                                {columns.map((_, i) => {
                                    const cell = byCol.get(i);
                                    return (
                                        <td
                                            key={i}
                                            data-testid={`admin-tabular-review-show-cell-${docId}-${i}`}
                                            data-flag={cell?.flag ?? 'empty'}
                                            style={{
                                                padding: '6px 8px',
                                                borderBottom: '1px solid var(--hairline)',
                                                background: cellFlagBg(cell?.flag),
                                            }}
                                            title={cell?.content?.reasoning ?? ''}
                                        >
                                            {cell?.content?.summary ?? '—'}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

function cellFlagBg(flag?: string): string {
    switch (flag) {
        case 'green':
            return 'rgba(0, 160, 0, 0.10)';
        case 'yellow':
            return 'rgba(220, 180, 0, 0.10)';
        case 'red':
            return 'rgba(200, 0, 0, 0.10)';
        case 'grey':
            return 'rgba(120, 120, 120, 0.06)';
        default:
            return 'transparent';
    }
}
