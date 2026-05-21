import { useMemo, useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { AdminShell } from '../shell/AdminShell';
import { adminKbApi } from '../admin.api';

export function KbHealthView(): ReactNode {
    const [project, setProject] = useState<string>('');
    const [minScore, setMinScore] = useState<number>(0);

    const query = useQuery({
        queryKey: ['admin', 'kb', 'health', project, minScore],
        queryFn: () => adminKbApi.health({
            project: project || null,
            min_score: minScore,
            limit: 200,
        }),
    });

    const rows = query.data?.data ?? [];
    const topRows = useMemo(() => rows.slice(0, 40), [rows]);

    return (
        <AdminShell section="kb">
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14, padding: 8 }}>
                <h1 style={{ margin: 0, fontSize: 22 }}>KB health heatmap</h1>
                <p style={{ margin: 0, fontSize: 13, color: 'var(--fg-3)' }}>
                    Decision-debt score per canonical document (higher = more debt).
                </p>

                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                    <input
                        value={project}
                        onChange={(e) => setProject(e.target.value)}
                        placeholder="project_key"
                        style={{ padding: '6px 8px', border: '1px solid var(--hairline)', borderRadius: 8 }}
                    />
                    <label style={{ fontSize: 12 }}>
                        Min score:
                        <input
                            type="number"
                            min={0}
                            max={100}
                            value={minScore}
                            onChange={(e) => setMinScore(Number(e.target.value || 0))}
                            style={{ marginLeft: 6, width: 72, padding: '5px 6px', border: '1px solid var(--hairline)', borderRadius: 8 }}
                        />
                    </label>
                    <span className="mono" style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                        threshold_event_score={query.data?.threshold_event_score ?? '-'}
                    </span>
                </div>

                {query.isLoading && <p>Loading…</p>}
                {query.isError && <p style={{ color: 'var(--err)' }}>Unable to load KB health snapshot.</p>}

                {!query.isLoading && !query.isError && (
                    <>
                        <div style={{ display: 'flex', gap: 14, fontSize: 12, color: 'var(--fg-2)' }}>
                            <span>Total: {query.data?.meta.total ?? 0}</span>
                            <span>Avg: {(query.data?.meta.avg_score ?? 0).toFixed(1)}</span>
                            <span>Max: {query.data?.meta.max_score ?? 0}</span>
                        </div>

                        <div
                            style={{
                                display: 'grid',
                                gridTemplateColumns: 'repeat(10, minmax(80px, 1fr))',
                                gap: 6,
                            }}
                        >
                            {topRows.map((r) => (
                                <div
                                    key={r.knowledge_document_id}
                                    title={`${r.project_key}/${r.doc_slug ?? r.knowledge_document_id}: ${r.health_score}`}
                                    style={{
                                        border: '1px solid var(--hairline)',
                                        borderRadius: 8,
                                        padding: 6,
                                        background: `rgba(220, 38, 38, ${Math.min(0.9, Math.max(0.08, r.health_score / 100))})`,
                                        color: '#fff',
                                        minHeight: 52,
                                    }}
                                >
                                    <div className="mono" style={{ fontSize: 10 }}>{r.project_key}</div>
                                    <div style={{ fontSize: 11, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                        {r.doc_slug ?? `doc-${r.knowledge_document_id}`}
                                    </div>
                                    <div style={{ fontSize: 12, fontWeight: 700 }}>{r.health_score}</div>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </AdminShell>
    );
}

