import type { QualityReport } from './insights.api';

export interface QualityReportCardProps {
    report: QualityReport | null;
}

/*
 * Phase I — corpus quality: chunk-length histogram + outlier + missing
 * frontmatter counts. Keeps charting dependency-free — the histogram
 * uses plain inline SVG bars so we don't pull Recharts for one card.
 * (Recharts is already code-split elsewhere; adding another widget-
 * level import was evaluated and skipped as overkill for a 5-bar chart.)
 */
export function QualityReportCard({ report }: QualityReportCardProps) {
    if (report === null) {
        return (
            <div
                data-testid="insight-card-quality"
                data-state="empty"
                style={{
                    border: '1px solid var(--hairline)',
                    borderRadius: 8,
                    padding: '14px 16px',
                    background: 'var(--bg-1)',
                }}
            >
                <h2 style={{ margin: 0, fontSize: 13, color: 'var(--fg-0)' }}>Quality report</h2>
                <div
                    data-testid="insight-card-quality-empty"
                    style={{ fontSize: 12, color: 'var(--fg-3)', marginTop: 8 }}
                >
                    Quality report unavailable for this snapshot.
                </div>
            </div>
        );
    }

    const state = report.total_chunks > 0 ? 'ready' : 'empty';
    const bars = Object.entries(report.chunk_length_distribution);
    const max = Math.max(...bars.map(([, v]) => v), 1);

    return (
        <div
            data-testid="insight-card-quality"
            data-state={state}
            style={{
                border: '1px solid var(--hairline)',
                borderRadius: 8,
                padding: '14px 16px',
                background: 'var(--bg-1)',
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
            }}
        >
            <h2 style={{ margin: 0, fontSize: 13, color: 'var(--fg-0)' }}>Quality report</h2>
            <div
                data-testid="insight-card-quality-summary"
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(4, 1fr)',
                    gap: 8,
                    fontSize: 11.5,
                }}
            >
                <SummaryItem label="Docs" value={report.total_docs} />
                <SummaryItem label="Chunks" value={report.total_chunks} />
                <SummaryItem label="Short outliers" value={report.outlier_short} />
                <SummaryItem label="Long outliers" value={report.outlier_long} />
            </div>
            <div
                data-testid="insight-card-quality-histogram"
                style={{ display: 'flex', flexDirection: 'column', gap: 4 }}
            >
                {bars.map(([bucket, count]) => (
                    <div
                        key={bucket}
                        data-testid={`quality-bucket-${bucket}`}
                        style={{
                            display: 'grid',
                            gridTemplateColumns: '80px 1fr 48px',
                            alignItems: 'center',
                            gap: 8,
                            fontSize: 11,
                            fontFamily: 'var(--font-mono)',
                            color: 'var(--fg-3)',
                        }}
                    >
                        <span>{bucket}</span>
                        <div
                            style={{
                                height: 10,
                                background: 'var(--bg-0)',
                                borderRadius: 3,
                                overflow: 'hidden',
                                border: '1px solid var(--hairline)',
                            }}
                        >
                            <div
                                style={{
                                    height: '100%',
                                    width: `${(count / max) * 100}%`,
                                    background: 'var(--accent, #3b82f6)',
                                }}
                            />
                        </div>
                        <span style={{ textAlign: 'right', color: 'var(--fg-2)' }}>{count}</span>
                    </div>
                ))}
            </div>
            <div
                data-testid="insight-card-quality-missing-fm"
                style={{ fontSize: 11.5, color: 'var(--fg-3)' }}
            >
                {report.missing_frontmatter} canonical doc{report.missing_frontmatter === 1 ? '' : 's'}
                {' '}missing frontmatter.
            </div>
        </div>
    );
}

function SummaryItem({ label, value }: { label: string; value: number }) {
    return (
        <div>
            <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--fg-0)' }}>{value}</div>
            <div style={{ color: 'var(--fg-3)' }}>{label}</div>
        </div>
    );
}
