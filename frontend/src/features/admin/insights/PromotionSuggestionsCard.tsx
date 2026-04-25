import type { PromotionSuggestion } from './insights.api';

export interface PromotionSuggestionsCardProps {
    items: PromotionSuggestion[];
}

/*
 * Phase I — top-N non-canonical docs that are cited often. "Promote"
 * sends the user to the canonical promotion API endpoint (already
 * exposed in main by Phase 4). We don't issue the POST inline — the
 * promotion flow itself is human-gated (ADR 0003) and surfaces a
 * larger dialog in the canonical pipeline; the card link is a pivot.
 */
export function PromotionSuggestionsCard({ items }: PromotionSuggestionsCardProps) {
    const state = items.length === 0 ? 'empty' : 'ready';
    return (
        <div
            data-testid="insight-card-promotions"
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
            <h2 style={{ margin: 0, fontSize: 13, color: 'var(--fg-0)' }}>
                Promotion candidates
            </h2>
            {state === 'empty' ? (
                <div
                    data-testid="insight-card-promotions-empty"
                    style={{ fontSize: 12, color: 'var(--fg-3)' }}
                >
                    No non-canonical docs crossed the citation threshold.
                </div>
            ) : (
                <ul
                    style={{
                        margin: 0,
                        padding: 0,
                        listStyle: 'none',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 8,
                    }}
                >
                    {items.slice(0, 10).map((item) => (
                        <li
                            key={item.document_id}
                            data-testid={`promotion-row-${item.document_id}`}
                            style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                gap: 10,
                                padding: '6px 0',
                                borderBottom: '1px solid var(--hairline)',
                                fontSize: 12.5,
                            }}
                        >
                            <div style={{ minWidth: 0 }}>
                                <div
                                    style={{
                                        color: 'var(--fg-1)',
                                        overflow: 'hidden',
                                        textOverflow: 'ellipsis',
                                        whiteSpace: 'nowrap',
                                    }}
                                >
                                    {item.title ?? item.slug ?? `Doc #${item.document_id}`}
                                </div>
                                <div
                                    style={{
                                        fontSize: 11,
                                        color: 'var(--fg-3)',
                                        fontFamily: 'var(--font-mono)',
                                    }}
                                >
                                    {item.project_key} · {item.score} citations
                                </div>
                            </div>
                            <button
                                type="button"
                                data-testid={`promotions-action-promote-${item.document_id}`}
                                // Clicking is a soft pivot — the actual promote
                                // flow is a multi-step dialog in the canonical
                                // pipeline (Phase 4). For now we surface the
                                // doc id so the E2E can assert the chain.
                                onClick={() => {
                                    window.location.assign(
                                        `/app/admin/kb?doc=${item.document_id}&tab=meta&promote=1`,
                                    );
                                }}
                                style={{
                                    fontSize: 11,
                                    padding: '4px 10px',
                                    borderRadius: 4,
                                    border: '1px solid var(--accent, #3b82f6)',
                                    background: 'transparent',
                                    color: 'var(--accent, #3b82f6)',
                                    cursor: 'pointer',
                                    flexShrink: 0,
                                }}
                            >
                                Promote
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
