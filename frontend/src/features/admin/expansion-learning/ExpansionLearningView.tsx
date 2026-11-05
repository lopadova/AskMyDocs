import { Fragment, useMemo, useState, type CSSProperties, type ReactNode } from 'react';
import { SelectField, SelectOption } from '@/components/ui/select-field';
import { useUiTranslation } from '../../../lib/ui-locale';
import { useKbProjects } from '../kb/kb-tree.api';
import { ToastHost, useToast } from '../shared/Toast';
import { ExpansionLearningTrainingForm } from './ExpansionLearningTrainingForm';
import {
    expansionLearningErrorMessage,
    isExpansionLearningDisabled,
    useAnalyzeExpansion,
    useApplyTuning,
    useExpansionLearningStatus,
    usePromoteModel,
    useRefreshModel,
    useRejectTuning,
    useRetireModel,
    type ExpansionLearningStatus,
    type ExpansionModel,
    type ExpansionTuning,
    type ExpansionWeights,
    type FewShotExample,
} from './expansion-learning.api';

/**
 * Admin "Expansion learning" screen — the query-expansion learning loop.
 *
 * The backend records what each query rewrite (lex / vec / hyde) actually
 * contributed to the results; "Analyze" turns that evidence into a proposed
 * tuning (per-type weights + few-shot examples) that a human applies or
 * rejects, and an optional training run produces a candidate model that a
 * super-admin promotes.
 *
 * R11/R29: stable `admin-expansion-learning-*` testids, observable
 * `data-state` on the page (`idle|loading|ready|empty|error|unavailable`)
 * + `aria-busy`.
 * R14: a failed status read is `error`, never `empty`; every refused action
 * shows the server's message.
 * R17: multi-row proposal rows use keyed Fragments.
 * R18: project choices come from the projects API.
 * R43: with `KB_EXPANSION_LEARNING_ENABLED=false` every endpoint answers
 * 404 `expansion_learning_disabled`; the page shows ONE "unavailable" panel.
 *
 * Model-produced text (few-shot rewrites, evidence) is rendered as plain
 * text through React — never as HTML.
 */

type PageState = 'idle' | 'loading' | 'ready' | 'empty' | 'error' | 'unavailable';

type PendingConfirm =
    | { kind: 'apply' | 'reject' | 'promote' | 'retire'; id: number }
    | { kind: 'rollback'; id: number }
    | null;

