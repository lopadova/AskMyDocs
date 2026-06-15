import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { applySuggestion, listAnalyses, type DocAnalysis } from './analyses.api';

/**
 * v8.7/W3–W4 — Doc Insights: list of AI document-change analyses.
 *
 * Each card shows, for one ingest/modify, the LLM's enhancement suggestions,
 * cross-references, and the documents this change may have made obsolete /
 * in need of revision. Enhancement suggestions are advice only; cross-references
 * and impacted docs can be APPLIED (v8.11/P8): a manual apply (admin actor) adds
 * the cross-reference edge or deprecates the impacted doc, audited + reversible.
 *
 * R11 testids · R14 distinct loading / empty / error states + a 200-with-refusal
 * surfaced distinctly from a transport error · R15 a11y.
 */
type ApplyNote = { kind: 'ok' | 'refused' | 'error'; msg: string };
export function KbInsightsView(): ReactNode {
    const [statusFilter, setStatusFilter] = useState<'' | 'completed' | 'failed'>('');

    const query = useQuery({
        queryKey: ['admin-kb-analyses', statusFilter],
        queryFn: () => listAnalyses({ status: statusFilter || undefined }),
        staleTime: 30_000,
    });

    const rows = query.data?.data ?? [];

    return (
        <div data-testid="admin-kb-insights-view" style={{ padding: 24 }}>
            <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 8 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Doc Insights</h1>
                <span data-testid="admin-kb-insights-count" style={{ color: 'var(--fg-3)', fontSize: 12 }}>
                    {query.data?.meta.total ?? 0} total
                </span>
                <span style={{ flex: 1 }} />
                <label htmlFor="kb-insights-status" style={{ color: 'var(--fg-2)', fontSize: 11 }}>
                    Status
                </label>
                <select
                    id="kb-insights-status"
                    data-testid="admin-kb-insights-status-filter"
                    value={statusFilter}
                    onChange={(e) => setStatusFilter(e.target.value as '' | 'completed' | 'failed')}
                    style={{
                        padding: '5px 10px',
                        borderRadius: 6,
                        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                        background: 'var(--bg-3, rgba(255,255,255,.04))',
                        color: 'var(--fg-0)',
                        fontSize: 12,
                    }}
                >
                    <option value="">All</option>
                    <option value="completed">Completed</option>
                    <option value="failed">Failed</option>
                </select>
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 640 }}>
                When a document is ingested, modified, or deleted, an AI pass suggests how to strengthen it,
                surfaces cross-references, and flags which other docs the change may have made obsolete (for a
                deletion, which remaining docs now have a dangling reference). Advice only — nothing here edits
                a document.
            </p>

            {query.isLoading && (
                <p data-testid="admin-kb-insights-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>
                    Loading…
                </p>
            )}
            {!query.isLoading && query.isError && (
                <p
                    data-testid="admin-kb-insights-error"
                    data-state="error"
                    role="alert"
                    style={{ color: 'var(--err)', padding: 24, textAlign: 'center', border: '1px dashed var(--err)', borderRadius: 8 }}
                >
                    Failed to load analyses. {query.error instanceof Error ? query.error.message : 'Please retry.'}
                </p>
            )}
            {!query.isLoading && !query.isError && rows.length === 0 && (
                <p
                    data-testid="admin-kb-insights-empty"
                    data-state="empty"
                    style={{ color: 'var(--fg-3)', padding: 24, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}
                >
                    No document analyses yet. They appear automatically after canonical documents are ingested, modified, or deleted.
                </p>
            )}

            {rows.length > 0 && (
                <div data-testid="admin-kb-insights-list" data-state="ready" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                    {rows.map((row) => (
                        <AnalysisCard key={row.id} row={row} />
                    ))}
                </div>
            )}
        </div>
    );
}

