/*
 * API Connector launcher card — shown in the unified Connectors gallery
 * (`ConnectorsView`) alongside the ingest connectors (IMAP, Notion, …).
 *
 * The API connector (`padosoft/askmydocs-connector-api`) is a DIFFERENT
 * paradigm from the ingest connectors: instead of installing accounts and
 * syncing documents, each configured HTTP endpoint (Rotta) becomes a live LLM
 * tool the chat can call. It therefore has no "accounts" to manage inline —
 * this card is a launcher that summarises the configured state and opens the
 * dedicated API Connectors page.
 *
 * Presentational only (R11/R16): data + navigation are wired by the parent and
 * passed as props, so the card is unit-tested without a query/router context.
 * R14 — a failed status probe degrades to a muted "status unavailable" line;
 * the card and its CTA stay usable (the CTA never depends on the count).
 * R15 — the CTA is a keyboard-reachable button with an accessible name; the
 * icon is decorative (aria-hidden).
 */

export interface ApiConnectorLauncherCardProps {
    /** Number of API connectors configured for the active tenant. */
    connectorCount: number;
    /** Number of routes across those connectors currently in the `active` state. */
    activeToolCount: number;
    /** The status probe (useApiConnectors) is in flight. */
    isLoading?: boolean;
    /** The status probe failed — show the card + CTA, omit the (unknown) counts. */
    isError?: boolean;
    /** Navigate to the dedicated API Connectors admin page. */
    onOpen: () => void;
}

export function ApiConnectorLauncherCard({
    connectorCount,
    activeToolCount,
    isLoading = false,
    isError = false,
    onOpen,
}: ApiConnectorLauncherCardProps) {
    const configured = connectorCount > 0;
    const state = isLoading ? 'loading' : isError ? 'error' : 'ready';

    return (
        <div
            role="group"
            aria-label="API Connector — turn HTTP endpoints into live chat tools"
            data-testid="api-connector-launcher-card"
            data-state={state}
            data-connector-count={connectorCount}
            style={{
                padding: 16,
                borderRadius: 12,
                border: '1px solid var(--hairline)',
                background: 'var(--bg-1)',
                display: 'flex',
                flexDirection: 'column',
                gap: 12,
                minHeight: 200,
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <span
                    aria-hidden="true"
                    data-testid="api-connector-launcher-icon"
                    style={{
                        width: 36,
                        height: 36,
                        borderRadius: 8,
                        background: 'var(--bg-2)',
                        color: 'var(--accent, #818cf8)',
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        flexShrink: 0,
                    }}
                >
                    <svg viewBox="0 0 24 24" width={22} height={22}>
                        <path
                            fill="none"
                            stroke="currentColor"
                            strokeWidth={1.6}
                            strokeLinecap="round"
                            d="M12 12 5.5 5.5M12 12l6.5-6.5M12 12v6.8"
                        />
                        <circle cx="12" cy="12" r="2.4" fill="currentColor" />
                        <circle cx="5.5" cy="5.5" r="1.8" fill="currentColor" />
                        <circle cx="18.5" cy="5.5" r="1.8" fill="currentColor" />
                        <circle cx="12" cy="19.6" r="1.8" fill="currentColor" />
                    </svg>
                </span>
                <div style={{ flex: 1, minWidth: 0 }}>
                    <div
                        data-testid="api-connector-launcher-name"
                        style={{
                            fontSize: 15,
                            fontWeight: 600,
                            color: 'var(--fg-0)',
                            letterSpacing: '-0.01em',
                        }}
                    >
                        API Connector
                    </div>
                    <div
                        style={{
                            fontSize: 11,
                            color: 'var(--fg-3)',
                            fontFamily: 'var(--font-mono)',
                            marginTop: 2,
                        }}
                    >
                        live tools
                    </div>
                </div>
                <button
                    type="button"
                    data-testid="api-connector-launcher-open"
                    className="focus-ring"
                    onClick={onOpen}
                    style={{
                        padding: '6px 14px',
                        fontSize: 12.5,
                        background: 'var(--grad-accent)',
                        color: '#fff',
                        border: '1px solid transparent',
                        borderRadius: 8,
                        cursor: 'pointer',
                    }}
                >
                    {configured ? 'Manage' : 'Get started'}
                </button>
            </div>

            <p style={{ margin: 0, fontSize: 12.5, color: 'var(--fg-2)', lineHeight: 1.45 }}>
                Turn any HTTP endpoint into a live tool the chat can call — grounded RAG answers
                <strong style={{ color: 'var(--fg-1)' }}> plus </strong>
                fresh data fetched from your APIs in the same turn.
            </p>

            <div
                data-testid="api-connector-launcher-status"
                role="status"
                style={{
                    marginTop: 'auto',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    minHeight: 44,
                    borderRadius: 8,
                    border: '1px dashed var(--hairline)',
                    padding: 12,
                    fontSize: 12.5,
                    color: 'var(--fg-3)',
                    textAlign: 'center',
                }}
            >
                {isLoading && <span aria-busy="true">Loading status…</span>}
                {!isLoading && isError && <span>Status unavailable — open to manage.</span>}
                {!isLoading && !isError && !configured && (
                    <span data-testid="api-connector-launcher-empty">
                        No API connectors yet. Get started to expose an endpoint as a chat tool.
                    </span>
                )}
                {!isLoading && !isError && configured && (
                    <span data-testid="api-connector-launcher-count">
                        <strong style={{ color: 'var(--fg-1)' }}>{connectorCount}</strong>{' '}
                        {connectorCount === 1 ? 'connector' : 'connectors'} ·{' '}
                        <strong style={{ color: 'var(--fg-1)' }}>{activeToolCount}</strong>{' '}
                        active {activeToolCount === 1 ? 'tool' : 'tools'}
                    </span>
                )}
            </div>
        </div>
    );
}
