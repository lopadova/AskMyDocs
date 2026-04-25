import type { SuggestedTagsRow } from './insights.api';

export interface SuggestedTagsCardProps {
    items: SuggestedTagsRow[];
}

export function SuggestedTagsCard({ items }: SuggestedTagsCardProps) {
    const state = items.length === 0 ? 'empty' : 'ready';
    return (
        <div
            data-testid="insight-card-suggested-tags"
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
            <h2 style={{ margin: 0, fontSize: 13, color: 'var(--fg-0)' }}>Suggested tags</h2>
            {state === 'empty' ? (
                <div
                    data-testid="insight-card-suggested-tags-empty"
                    style={{ fontSize: 12, color: 'var(--fg-3)' }}
                >
                    No tag proposals in the latest snapshot.
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
                    {items.slice(0, 10).map((item) => (
                        <li
                            key={item.document_id}
                            data-testid={`suggested-tag-row-${item.document_id}`}
                            style={{
                                padding: '6px 0',
                                borderBottom: '1px solid var(--hairline)',
                                fontSize: 12.5,
                            }}
                        >
                            <div style={{ color: 'var(--fg-1)', marginBottom: 4 }}>
                                {item.title ?? item.slug ?? `Doc #${item.document_id}`}
                            </div>
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                                {item.tags_proposed.map((tag) => (
                                    <span
                                        key={tag}
                                        data-testid={`suggested-tag-${item.document_id}-${tag}`}
                                        style={{
                                            padding: '2px 8px',
                                            borderRadius: 999,
                                            border: '1px solid var(--hairline)',
                                            fontSize: 10.5,
                                            fontFamily: 'var(--font-mono)',
                                            color: 'var(--fg-2)',
                                            background: 'var(--bg-0)',
                                        }}
                                    >
                                        #{tag}
                                    </span>
                                ))}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