function AnalysisCard({ row }: { row: DocAnalysis }): ReactNode {
    const failed = row.status === 'failed';
    const qc = useQueryClient();
    const [notes, setNotes] = useState<Record<string, ApplyNote>>({});
    const [pendingKey, setPendingKey] = useState<string | null>(null);

    const applyMutation = useMutation({
        mutationFn: (vars: { type: 'cross_reference' | 'impacted'; target: string }) =>
            applySuggestion(row.id, vars.type, vars.target),
        onMutate: (vars) => setPendingKey(`${vars.type}:${vars.target}`),
        onSuccess: (res, vars) => {
            const key = `${vars.type}:${vars.target}`;
            const note: ApplyNote = res.applied
                ? { kind: 'ok', msg: `Applied — ${res.action ?? 'done'}.` }
                : { kind: 'refused', msg: `Not applied — ${res.reason ?? 'refused'}.` };
            setNotes((n) => ({ ...n, [key]: note }));
            if (res.applied) {
                qc.invalidateQueries({ queryKey: ['admin-kb-analyses'] });
            }
        },
        onError: (err, vars) => {
            const key = `${vars.type}:${vars.target}`;
            setNotes((n) => ({ ...n, [key]: { kind: 'error', msg: err instanceof Error ? err.message : 'Apply failed.' } }));
        },
        onSettled: () => setPendingKey(null),
    });

    const apply = (type: 'cross_reference' | 'impacted', target: string) => applyMutation.mutate({ type, target });

    return (
        <article
            data-testid={`admin-kb-insight-${row.id}`}
            data-analysis-status={row.status}
            style={{
                border: '1px solid var(--panel-border, rgba(255,255,255,.1))',
                borderRadius: 10,
                padding: '12px 16px',
                background: 'var(--bg-2, rgba(255,255,255,.02))',
            }}
        >
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 10, flexWrap: 'wrap' }}>
                <strong style={{ fontSize: 14, color: 'var(--fg-0)' }}>{row.document_title ?? row.doc_slug ?? `Doc #${row.knowledge_document_id}`}</strong>
                <span style={{ fontSize: 11, color: 'var(--fg-3)' }}>{row.project_key}</span>
                <span style={{ fontSize: 11, color: 'var(--fg-2)', textTransform: 'uppercase', letterSpacing: '.04em' }}>{row.trigger}</span>
                <span
                    data-testid={`admin-kb-insight-${row.id}-status`}
                    style={{ fontSize: 11, color: failed ? 'var(--err, #c4391d)' : 'var(--ok, #3fb950)' }}
                >
                    {row.status}
                </span>
            </div>

            {failed && (
                <p data-testid={`admin-kb-insight-${row.id}-error`} role="alert" style={{ margin: '8px 0 0', fontSize: 12, color: 'var(--err)' }}>
                    Analysis failed: {row.error ?? 'unknown error'}
                </p>
            )}

            {!failed && (
                <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 8 }}>
                    {row.analysis_json.enhancement_suggestions.length > 0 && (
                        <Section title={`Suggestions (${row.suggestion_count})`} testid={`admin-kb-insight-${row.id}-suggestions`}>
                            <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, color: 'var(--fg-1)' }}>
                                {row.analysis_json.enhancement_suggestions.map((s, i) => (
                                    <li key={i} style={{ margin: '2px 0' }}>{s}</li>
                                ))}
                            </ul>
                        </Section>
                    )}
                    {row.analysis_json.impacted_docs.length > 0 && (
                        <Section title={`Impacted docs (${row.impacted_count})`} testid={`admin-kb-insight-${row.id}-impacted`}>
                            <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, color: 'var(--fg-1)' }}>
                                {row.analysis_json.impacted_docs.map((d) => (
                                    <li key={d.slug} style={{ margin: '4px 0' }}>
                                        <strong>{d.title || d.slug}</strong> — {d.impact}{' '}
                                        <em style={{ color: 'var(--accent, #6366f1)' }}>→ {d.suggested_action}</em>
                                        <ApplyControl
                                            testid={`admin-kb-insight-${row.id}-impacted-${d.slug}`}
                                            label={`Deprecate ${d.slug}`}
                                            pending={pendingKey === `impacted:${d.slug}`}
                                            note={notes[`impacted:${d.slug}`]}
                                            onApply={() => apply('impacted', d.slug)}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    )}
                    {row.analysis_json.cross_references.length > 0 && (
                        <Section title={`Cross-references (${row.analysis_json.cross_references.length})`} testid={`admin-kb-insight-${row.id}-crossrefs`}>
                            <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, color: 'var(--fg-2)' }}>
                                {row.analysis_json.cross_references.map((c) => (
                                    <li key={c.slug} style={{ margin: '4px 0' }}>
                                        {c.title || c.slug} — {c.why}
                                        <ApplyControl
                                            testid={`admin-kb-insight-${row.id}-crossref-${c.slug}`}
                                            label={`Add cross-reference to ${c.slug}`}
                                            pending={pendingKey === `cross_reference:${c.slug}`}
                                            note={notes[`cross_reference:${c.slug}`]}
                                            onApply={() => apply('cross_reference', c.slug)}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    )}
                </div>
            )}
        </article>
    );
}

function ApplyControl({ testid, label, pending, note, onApply }: { testid: string; label: string; pending: boolean; note: ApplyNote | undefined; onApply: () => void }): ReactNode {
    const applied = note?.kind === 'ok';
    const noteColor = note?.kind === 'ok' ? 'var(--ok, #3fb950)' : note?.kind === 'refused' ? 'var(--warn, #d29922)' : 'var(--err, #c4391d)';
    return (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, marginLeft: 8 }}>
            <button
                type="button"
                data-testid={`${testid}-apply`}
                onClick={onApply}
                disabled={pending || applied}
                aria-label={label}
                style={{ fontSize: 10.5, padding: '1px 8px', borderRadius: 5, cursor: pending || applied ? 'default' : 'pointer', border: '1px solid var(--accent, #6366f1)', color: applied ? 'var(--fg-3)' : 'var(--accent, #6366f1)', background: 'transparent' }}
            >
                {pending ? 'Applying…' : applied ? 'Applied' : 'Apply'}
            </button>
            {note && (
                <span
                    data-testid={`${testid}-note`}
                    role={note.kind === 'error' ? 'alert' : 'status'}
                    style={{ fontSize: 10.5, color: noteColor }}
                >
                    {note.msg}
                </span>
            )}
        </span>
    );
}

function Section({ title, testid, children }: { title: string; testid: string; children: ReactNode }): ReactNode {
    return (
        <div data-testid={testid}>
            <div style={{ fontSize: 10.5, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 2 }}>{title}</div>
            {children}
        </div>
    );
}