export function ExpansionLearningView(): ReactNode {
    const { t } = useUiTranslation();
    const toast = useToast();
    const [projectFilter, setProjectFilter] = useState('');
    const [confirm, setConfirm] = useState<PendingConfirm>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const [expanded, setExpanded] = useState<number | null>(null);

    const projectsQuery = useKbProjects();
    const query = useExpansionLearningStatus(projectFilter);
    const analyze = useAnalyzeExpansion();
    const applyTuning = useApplyTuning();
    const rejectTuning = useRejectTuning();
    const refreshModel = useRefreshModel();
    const promoteModel = usePromoteModel();
    const retireModel = useRetireModel();

    const status = query.data;
    const unavailable = isExpansionLearningDisabled(query.error);
    const state: PageState = pageState(query, unavailable, status);

    const projectOptions = useMemo(() => {
        const keys = new Set<string>(projectsQuery.data?.projects ?? []);
        status?.proposals.forEach((p) => keys.add(p.project_key));
        if (status?.applied) keys.add(status.applied.project_key);
        return [...keys].filter((k) => k !== '').sort((a, b) => a.localeCompare(b));
    }, [projectsQuery.data, status]);

    const busy =
        applyTuning.isPending || rejectTuning.isPending || refreshModel.isPending || promoteModel.isPending || retireModel.isPending;

    /** Runs one row action; every failure is explained (R14), never swallowed. */
    const run = (mutate: (id: number, opts: { onSuccess: () => void; onError: (err: unknown) => void }) => void, id: number, done: string, testid: string) => {
        setActionError(null);
        setConfirm(null);
        mutate(id, {
            onSuccess: () => toast.success(t(done), testid),
            onError: (err) => setActionError(expansionLearningErrorMessage(err)),
        });
    };

    const handleAnalyze = () => {
        if (projectFilter === '') return;
        setActionError(null);
        analyze.mutate(projectFilter, {
            onSuccess: () => toast.success(t('Analysis queued.'), 'admin-expansion-learning-analyze-queued'),
            onError: (err) => setActionError(expansionLearningErrorMessage(err)),
        });
    };

    return (
        <div
            data-testid="admin-expansion-learning-page"
            data-state={state}
            aria-busy={state === 'loading'}
            style={{ padding: 24 }}
        >
            <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 8, flexWrap: 'wrap' }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>{t('Expansion learning')}</h1>
                <span style={{ flex: 1 }} />
                {state !== 'unavailable' && (
                    <>
                        <label htmlFor="admin-expansion-learning-project-filter" style={{ color: 'var(--fg-2)', fontSize: 12 }}>
                            {t('Project')}
                        </label>
                        <SelectField
                            id="admin-expansion-learning-project-filter"
                            data-testid="admin-expansion-learning-project-filter"
                            value={projectFilter}
                            onValueChange={(value) => {
                                setProjectFilter(value);
                                setConfirm(null);
                                setExpanded(null);
                            }}
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
                            data-testid="admin-expansion-learning-analyze"
                            disabled={projectFilter === '' || analyze.isPending}
                            aria-describedby={projectFilter === '' ? 'admin-expansion-learning-analyze-hint' : undefined}
                            onClick={handleAnalyze}
                            style={primaryButtonStyle(projectFilter === '' || analyze.isPending)}
                        >
                            {analyze.isPending ? t('Queueing…') : t('Analyze now')}
                        </button>
                    </>
                )}
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 720 }}>
                {t('Learn from past searches which query rewrites actually find new results. An analysis proposes new weights and examples; nothing changes until you apply it.')}
                {state !== 'unavailable' && projectFilter === '' && (
                    <span id="admin-expansion-learning-analyze-hint"> {t('Choose a project to analyze it.')}</span>
                )}
            </p>

            {state === 'unavailable' && (
                <div
                    data-testid="admin-expansion-learning-unavailable"
                    data-state="unavailable"
                    role="status"
                    style={panelStyle('var(--panel-border)')}
                >
                    {t('Expansion learning is disabled on this deployment.')}
                </div>
            )}

            {state !== 'unavailable' && actionError && (
                <p data-testid="admin-expansion-learning-action-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, marginBottom: 8 }}>
                    {actionError}
                </p>
            )}

            {state === 'loading' && (
                <p data-testid="admin-expansion-learning-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>
                    {t('Loading…')}
                </p>
            )}

            {state === 'error' && (
                <div
                    data-testid="admin-expansion-learning-error"
                    data-state="error"
                    role="alert"
                    style={panelStyle('var(--err)', 'var(--err)')}
                >
                    <p style={{ margin: '0 0 8px' }}>
                        {t('Failed to load expansion learning.')} {expansionLearningErrorMessage(query.error)}
                    </p>
                    <button type="button" data-testid="admin-expansion-learning-retry" onClick={() => query.refetch()} style={ghostButtonStyle}>
                        {t('Retry')}
                    </button>
                </div>
            )}

            {state === 'empty' && (
                <p data-testid="admin-expansion-learning-empty" data-state="empty" style={panelStyle('var(--panel-border)')}>
                    {t('No searches with query expansion have been recorded yet. Signals appear here once users search with expansion enabled.')}
                </p>
            )}

            {state === 'ready' && status && (
                <>
                    <KpiRow status={status} />

                    <AppliedTuningCard
                        tuning={status.applied}
                        confirming={confirm?.kind === 'rollback' && confirm.id === status.applied?.id}
                        disabled={busy}
                        onAsk={(id) => setConfirm({ kind: 'rollback', id })}
                        onCancel={() => setConfirm(null)}
                        onConfirm={(id) => run(rejectTuning.mutate, id, 'Tuning rolled back.', 'admin-expansion-learning-rolled-back')}
                    />

                    <section aria-labelledby="admin-expansion-learning-proposals-heading" style={sectionStyle}>
                        <h2 id="admin-expansion-learning-proposals-heading" style={h2Style}>
                            {t('Proposals')}
                        </h2>
                        {status.proposals.length === 0 ? (
                            <p data-testid="admin-expansion-learning-proposals-empty" style={mutedStyle}>
                                {t('No proposals yet. Choose a project and click Analyze now.')}
                            </p>
                        ) : (
                            <table data-testid="admin-expansion-learning-proposals" style={tableStyle}>
                                <thead>
                                    <tr style={theadRowStyle}>
                                        <th style={cellStyle}>{t('ID')}</th>
                                        <th style={cellStyle}>{t('Project')}</th>
                                        <th style={cellStyle}>{t('Status')}</th>
                                        <th style={cellStyle}>{t('Weights')}</th>
                                        <th style={cellStyle}>{t('Benchmark gate')}</th>
                                        <th style={cellStyle}>{t('Created')}</th>
                                        <th style={{ ...cellStyle, textAlign: 'right' }}>{t('Actions')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {status.proposals.map((p) => (
                                        <ProposalRow
                                            key={p.id}
                                            tuning={p}
                                            expanded={expanded === p.id}
                                            onToggle={() => setExpanded(expanded === p.id ? null : p.id)}
                                            confirming={confirm !== null && confirm.id === p.id && (confirm.kind === 'apply' || confirm.kind === 'reject') ? confirm.kind : null}
                                            disabled={busy}
                                            onAsk={(kind) => setConfirm({ kind, id: p.id })}
                                            onCancel={() => setConfirm(null)}
                                            onConfirm={(kind) =>
                                                kind === 'apply'
                                                    ? run(applyTuning.mutate, p.id, 'Tuning applied.', 'admin-expansion-learning-applied-toast')
                                                    : run(rejectTuning.mutate, p.id, 'Proposal rejected.', 'admin-expansion-learning-rejected-toast')
                                            }
                                        />
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>

                    <section aria-labelledby="admin-expansion-learning-models-heading" style={sectionStyle}>
                        <h2 id="admin-expansion-learning-models-heading" style={h2Style}>
                            {t('Trained models')}
                        </h2>
                        {status.models.length === 0 ? (
                            <p data-testid="admin-expansion-learning-models-empty" style={mutedStyle}>
                                {t('No training runs yet.')}
                            </p>
                        ) : (
                            <table data-testid="admin-expansion-learning-models" style={tableStyle}>
                                <thead>
                                    <tr style={theadRowStyle}>
                                        <th style={cellStyle}>{t('ID')}</th>
                                        <th style={cellStyle}>{t('Backend')}</th>
                                        <th style={cellStyle}>{t('Base model')}</th>
                                        <th style={cellStyle}>{t('Rows')}</th>
                                        <th style={cellStyle}>{t('Status')}</th>
                                        <th style={cellStyle}>{t('Model ID')}</th>
                                        <th style={cellStyle}>{t('Failure reason')}</th>
                                        <th style={{ ...cellStyle, textAlign: 'right' }}>{t('Actions')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {status.models.map((m) => (
                                        <ModelRow
                                            key={m.id}
                                            model={m}
                                            confirming={confirm !== null && confirm.id === m.id && (confirm.kind === 'promote' || confirm.kind === 'retire') ? confirm.kind : null}
                                            disabled={busy}
                                            onRefresh={() => run(refreshModel.mutate, m.id, 'Status refreshed.', 'admin-expansion-learning-model-refreshed')}
                                            onAsk={(kind) => setConfirm({ kind, id: m.id })}
                                            onCancel={() => setConfirm(null)}
                                            onConfirm={(kind) =>
                                                kind === 'promote'
                                                    ? run(promoteModel.mutate, m.id, 'Model promoted.', 'admin-expansion-learning-model-promoted')
                                                    : run(retireModel.mutate, m.id, 'Model retired.', 'admin-expansion-learning-model-retired')
                                            }
                                        />
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>

                    <ExpansionLearningTrainingForm projectKey={projectFilter} trainingConsent={status.training_consent} />
                </>
            )}

            <ToastHost />
        </div>
    );
}

function KpiRow({ status }: { status: ExpansionLearningStatus }): ReactNode {
    const { t } = useUiTranslation();
    return (
        <div data-testid="admin-expansion-learning-kpis" style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
            <Kpi testid="admin-expansion-learning-kpi-total" label={t('Recorded searches')} value={status.events.total} />
            <Kpi testid="admin-expansion-learning-kpi-last-30-days" label={t('Last 30 days')} value={status.events.last_30_days} />
            <Kpi testid="admin-expansion-learning-kpi-with-feedback" label={t('With feedback')} value={status.events.with_feedback} />
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6, justifyContent: 'center' }}>
                <Badge testid="admin-expansion-learning-auto-apply" on={status.auto_apply}>
                    {status.auto_apply ? t('Auto-apply on') : t('Auto-apply off')}
                </Badge>
                <Badge testid="admin-expansion-learning-consent" on={status.training_consent}>
                    {status.training_consent ? t('Training consent: yes') : t('Training consent: no')}
                </Badge>
            </div>
        </div>
    );
}

function Kpi({ testid, label, value }: { testid: string; label: string; value: number }): ReactNode {
    return (
        <div data-testid={testid} style={{ border: '1px solid var(--panel-border, rgba(255,255,255,.1))', borderRadius: 8, padding: '10px 14px', minWidth: 140 }}>
            <div style={{ fontSize: 11, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.04em' }}>{label}</div>
            <div data-testid={`${testid}-value`} style={{ fontSize: 20, color: 'var(--fg-0)', fontWeight: 600 }}>
                {Number.isFinite(value) ? value : 0}
            </div>
        </div>
    );
}

function Badge({ testid, on, children }: { testid: string; on: boolean; children: ReactNode }): ReactNode {
    return (
        <span
            data-testid={testid}
            data-on={on ? 'true' : 'false'}
            style={{
                fontSize: 11,
                padding: '2px 8px',
                borderRadius: 999,
                border: `1px solid ${on ? 'var(--ok, #2f9e44)' : 'var(--panel-border, rgba(255,255,255,.15))'}`,
                color: on ? 'var(--ok, #2f9e44)' : 'var(--fg-2)',
            }}
        >
            {children}
        </span>
    );
}

interface AppliedTuningCardProps {
    tuning: ExpansionTuning | null;
    confirming: boolean;
    disabled: boolean;
    onAsk: (id: number) => void;
    onCancel: () => void;
    onConfirm: (id: number) => void;
}

function AppliedTuningCard({ tuning, confirming, disabled, onAsk, onCancel, onConfirm }: AppliedTuningCardProps): ReactNode {
    const { t } = useUiTranslation();
    return (
        <section
            data-testid="admin-expansion-learning-applied"
            data-state={tuning ? 'ready' : 'empty'}
            aria-labelledby="admin-expansion-learning-applied-heading"
            style={{ ...sectionStyle, border: '1px solid var(--panel-border, rgba(255,255,255,.1))', borderRadius: 8, padding: 14 }}
        >
            <h2 id="admin-expansion-learning-applied-heading" style={h2Style}>
                {t('Applied tuning')}
            </h2>
            {!tuning && (
                <p data-testid="admin-expansion-learning-applied-none" style={mutedStyle}>
                    {t('No tuning is applied: the expansion uses neutral weights.')}
                </p>
            )}
            {tuning && (
                <>
                    <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '4px 14px', margin: '0 0 10px', fontSize: 12.5 }}>
                        <dt style={dtStyle}>{t('Tuning')}</dt>
                        <dd style={ddStyle} data-testid="admin-expansion-learning-applied-id">#{tuning.id} · {tuning.project_key}</dd>
                        <dt style={dtStyle}>{t('Weights')}</dt>
                        <dd style={ddStyle} data-testid="admin-expansion-learning-applied-weights">{formatWeights(tuning.weights)}</dd>
                        <dt style={dtStyle}>{t('Few-shot examples')}</dt>
                        <dd style={ddStyle} data-testid="admin-expansion-learning-applied-few-shot">{tuning.few_shot_count}</dd>
                        <dt style={dtStyle}>{t('Decided')}</dt>
                        <dd style={ddStyle} data-testid="admin-expansion-learning-applied-decided">
                            {tuning.decided_by ?? '—'} · {formatDate(tuning.decided_at)}
                        </dd>
                        <dt style={dtStyle}>{t('Benchmark gate')}</dt>
                        <dd style={ddStyle}>
                            <GateBadge testid="admin-expansion-learning-applied-gate" status={tuning.gate?.status} />
                        </dd>
                    </dl>
                    {!confirming && (
                        <button
                            type="button"
                            data-testid="admin-expansion-learning-applied-rollback"
                            aria-label={t('Roll back tuning #{id}', { id: tuning.id })}
                            disabled={disabled}
                            onClick={() => onAsk(tuning.id)}
                            style={smallButtonStyle()}
                        >
                            {t('Roll back')}
                        </button>
                    )}
                    {confirming && (
                        <span role="group" aria-label={t('Confirm rolling back tuning #{id}', { id: tuning.id })}>
                            <span style={{ fontSize: 12, color: 'var(--fg-2)' }}>{t('The expansion returns to neutral weights.')}</span>
                            <button
                                type="button"
                                data-testid="admin-expansion-learning-applied-rollback-confirm"
                                onClick={() => onConfirm(tuning.id)}
                                style={smallButtonStyle('danger')}
                            >
                                {t('Confirm')}
                            </button>
                            <button type="button" data-testid="admin-expansion-learning-applied-rollback-cancel" onClick={onCancel} style={smallButtonStyle()}>
                                {t('Cancel')}
                            </button>
                        </span>
                    )}
                </>
            )}
        </section>
    );
}

interface ProposalRowProps {
    tuning: ExpansionTuning;
    expanded: boolean;
    onToggle: () => void;
    confirming: 'apply' | 'reject' | null;
    disabled: boolean;
    onAsk: (kind: 'apply' | 'reject') => void;
    onCancel: () => void;
    onConfirm: (kind: 'apply' | 'reject') => void;
}

function ProposalRow({ tuning, expanded, onToggle, confirming, disabled, onAsk, onCancel, onConfirm }: ProposalRowProps): ReactNode {
    const { t } = useUiTranslation();
    const base = `admin-expansion-learning-proposal-${tuning.id}`;
    const panelId = `${base}-details-panel`;
    const actionable = tuning.status === 'proposed';
    return (
        <Fragment>
            <tr data-testid={base} data-status={tuning.status} style={rowStyle}>
                <td style={cellStyle}>#{tuning.id}</td>
                <td style={{ ...cellStyle, color: 'var(--fg-2)' }}>{tuning.project_key}</td>
                <td style={cellStyle}>
                    <span data-testid={`${base}-status`}>{statusLabel(tuning.status, t)}</span>
                </td>
                <td style={{ ...cellStyle, fontFamily: 'var(--font-mono, monospace)' }}>{formatWeights(tuning.weights)}</td>
                <td style={cellStyle}>
                    <GateBadge testid={`${base}-gate`} status={tuning.gate?.status} />
                </td>
                <td style={{ ...cellStyle, color: 'var(--fg-3)', whiteSpace: 'nowrap' }}>{formatDate(tuning.created_at)}</td>
                <td style={{ ...cellStyle, textAlign: 'right', whiteSpace: 'nowrap' }}>
                    <button
                        type="button"
                        data-testid={`${base}-details`}
                        aria-expanded={expanded}
                        aria-controls={panelId}
                        onClick={onToggle}
                        style={smallButtonStyle()}
                    >
                        {expanded ? t('Hide details') : t('Details')}
                    </button>
                    {actionable && confirming === null && (
                        <>
                            <button
                                type="button"
                                data-testid={`${base}-apply`}
                                aria-label={t('Apply tuning #{id}', { id: tuning.id })}
                                disabled={disabled}
                                onClick={() => onAsk('apply')}
                                style={smallButtonStyle()}
                            >
                                {t('Apply')}
                            </button>
                            <button
                                type="button"
                                data-testid={`${base}-reject`}
                                aria-label={t('Reject tuning #{id}', { id: tuning.id })}
                                disabled={disabled}
                                onClick={() => onAsk('reject')}
                                style={smallButtonStyle()}
                            >
                                {t('Reject')}
                            </button>
                        </>
                    )}
                    {actionable && confirming !== null && (
                        <>
                            <button
                                type="button"
                                data-testid={`${base}-${confirming}-confirm`}
                                aria-label={
                                    confirming === 'apply'
                                        ? t('Confirm applying tuning #{id}', { id: tuning.id })
                                        : t('Confirm rejecting tuning #{id}', { id: tuning.id })
                                }
                                onClick={() => onConfirm(confirming)}
                                style={smallButtonStyle(confirming === 'reject' ? 'danger' : 'primary')}
                            >
                                {t('Confirm')}
                            </button>
                            <button type="button" data-testid={`${base}-${confirming}-cancel`} onClick={onCancel} style={smallButtonStyle()}>
                                {t('Cancel')}
                            </button>
                        </>
                    )}
                </td>
            </tr>
            {expanded && (
                <tr data-testid={panelId} id={panelId} style={{ background: 'var(--bg-1, transparent)' }}>
                    <td colSpan={7} style={{ ...cellStyle, paddingBottom: 14 }}>
                        <h3 style={h3Style}>{t('Few-shot examples')}</h3>
                        <FewShotList base={base} examples={tuning.few_shot ?? []} />
                        <h3 style={h3Style}>{t('Evidence')}</h3>
                        <pre data-testid={`${base}-evidence`} style={preStyle}>
                            {stringify(tuning.evidence)}
                        </pre>
                        <h3 style={h3Style}>{t('Benchmark gate')}</h3>
                        <pre data-testid={`${base}-gate-detail`} style={preStyle}>
                            {stringify(tuning.gate)}
                        </pre>
                    </td>
                </tr>
            )}
        </Fragment>
    );
}

function FewShotList({ base, examples }: { base: string; examples: FewShotExample[] }): ReactNode {
    const { t } = useUiTranslation();
    if (examples.length === 0) {
        return (
            <p data-testid={`${base}-few-shot-empty`} style={mutedStyle}>
                {t('No examples.')}
            </p>
        );
    }
    return (
        <ol data-testid={`${base}-few-shot`} style={{ margin: '0 0 10px', paddingLeft: 18, fontSize: 12 }}>
            {examples.map((ex, i) => (
                <li key={i} data-testid={`${base}-few-shot-${i}`} style={{ marginBottom: 6 }}>
                    <div style={{ color: 'var(--fg-0)' }}>{String(ex.query ?? '')}</div>
                    {(ex.lex ?? []).length > 0 && <div style={exampleLineStyle}>lex: {(ex.lex ?? []).map(String).join(' · ')}</div>}
                    {(ex.vec ?? []).length > 0 && <div style={exampleLineStyle}>vec: {(ex.vec ?? []).map(String).join(' · ')}</div>}
                    {ex.hyde ? <div style={exampleLineStyle}>hyde: {String(ex.hyde)}</div> : null}
                </li>
            ))}
        </ol>
    );
}

interface ModelRowProps {
    model: ExpansionModel;
    confirming: 'promote' | 'retire' | null;
    disabled: boolean;
    onRefresh: () => void;
    onAsk: (kind: 'promote' | 'retire') => void;
    onCancel: () => void;
    onConfirm: (kind: 'promote' | 'retire') => void;
}

function ModelRow({ model, confirming, disabled, onRefresh, onAsk, onCancel, onConfirm }: ModelRowProps): ReactNode {
    const { t } = useUiTranslation();
    const base = `admin-expansion-learning-model-${model.id}`;
    const canRefresh = model.status === 'submitted' || model.status === 'training';
    const canPromote = model.status === 'ready';
    const canRetire = model.status !== 'retired';
    return (
        <tr data-testid={base} data-status={model.status} style={rowStyle}>
            <td style={cellStyle}>#{model.id}</td>
            <td style={cellStyle}>{model.backend}</td>
            <td style={{ ...cellStyle, fontFamily: 'var(--font-mono, monospace)' }}>{model.base_model ?? '—'}</td>
            <td style={cellStyle}>{model.dataset_rows ?? '—'}</td>
            <td style={cellStyle}>
                <span data-testid={`${base}-status`}>{statusLabel(model.status, t)}</span>
            </td>
            <td style={{ ...cellStyle, fontFamily: 'var(--font-mono, monospace)', wordBreak: 'break-all' }}>{model.model_id ?? '—'}</td>
            <td style={{ ...cellStyle, color: model.failure_reason ? 'var(--err)' : 'var(--fg-3)' }}>{model.failure_reason ?? '—'}</td>
            <td style={{ ...cellStyle, textAlign: 'right', whiteSpace: 'nowrap' }}>
                {confirming === null && (
                    <>
                        {canRefresh && (
                            <button
                                type="button"
                                data-testid={`${base}-refresh`}
                                aria-label={t('Refresh model #{id}', { id: model.id })}
                                disabled={disabled}
                                onClick={onRefresh}
                                style={smallButtonStyle()}
                            >
                                {t('Refresh')}
                            </button>
                        )}
                        {canPromote && (
                            <button
                                type="button"
                                data-testid={`${base}-promote`}
                                aria-label={t('Promote model #{id}', { id: model.id })}
                                disabled={disabled}
                                onClick={() => onAsk('promote')}
                                style={smallButtonStyle()}
                            >
                                {t('Promote')}
                            </button>
                        )}
                        {canRetire && (
                            <button
                                type="button"
                                data-testid={`${base}-retire`}
                                aria-label={t('Retire model #{id}', { id: model.id })}
                                disabled={disabled}
                                onClick={() => onAsk('retire')}
                                style={smallButtonStyle()}
                            >
                                {t('Retire')}
                            </button>
                        )}
                    </>
                )}
                {confirming !== null && (
                    <>
                        <button
                            type="button"
                            data-testid={`${base}-${confirming}-confirm`}
                            aria-label={
                                confirming === 'promote'
                                    ? t('Confirm promoting model #{id}', { id: model.id })
                                    : t('Confirm retiring model #{id}', { id: model.id })
                            }
                            onClick={() => onConfirm(confirming)}
                            style={smallButtonStyle(confirming === 'retire' ? 'danger' : 'primary')}
                        >
                            {t('Confirm')}
                        </button>
                        <button type="button" data-testid={`${base}-${confirming}-cancel`} onClick={onCancel} style={smallButtonStyle()}>
                            {t('Cancel')}
                        </button>
                    </>
                )}
            </td>
        </tr>
    );
}

const GATE_LABELS: Record<string, string> = {
    passed: 'Passed',
    failed: 'Failed',
    not_measurable: 'Not measurable',
    skipped: 'Skipped',
};

function GateBadge({ testid, status }: { testid: string; status?: string | null }): ReactNode {
    const { t } = useUiTranslation();
    const key = typeof status === 'string' && status !== '' ? status : 'unknown';
    const color = key === 'passed' ? 'var(--ok, #2f9e44)' : key === 'failed' ? 'var(--err, #c4391d)' : 'var(--fg-2)';
    return (
        <span data-testid={testid} data-gate={key} style={{ color, fontSize: 12 }}>
            {GATE_LABELS[key] ? t(GATE_LABELS[key]) : key === 'unknown' ? '—' : key}
        </span>
    );
}

const STATUS_LABELS: Record<string, string> = {
    proposed: 'Proposed',
    applied: 'Applied',
    rejected: 'Rejected',
    superseded: 'Superseded',
    pending: 'Pending',
    submitted: 'Submitted',
    training: 'Training',
    ready: 'Ready',
    failed: 'Failed',
    promoted: 'Promoted',
    retired: 'Retired',
};

function statusLabel(status: string, t: (message: string) => string): string {
    return STATUS_LABELS[status] ? t(STATUS_LABELS[status]) : status;
}

function pageState(
    query: { isPending: boolean; isError: boolean; fetchStatus: string },
    unavailable: boolean,
    status: ExpansionLearningStatus | undefined,
): PageState {
    if (unavailable) return 'unavailable';
    if (query.isError) return 'error';
    if (query.isPending || !status) return query.fetchStatus === 'idle' ? 'idle' : 'loading';
    const nothing = status.events.total === 0 && status.proposals.length === 0 && status.models.length === 0 && status.applied === null;
    return nothing ? 'empty' : 'ready';
}

function formatWeights(weights: ExpansionWeights | null): string {
    if (!weights) return '—';
    const part = (name: keyof ExpansionWeights) => {
        const value = weights[name];
        return `${name} ${typeof value === 'number' && Number.isFinite(value) ? value.toFixed(2) : '—'}`;
    };
    return [part('lex'), part('vec'), part('hyde')].join(' · ');
}

/** `2026-11-05T10:00:00+00:00` → `2026-11-05 10:00 UTC` (deterministic, no locale). */
function formatDate(value?: string | null): string {
    if (!value) return '—';
    const match = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/.exec(value);
    return match ? `${match[1]} ${match[2]} UTC` : value;
}

function stringify(value: unknown): string {
    if (value === null || value === undefined) return '—';
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

const cellStyle: CSSProperties = { padding: '8px 10px', verticalAlign: 'top' };
const rowStyle: CSSProperties = { borderTop: '1px solid var(--panel-border, rgba(255,255,255,.06))' };
const theadRowStyle: CSSProperties = { textAlign: 'left', color: 'var(--fg-2)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em' };
const tableStyle: CSSProperties = { width: '100%', borderCollapse: 'collapse', fontSize: 12.5 };
const sectionStyle: CSSProperties = { marginBottom: 20 };
const h2Style: CSSProperties = { margin: '0 0 8px', fontSize: 14, color: 'var(--fg-0)' };
const h3Style: CSSProperties = { margin: '8px 0 4px', fontSize: 12, color: 'var(--fg-1)' };
const mutedStyle: CSSProperties = { margin: 0, color: 'var(--fg-3)', fontSize: 12 };
const dtStyle: CSSProperties = { color: 'var(--fg-3)' };
const ddStyle: CSSProperties = { margin: 0, color: 'var(--fg-1)' };
const exampleLineStyle: CSSProperties = { color: 'var(--fg-2)', fontFamily: 'var(--font-mono, monospace)', fontSize: 11.5, whiteSpace: 'pre-wrap' };
const preStyle: CSSProperties = {
    margin: '0 0 8px',
    padding: 8,
    fontSize: 11,
    maxHeight: 220,
    overflow: 'auto',
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-word',
    border: '1px solid var(--panel-border, rgba(255,255,255,.08))',
    borderRadius: 6,
    color: 'var(--fg-2)',
};

// The shadcn trigger carries the theme (border, background, focus ring);
// only size it to fit the header row.
const selectStyle: CSSProperties = { fontSize: 12, minWidth: 160 };

function primaryButtonStyle(disabled: boolean): CSSProperties {
    return {
        padding: '5px 12px',
        borderRadius: 6,
        border: '1px solid var(--accent, #6366f1)',
        background: 'var(--accent, #6366f1)',
        color: 'white',
        fontSize: 12,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.55 : 1,
    };
}

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

function smallButtonStyle(variant: 'neutral' | 'danger' | 'primary' = 'neutral'): CSSProperties {
    const danger = variant === 'danger';
    const primary = variant === 'primary';
    const accent = danger ? 'var(--err, #c4391d)' : 'var(--accent, #6366f1)';
    return {
        marginLeft: 6,
        padding: '4px 10px',
        borderRadius: 6,
        border: '1px solid ' + (danger || primary ? accent : 'var(--panel-border, rgba(255,255,255,.12))'),
        background: danger || primary ? accent : 'transparent',
        color: danger || primary ? 'white' : 'var(--fg-1)',
        fontSize: 11.5,
        cursor: 'pointer',
    };
}
