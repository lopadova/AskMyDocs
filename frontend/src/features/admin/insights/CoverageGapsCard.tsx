import type { CoverageGap } from './insights.api';

export interface CoverageGapsCardProps {
    items: CoverageGap[];
}

export function CoverageGapsCard({ items }: CoverageGapsCardProps) {
    const state = items.length === 0 ? 'empty' : 'ready';
    return (
        <div
            data-testid="insight-card-coverage-gaps"
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
            <h2 style={{ margin: 0, fontSize: 13, color: 'var(--fg-0)' }}>Coverage gaps</h2>
            {state === 'empty' ? (
                <div
                    data-testid="insight-card-coverage-gaps-empty"
                    style={{ fontSize: 12, color: 'var(--fg-3)' }}
                >
                    No coverage gaps detected.
                </div>
            ) : (
                <ul
                    style={{
                        margin: 0,
                        padding: 0,
                        listStyle: 'none',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 10,
                    }}
                >
                    {items.slice(0, 10).map((gap, idx) => (
                        <li
                            key={gap.topic + idx}
                            data-testid={`coverage-gap-row-${idx}`}
                            style={{
                                padding: '6px 0',
                                borderBottom: '1px solid var(--hairline)',
                                fontSize: 12.5,
                            }}
                        >
                            <div style={{ color: 'var(--fg-1)', fontWeight: 500 }}>
                                {gap.topic}
                            </div>
                            <div
                                style={{
                                    fontSize: 11,
                                    color: 'var(--fg-3)',
                                    marginBottom: 4,
                                    fontFamily: 'var(--font-mono)',
                                }}
                            >
                                {gap.zero_citation_count} zero-citation · {gap.low_confidence_count} low-confidence
                            </div>
                            {gap.sample_questions.length > 0 ? (
                                <ul
                                    style={{
                                        margin: 0,
                                        paddingLeft: 14,
                                        fontSize: 11.5,
                                        color: 'var(--fg-2)',
                                    }}
                                >
                                    {gap.sample_questions.slice(0, 3).map((q, i) => (
                                        <li
                                            key={i}
                                            data-testid={`coverage-gap-${idx}-sample-${i}`}
                                        >
                                            {q}
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
