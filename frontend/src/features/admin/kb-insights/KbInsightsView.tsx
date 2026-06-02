import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { listAnalyses, type DocAnalysis } from './analyses.api';

/**
 * v8.7/W3–W4 — Doc Insights: read-only list of AI document-change analyses.
 *
 * Each card shows, for one ingest/modify, the LLM's enhancement suggestions,
 * cross-references, and the documents this change may have made obsolete /
 * in need of revision. Suggest-only — nothing here mutates a doc.
 *
 * R11 testids · R14 distinct loading / empty / error states · R15 a11y.
 */
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
                                {row.analysis_json.impacted_docs.map((d, i) => (
                                    <li key={i} style={{ margin: '2px 0' }}>
                                        <strong>{d.title || d.slug}</strong> — {d.impact}{' '}
                                        <em style={{ color: 'var(--accent, #6366f1)' }}>→ {d.suggested_action}</em>
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    )}
                    {row.analysis_json.cross_references.length > 0 && (
                        <Section title={`Cross-references (${row.analysis_json.cross_references.length})`} testid={`admin-kb-insight-${row.id}-crossrefs`}>
                            <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, color: 'var(--fg-2)' }}>
                                {row.analysis_json.cross_references.map((c, i) => (
                                    <li key={i} style={{ margin: '2px 0' }}>{c.title || c.slug} — {c.why}</li>
                                ))}
                            </ul>
                        </Section>
                    )}
                </div>
            )}
        </article>
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
