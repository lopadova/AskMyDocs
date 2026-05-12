import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '../../../lib/auth-store';
import {
    adminWorkflowsApi,
    type CreateWorkflowPayload,
    type Workflow,
    type WorkflowProposal,
} from './admin-workflows.api';

/**
 * v4.7/W3 — Admin Workflows list view.
 *
 * Three scope tabs: mine / shared / system. A "Get suggestions" button
 * calls the W2 AI-suggester endpoint and renders the proposals as
 * cards; each card has a Save-this button that calls /from-proposal.
 *
 * R11/R12/R29: testids follow `feature-resource-{id}-{action}`. Both
 * the create modal and the suggestions gallery carry `role="dialog"`
 * + `aria-modal="true"` + `data-state` for E2E scenarios — the
 * earlier mention of `role="region"` was stale doc drift (Copilot
 * iter 7).
 */
export function WorkflowsList(): ReactNode {
    const qc = useQueryClient();
    const [scope, setScope] = useState<'mine' | 'shared' | 'system'>('mine');
    const [createOpen, setCreateOpen] = useState(false);
    const [createError, setCreateError] = useState<string | null>(null);
    const [mutationError, setMutationError] = useState<string | null>(null);
    const [suggestOpen, setSuggestOpen] = useState(false);

    const { user, roles } = useAuthStore();
    const myUserId = user?.id ?? null;
    // The `suggest` endpoint is cost-protected server-side
    // (`assertCanSuggest()` admits only admin/super-admin). We mirror
    // that on the FE so a viewer never sees a misleading button.
    const canSuggest = roles.includes('admin') || roles.includes('super-admin');
    // `canCreate` gates the "+ New workflow" button. NOTE: this is NOT
    // a blanket "viewers cannot mutate" — viewers ARE allowed to hide
    // a workflow from their personal catalogue (BE Gate
    // `hideWorkflow` admits any authenticated role; the hide button
    // below is rendered for everyone). The boolean is scoped to the
    // create + suggest flows, which the BE restricts to admin /
    // super-admin via `denyMutationForViewer()` / `assertCanSuggest()`.
    // Copilot iter 7 caught the doc drift on the previous
    // `canMutate` naming.
    const canCreate = canSuggest;

    const wfQuery = useQuery({
        // Fetch the same superset for shared+system (include_shared=1);
        // the client splits Mine vs the rest below. Caching keyed on
        // include_shared so the Mine tab gets its own cache entry.
        queryKey: ['admin-workflows', scope === 'mine' ? 'mine' : 'shared-superset'],
        queryFn: () => adminWorkflowsApi.list(scope),
        staleTime: 30_000,
    });

    const suggestQuery = useQuery({
        queryKey: ['admin-workflows-suggestions'],
        queryFn: () => adminWorkflowsApi.suggest(),
        enabled: suggestOpen && canSuggest,
        staleTime: 0,
    });

    const createMutation = useMutation({
        mutationFn: (p: CreateWorkflowPayload) => adminWorkflowsApi.create(p),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-workflows'] });
            setCreateOpen(false);
            setCreateError(null);
            setScope('mine');
        },
        onError: (err: unknown) => {
            setCreateError(err instanceof Error ? err.message : 'Could not create workflow.');
        },
    });

    const fromProposalMutation = useMutation({
        mutationFn: (p: WorkflowProposal) => adminWorkflowsApi.fromProposal(p),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-workflows'] });
            setScope('mine');
            setMutationError(null);
        },
        onError: (err: unknown) => {
            setMutationError(err instanceof Error ? err.message : 'Could not save proposal.');
        },
    });

    const hideMutation = useMutation({
        mutationFn: (id: number) => adminWorkflowsApi.hide(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-workflows'] });
            setMutationError(null);
        },
        onError: (err: unknown) => {
            setMutationError(err instanceof Error ? err.message : 'Could not hide workflow.');
        },
    });

    const allWorkflows = wfQuery.data ?? [];
    // Client-side split — see admin-workflows.api.ts list() rationale.
    const workflows = useMemo(() => {
        if (scope === 'mine') return allWorkflows;
        if (scope === 'system') return allWorkflows.filter((w) => w.is_system === true);
        // shared = NOT mine AND NOT system
        return allWorkflows.filter((w) => w.is_system !== true && w.user_id !== myUserId);
    }, [allWorkflows, scope, myUserId]);

    let dataState: 'loading' | 'ready' | 'error' | 'empty' = 'loading';
    if (wfQuery.isLoading) dataState = 'loading';
    else if (wfQuery.isError) dataState = 'error';
    else if (workflows.length === 0) dataState = 'empty';
    else dataState = 'ready';

    return (
        <div
            data-testid="admin-workflows"
            data-state={dataState}
            aria-busy={dataState === 'loading'}
        >
            <header style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 16 }}>
                <h2 style={{ margin: 0, flex: 1 }}>Workflows</h2>
                {canCreate && (
                    <button
                        type="button"
                        data-testid="admin-workflows-create"
                        onClick={() => setCreateOpen(true)}
                        style={{ padding: '6px 12px', borderRadius: 4, border: '1px solid var(--hairline)' }}
                    >
                        + New workflow
                    </button>
                )}
                {canSuggest && (
                    <button
                        type="button"
                        data-testid="admin-workflows-suggest"
                        onClick={() => setSuggestOpen(true)}
                        style={{ padding: '6px 12px', borderRadius: 4, border: '1px solid var(--accent)', background: 'var(--accent-soft, transparent)' }}
                    >
                        Get suggestions from my data
                    </button>
                )}
            </header>

            {mutationError && (
                <p
                    data-testid="admin-workflows-mutation-error"
                    role="alert"
                    style={{ color: 'var(--danger, #c00)', marginBottom: 12 }}
                >
                    {mutationError}
                </p>
            )}

            <nav aria-label="Workflow scope" style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
                {(['mine', 'shared', 'system'] as const).map((s) => (
                    <button
                        key={s}
                        type="button"
                        data-testid={`admin-workflows-scope-${s}`}
                        data-active={scope === s ? 'true' : 'false'}
                        onClick={() => setScope(s)}
                        style={{
                            padding: '6px 12px',
                            borderRadius: 4,
                            border: '1px solid var(--hairline)',
                            background: scope === s ? 'var(--bg-2)' : 'transparent',
                            cursor: 'pointer',
                        }}
                    >
                        {s === 'mine' ? 'Mine' : s === 'shared' ? 'Shared with me' : 'System'}
                    </button>
                ))}
            </nav>

            {dataState === 'loading' && <p data-testid="admin-workflows-loading">Loading…</p>}
            {dataState === 'error' && (
                <p data-testid="admin-workflows-error">
                    Failed to load workflows: {wfQuery.error instanceof Error ? wfQuery.error.message : 'unknown error'}
                </p>
            )}
            {dataState === 'empty' && (
                <p data-testid="admin-workflows-empty">No workflows in this scope yet.</p>
            )}
            {dataState === 'ready' && (
                <ul
                    data-testid="admin-workflows-list"
                    style={{
                        listStyle: 'none',
                        margin: 0,
                        padding: 0,
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
                        gap: 12,
                    }}
                >
                    {workflows.map((w: Workflow) => (
                        <li
                            key={w.id}
                            data-testid={`admin-workflow-card-${w.id}`}
                            style={{ border: '1px solid var(--hairline)', borderRadius: 6, padding: 12 }}
                        >
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <strong style={{ flex: 1 }}>{w.title}</strong>
                                <span
                                    data-testid={`admin-workflow-card-${w.id}-type`}
                                    style={{
                                        fontSize: 11,
                                        padding: '2px 8px',
                                        borderRadius: 9999,
                                        background: w.type === 'tabular' ? 'rgba(0, 150, 200, 0.15)' : 'rgba(150, 100, 250, 0.15)',
                                    }}
                                >
                                    {w.type}
                                </span>
                            </div>
                            {w.practice && (
                                <span style={{ fontSize: 11, color: 'var(--fg-3)' }}>practice: {w.practice}</span>
                            )}
                            <div style={{ marginTop: 8, display: 'flex', gap: 8 }}>
                                {/* Hide hits a per-user preference table; BE admits any role for this action, so the button is always rendered. */}
                                <button
                                    type="button"
                                    data-testid={`admin-workflow-card-${w.id}-hide`}
                                    disabled={hideMutation.isPending}
                                    onClick={() => hideMutation.mutate(w.id)}
                                    style={{ fontSize: 12, background: 'none', border: '1px solid var(--hairline)', padding: '3px 8px', borderRadius: 4 }}
                                >
                                    Hide
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {createOpen && (
                <CreateWorkflowDialog
                    onClose={() => {
                        setCreateOpen(false);
                        setCreateError(null);
                    }}
                    onSubmit={(p) => createMutation.mutate(p)}
                    submitting={createMutation.isPending}
                    error={createError}
                />
            )}

            {suggestOpen && (
                <SuggestionsGallery
                    data={suggestQuery.data ?? []}
                    isLoading={suggestQuery.isLoading}
                    isError={suggestQuery.isError}
                    onClose={() => setSuggestOpen(false)}
                    onSave={(p) => fromProposalMutation.mutate(p)}
                    savingFor={fromProposalMutation.isPending ? fromProposalMutation.variables?.title ?? null : null}
                />
            )}
        </div>
    );
}

interface CreateProps {
    onClose: () => void;
    onSubmit: (payload: CreateWorkflowPayload) => void;
    submitting: boolean;
    error: string | null;
}

function CreateWorkflowDialog({ onClose, onSubmit, submitting, error }: CreateProps): ReactNode {
    const [title, setTitle] = useState('');
    const [type, setType] = useState<'assistant' | 'tabular'>('assistant');
    const [promptMd, setPromptMd] = useState('');
    const [practice, setPractice] = useState('');

    return (
        <div
            data-testid="admin-workflow-create-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="create-wf-title"
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
            <div style={{ background: 'var(--bg-1)', padding: 24, borderRadius: 8, width: 'min(560px, 90vw)' }}>
                <h3 id="create-wf-title" style={{ marginTop: 0 }}>New workflow</h3>

                <label htmlFor="wf-title-input" style={{ display: 'block', marginBottom: 4 }}>
                    Title
                </label>
                <input
                    id="wf-title-input"
                    data-testid="admin-workflow-create-title"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    style={{ width: '100%', padding: 8, marginBottom: 12 }}
                />

                <fieldset style={{ marginBottom: 12, border: '1px solid var(--hairline)', padding: 8, borderRadius: 4 }}>
                    <legend>Type</legend>
                    <label style={{ marginRight: 16 }}>
                        <input
                            type="radio"
                            name="workflow-type"
                            data-testid="admin-workflow-create-type-assistant"
                            checked={type === 'assistant'}
                            onChange={() => setType('assistant')}
                        />{' '}
                        Assistant
                    </label>
                    <label title="Tabular workflows require a columns-config builder — coming in v4.7.x">
                        <input
                            type="radio"
                            name="workflow-type"
                            data-testid="admin-workflow-create-type-tabular"
                            disabled
                        />{' '}
                        Tabular <span style={{ fontSize: 11, color: 'var(--fg-3)' }}>(v4.7.x)</span>
                    </label>
                </fieldset>

                <label htmlFor="wf-prompt-input" style={{ display: 'block', marginBottom: 4 }}>
                    Prompt (markdown)
                </label>
                <textarea
                    id="wf-prompt-input"
                    data-testid="admin-workflow-create-prompt"
                    value={promptMd}
                    onChange={(e) => setPromptMd(e.target.value)}
                    rows={6}
                    style={{ width: '100%', padding: 8, marginBottom: 12, fontFamily: 'monospace' }}
                />

                <label htmlFor="wf-practice-input" style={{ display: 'block', marginBottom: 4 }}>
                    Practice (optional)
                </label>
                <input
                    id="wf-practice-input"
                    data-testid="admin-workflow-create-practice"
                    value={practice}
                    onChange={(e) => setPractice(e.target.value)}
                    style={{ width: '100%', padding: 8, marginBottom: 12 }}
                />

                {error && (
                    <p data-testid="admin-workflow-create-error" style={{ color: 'var(--danger, #c00)' }}>
                        {error}
                    </p>
                )}

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 12 }}>
                    <button type="button" data-testid="admin-workflow-create-cancel" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        data-testid="admin-workflow-create-submit"
                        disabled={submitting || title.trim() === '' || promptMd.trim() === ''}
                        onClick={() =>
                            onSubmit({
                                title: title.trim(),
                                type,
                                prompt_md: promptMd,
                                practice: practice || undefined,
                                // Tabular workflows need a
                                // columns-config builder — disabled
                                // in this GA shell. The radio above
                                // is disabled so `type` is always
                                // `assistant` here; we only send the
                                // columns_config key on tabular submits
                                // (which the disabled radio prevents).
                                columns_config: undefined,
                            })
                        }
                        style={{
                            padding: '6px 14px',
                            borderRadius: 4,
                            border: '1px solid var(--accent)',
                            background: 'var(--accent)',
                            color: 'white',
                        }}
                    >
                        {submitting ? 'Creating…' : 'Create'}
                    </button>
                </div>
            </div>
        </div>
    );
}

interface SuggestProps {
    data: WorkflowProposal[];
    isLoading: boolean;
    isError: boolean;
    onClose: () => void;
    onSave: (p: WorkflowProposal) => void;
    savingFor: string | null;
}

function SuggestionsGallery({ data, isLoading, isError, onClose, onSave, savingFor }: SuggestProps): ReactNode {
    return (
        <div
            data-testid="admin-workflow-suggestions-gallery"
            role="dialog"
            aria-modal="true"
            aria-labelledby="admin-workflow-suggestions-title"
            data-state={isLoading ? 'loading' : isError ? 'error' : 'ready'}
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
            <div style={{ background: 'var(--bg-1)', padding: 24, borderRadius: 8, width: 'min(720px, 92vw)', maxHeight: '90vh', overflow: 'auto' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <h3 id="admin-workflow-suggestions-title" style={{ marginTop: 0 }}>AI-suggested workflows</h3>
                    <button type="button" data-testid="admin-workflow-suggestions-close" onClick={onClose}>
                        Close
                    </button>
                </div>

                {isLoading && <p data-testid="admin-workflow-suggestions-loading">Analyzing your knowledge base…</p>}
                {isError && (
                    <p data-testid="admin-workflow-suggestions-error">Could not load suggestions. Try again.</p>
                )}
                {!isLoading && !isError && data.length === 0 && (
                    <p data-testid="admin-workflow-suggestions-empty">No suggestions available yet.</p>
                )}

                {!isLoading && !isError && data.length > 0 && (
                    <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 12 }}>
                        {data.map((p, idx) => (
                            // Stable React key derived from proposal identity
                            // (title + type) PLUS the array index as a
                            // tie-breaker — the suggester is an LLM and can
                            // legitimately emit two proposals with the same
                            // (title, type) on the same fetch. The data-testid
                            // hierarchy is kept on idx alone so existing E2E
                            // selectors (`admin-workflow-suggestion-${idx}`)
                            // survive. Copilot iter 5 flagged index-as-key
                            // risk; iter 8 flagged the duplicate-(title,type)
                            // risk — the `::${idx}` suffix addresses both.
                            <li
                                key={`${p.title}::${p.type}::${idx}`}
                                data-testid={`admin-workflow-suggestion-${idx}`}
                                style={{ border: '1px solid var(--hairline)', borderRadius: 6, padding: 12 }}
                            >
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                    <strong style={{ flex: 1 }}>{p.title}</strong>
                                    <span style={{ fontSize: 11, color: 'var(--fg-3)' }}>{p.type}</span>
                                </div>
                                {p.rationale && (
                                    <p style={{ fontSize: 12, color: 'var(--fg-3)', margin: '6px 0' }}>{p.rationale}</p>
                                )}
                                <button
                                    type="button"
                                    data-testid={`admin-workflow-suggestion-${idx}-save`}
                                    disabled={savingFor !== null}
                                    onClick={() => onSave(p)}
                                    style={{ marginTop: 6, padding: '4px 10px', borderRadius: 4, border: '1px solid var(--accent)' }}
                                >
                                    {savingFor === p.title ? 'Saving…' : 'Save this'}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
