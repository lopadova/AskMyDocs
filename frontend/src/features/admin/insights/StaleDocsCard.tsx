import type { StaleDoc } from './insights.api';

export interface StaleDocsCardProps {
    items: StaleDoc[];
}

export function StaleDocsCard({ items }: StaleDocsCardProps) {
    const state = items.length === 0 ? 'empty' : 'ready';
    return (
        <div
            data-testid="insight-card-stale-docs"
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
            <h2 style={{ margin: 0, fontSize: 13, color: 'var(--fg-0)' }}>Stale canonical docs</h2>
            {state === 'empty' ? (
                <div
                    data-testid="insight-card-stale-docs-empty"
                    style={{ fontSize: 12, color: 'var(--fg-3)' }}
                >
                    No stale docs detected.
                </div>
            ) : (
                <ul
                    style={{
                        margin: 0,
                        padding: 0,
                        listStyle: 'none',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 6,
                    }}
                >
                    {items.slice(0, 10).map((item) => (
                        <li
                            key={item.document_id}
                            data-testid={`stale-doc-row-${item.document_id}`}
                            style={{
                                padding: '6px 0',
                                borderBottom: '1px solid var(--hairline)',
                                fontSize: 12.5,
                            }}
                        >
                            <div style={{ color: 'var(--fg-1)' }}>
                                {item.title ?? item.slug ?? `Doc #${item.document_id}`}
                            </div>
                            <div
                                style={{
                                    fontSize: 11,
                                    color: 'var(--fg-3)',
                                    fontFamily: 'var(--font-mono)',
                                }}
                            >
                                {item.project_key} · indexed {item.indexed_at ?? 'never'} ·
                                {' '}neg ratio {(item.negative_rating_ratio * 100).toFixed(1)}%
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
