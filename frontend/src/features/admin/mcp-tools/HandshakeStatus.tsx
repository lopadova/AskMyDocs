import type { McpServerEntry } from './mcp-tools.api';

interface HandshakeStatusProps {
    server: McpServerEntry;
    onRetry: () => void;
    busy: boolean;
}

/*
 * v5.0/W2 — Shows the latest MCP handshake outcome for a server. Three
 * visual states:
 *   - never handshaked (pending) — neutral pill + CTA
 *   - successful — green pill + discovered tools count + last_handshake_at
 *   - errored — red pill + error message + retry CTA
 */
export function HandshakeStatus({ server, onRetry, busy }: HandshakeStatusProps) {
    const stateLabel = useStateLabel(server);
    const stateColor = useStateColor(server.status);
    const ariaState = busy ? 'busy' : 'ready';

    return (
        <div
            data-testid={`admin-mcp-server-${server.id}-handshake`}
            data-state={ariaState}
            aria-busy={busy}
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
                padding: '12px 14px',
                borderRadius: 12,
                border: '1px solid var(--border-1)',
                background: 'var(--bg-2)',
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span
                    data-testid={`admin-mcp-server-${server.id}-status-pill`}
                    style={{
                        padding: '2px 10px',
                        borderRadius: 999,
                        background: stateColor.background,
                        color: stateColor.color,
                        fontSize: 12,
                        fontWeight: 600,
                        letterSpacing: '0.02em',
                        textTransform: 'uppercase',
                    }}
                >
                    {stateLabel}
                </span>
                <span style={{ fontSize: 12.5, color: 'var(--fg-2)' }}>
                    {server.last_handshake_at
                        ? `Last handshake ${formatRelative(server.last_handshake_at)}`
                        : 'Not yet contacted'}
                </span>
                <button
                    type="button"
                    data-testid={`admin-mcp-server-${server.id}-handshake-retry`}
                    onClick={onRetry}
                    disabled={busy}
                    style={{
                        marginLeft: 'auto',
                        padding: '4px 10px',
                        borderRadius: 8,
                        border: '1px solid var(--border-2)',
                        background: busy ? 'var(--bg-2)' : 'var(--accent-bg)',
                        color: busy ? 'var(--fg-2)' : 'var(--accent-fg)',
                        cursor: busy ? 'wait' : 'pointer',
                        fontSize: 12.5,
                        fontWeight: 600,
                    }}
                >
                    {busy ? 'Handshaking…' : 'Run handshake'}
                </button>
            </div>
            {renderResponseSummary(server)}
        </div>
    );
}

function renderResponseSummary(server: McpServerEntry) {
    const response = server.handshake_response;
    if (!response) {
        return null;
    }

    if (server.status === 'errored') {
        const message = response.message ?? 'Unknown handshake error';
        return (
            <p
                data-testid={`admin-mcp-server-${server.id}-handshake-error`}
                style={{ margin: 0, fontSize: 12.5, color: 'var(--danger-fg)' }}
            >
                {message}
            </p>
        );
    }

    const toolsCount = response.tools?.length ?? 0;
    const protocol = response.protocol_version ?? response.server_info?.version ?? 'unknown';
    return (
        <div
            data-testid={`admin-mcp-server-${server.id}-handshake-summary`}
            style={{
                display: 'flex',
                flexWrap: 'wrap',
                gap: 14,
                fontSize: 12.5,
                color: 'var(--fg-2)',
            }}
        >
            <span>Protocol: <strong style={{ color: 'var(--fg-1)' }}>{protocol}</strong></span>
            <span>Tools discovered: <strong style={{ color: 'var(--fg-1)' }}>{toolsCount}</strong></span>
            {typeof response.duration_ms === 'number' ? (
                <span>Round-trip: <strong style={{ color: 'var(--fg-1)' }}>{response.duration_ms} ms</strong></span>
            ) : null}
        </div>
    );
}

function useStateLabel(server: McpServerEntry): string {
    switch (server.status) {
        case 'active':
            return 'Active';
        case 'disabled':
            return 'Disabled';
        case 'errored':
            return 'Errored';
        case 'pending':
        default:
            return 'Pending';
    }
}

function useStateColor(status: McpServerEntry['status']): { background: string; color: string } {
    switch (status) {
        case 'active':
            return { background: 'rgba(34,197,94,0.18)', color: '#86efac' };
        case 'disabled':
            return { background: 'rgba(148,163,184,0.18)', color: '#cbd5e1' };
        case 'errored':
            return { background: 'rgba(239,68,68,0.18)', color: '#fca5a5' };
        case 'pending':
        default:
            return { background: 'rgba(59,130,246,0.18)', color: '#93c5fd' };
    }
}

function formatRelative(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return 'unknown';
    }
    const diffSec = Math.max(0, (Date.now() - date.getTime()) / 1000);
    if (diffSec < 60) return 'just now';
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)} min ago`;
    if (diffSec < 86_400) return `${Math.floor(diffSec / 3600)} h ago`;
    return date.toLocaleString();
}
