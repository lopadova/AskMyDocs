import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
 * R11/R12/R29: testids follow `feature-resource-{id}-{action}`. The
 * dialog and gallery carry `role="dialog"` / `role="region"` and
 * `data-state` for E2E scenarios.
 */
export function WorkflowsList(): ReactNode {
    const qc = useQueryClient();
    const [scope, setScope] = useState<'mine' | 'shared' | 'system'>('mine');
    const [createOpen, setCreateOpen] = useState(false);
    const [createError, setCreateError] = useState<string | null>(null);
    const [suggestOpen, setSuggestOpen] = useState(false);

    const wfQuery = useQuery({
        queryKey: ['admin-workflows', scope],
        queryFn: () => adminWorkflowsApi.list(scope),
        staleTime: 30_000,
    });

    const suggestQuery = useQuery({
        queryKey: ['admin-workflows-suggestions'],
        queryFn: () => adminWorkflowsApi.suggest(),
        enabled: suggestOpen,
        staleTime: 0,
    });

    const createMutation = useMutation({
        mutationFn: (p: CreateWorkflowPayload) => adminWorkflowsApi.create(p),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['admin-workflows', scope] });
            setCreateOpen(false);
            setCreateError(null);
        },
        onError: (err: unknown) => {
            setCreateError(err instanceof Error ? err.message : 'Could not create workflow.');
        },
    });

    const fromProposalMutation = useMutation({
        mutationFn: (p: WorkflowProposal) => adminWorkflowsApi.fromProposal(p),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-workflows', scope] }),
    });

    const hideMutation = useMutation({
        mutationFn: (id: number) => adminWorkflowsApi.hide(id),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-workflows', scope] }),
    });

    const workflows = wfQuery.data ?? [];
    let dataState: 'loading' | 'ready' | 'error' | 'empty' = 'loading';
    if (wfQuery.isLoading) dataState = 'loading';
    else if (wfQuery.isError) dataState = 'error';
    else if (workflows.length === 0) dataState = 'empty';
    else dataState = 'ready';

    return (
        <div data-testid="admin-workflows" data-state={dataState}>
            <header style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 16 }}>
                <h2 style={{ margin: 0, flex: 1 }}>Workflows</h2>
                <button
                    type="button"
                    data-testid="admin-workflows-create"
                    onClick={() => setCreateOpen(true)}
                    style={{ padding: '6px 12px', borderRadius: 4, border: '1px solid var(--hairline)' }}
                >
                    + New workflow
                </button>
                <button
                    type="button"
                    data-testid="admin-workflows-suggest"
                    onClick={() => setSuggestOpen(true)}
                    style={{ padding: '6px 12px', borderRadius: 4, border: '1px solid var(--accent)', background: 'var(--accent-soft, transparent)' }}
                >
                    Get suggestions from my data
                </button>
            </header>

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
                                {scope === 'mine' && (
                                    <button
                                        type="button"
                                        data-testid={`admin-workflow-card-${w.id}-hide`}
                                        onClick={() => hideMutation.mutate(w.id)}
                                        style={{ fontSize: 12, background: 'none', border: '1px solid var(--hairline)', padding: '3px 8px', borderRadius: 4 }}
                                    >
                                        Hide
                                    </button>
                                )}
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
                    savingFor={fromProposalMutation.variables?.title ?? null}
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
                            data-testid="admin-workflow-create-type-assistant"
                            checked={type === 'assistant'}
                            onChange={() => setType('assistant')}
                        />{' '}
                        Assistant
                    </label>
                    <label>
                        <input
                            type="radio"
                            data-testid="admin-workflow-create-type-tabular"
                            checked={type === 'tabular'}
                            onChange={() => setType('tabular')}
                        />{' '}
                        Tabular
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
                                columns_config: type === 'tabular' ? [] : undefined,
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

function SuggestionsGallery({ data, isLoading, isError, onClose, onSave }: SuggestProps): ReactNode {
    return (
        <div
            data-testid="admin-workflow-suggestions-gallery"
            role="region"
            aria-label="Workflow suggestions"
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
                    <h3 style={{ marginTop: 0 }}>AI-suggested workflows</h3>
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
                            <li
                                key={idx}
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
                                    onClick={() => onSave(p)}
                                    style={{ marginTop: 6, padding: '4px 10px', borderRadius: 4, border: '1px solid var(--accent)' }}
                                >
                                    Save this
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
