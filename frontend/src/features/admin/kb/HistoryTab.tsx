import { useState } from 'react';
import { useKbHistory } from './kb-document.api';

/*
 * Phase G2 — History tab. Paginated view over `kb_canonical_audit`
 * rows filtered by (project_key, doc_id, slug) — the controller
 * handles that filter; the client just drives the page number.
 *
 * Rows show the event type + actor + ISO timestamp; `before_json`
 * / `after_json` are serialised into a collapsible detail line
 * so the editorial diff is inspectable without a modal.
 */

export interface HistoryTabProps {
    documentId: number;
}

export function HistoryTab({ documentId }: HistoryTabProps) {
    const [page, setPage] = useState(1);
    const query = useKbHistory(documentId, page);

    if (query.isLoading) {
        return (
            <div data-testid="kb-history-loading" style={{ color: 'var(--fg-3)' }}>
                Loading history…
            </div>
        );
    }

    if (query.isError || !query.data) {
        return (
            <div
                data-testid="kb-history-error"
                style={{ color: 'var(--danger-fg, #b91c1c)', fontSize: 12.5 }}
            >
                Could not load audit history.
            </div>
        );
    }

    const { data, meta } = query.data;

    if (data.length === 0) {
        return (
            <div data-testid="kb-history-empty" style={{ color: 'var(--fg-3)', fontSize: 12.5 }}>
                No audit events for this document yet.
            </div>
        );
    }

    return (
        <div data-testid="kb-history" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                {data.map((row) => (
                    <div
                        key={row.id}
                        data-testid={`kb-history-${row.id}`}
                        style={{
                            padding: 10,
                            border: '1px solid var(--hairline)',
                            borderRadius: 8,
                            background: 'var(--bg-0)',
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 4,
                        }}
                    >
                        <div
                            style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'baseline',
                                gap: 8,
                            }}
                        >
                            <div style={{ display: 'flex', gap: 8, alignItems: 'baseline' }}>
                                <span
                                    style={{
                                        padding: '1px 8px',
                                        borderRadius: 999,
                                        background: 'var(--grad-accent-soft)',
                                        color: 'var(--accent-fg)',
                                        fontSize: 10.5,
                                        fontFamily: 'var(--font-mono)',
                                        textTransform: 'uppercase',
                                        letterSpacing: '0.04em',
                                    }}
                                >
                                    {row.event_type}
                                </span>
                                <span
                                    style={{
                                        fontSize: 12,
                                        color: 'var(--fg-1)',
                                        fontFamily: 'var(--font-mono)',
                                    }}
                                >
                                    {row.actor}
                                </span>
                            </div>
                            <span
                                style={{
                                    fontSize: 10.5,
                                    color: 'var(--fg-3)',
                                    fontFamily: 'var(--font-mono)',
                                }}
                            >
                                {row.created_at ?? '—'}
                            </span>
                        </div>
                        {row.after_json || row.before_json ? (
                            <details
                                style={{ fontSize: 11.5, color: 'var(--fg-3)', marginTop: 2 }}
                            >
                                <summary style={{ cursor: 'pointer' }}>diff</summary>
                                <pre
                                    style={{
                                        fontFamily: 'var(--font-mono)',
                                        fontSize: 10.5,
                                        color: 'var(--fg-2)',
                                        background: 'var(--bg-1)',
                                        padding: 6,
                                        borderRadius: 4,
                                        margin: '4px 0 0',
                                        whiteSpace: 'pre-wrap',
                                        overflow: 'hidden',
                                    }}
                                >
{JSON.stringify({ before: row.before_json, after: row.after_json }, null, 2)}
                                </pre>
                            </details>
                        ) : null}
                    </div>
                ))}
            </div>

            <div
                data-testid="kb-history-pager"
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: 10,
                    padding: '6px 4px',
                    fontSize: 11.5,
                    color: 'var(--fg-3)',
                }}
            >
                <span>
                    Page {meta.current_page} of {meta.last_page} · {meta.total} events
                </span>
                <div style={{ display: 'flex', gap: 6 }}>
                    <button
                        type="button"
                        data-testid="kb-history-prev"
                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                        disabled={meta.current_page <= 1}
                        style={pagerBtnStyle(meta.current_page <= 1)}
                    >
                        Prev
                    </button>
                    <button
                        type="button"
                        data-testid="kb-history-next"
                        onClick={() => setPage((p) => p + 1)}
                        disabled={meta.current_page >= meta.last_page}
                        style={pagerBtnStyle(meta.current_page >= meta.last_page)}
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    );
}

function pagerBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '4px 10px',
        fontSize: 11.5,
        border: '1px solid var(--hairline)',
        background: 'var(--bg-0)',
        color: 'var(--fg-1)',
        borderRadius: 6,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.5 : 1,
    };
}
