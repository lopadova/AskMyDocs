import type { ApiConnector } from '../api-connectors/api-connectors.api';

/**
 * A gallery card for ONE saved API connection (spec Obj — "salvare una
 * connessione API dalla stessa pagina"). The API connector is a different
 * paradigm from the ingest sources: instead of syncing docs it turns HTTP
 * endpoints into live chat tools. Creation + this summary live in the unified
 * gallery; the deep management (routes / auth profiles / relations / tests) is a
 * page's worth of surface, reached via "Manage".
 *
 * Presentational: owns no state, delegates every action to callbacks.
 * R11/R29 testids `api-connection-tile-{id}[-…]`; R15 bound labels on icon-ish
 * actions; R14 nothing silent.
 */

export interface ApiConnectionTileProps {
    connector: ApiConnector;
    onManage: () => void;
    onEdit: (connector: ApiConnector) => void;
    onRemove: (connector: ApiConnector) => void;
    compact?: boolean;
}

function hostOf(url: string | null): string {
    if (!url) return 'no base URL';
    try {
        return new URL(url).host || url;
    } catch {
        return url;
    }
}

export function ApiConnectionTile({ connector, onManage, onEdit, onRemove, compact = false }: ApiConnectionTileProps) {
    const routes = connector.routes ?? [];
    const activeTools = routes.filter((r) => r.status === 'active').length;
    const base = `api-connection-tile-${connector.id}`;

    return (
        <div
            data-testid={base}
            data-active={connector.is_active}
            style={{
                display: 'flex',
                flexDirection: compact ? 'row' : 'column',
                alignItems: compact ? 'center' : undefined,
                flexWrap: compact ? 'wrap' : undefined,
                gap: compact ? 12 : 8,
                padding: compact ? 13 : 14,
                border: '1px solid var(--hairline)',
                borderRadius: 12,
                background: 'var(--bg-1)',
            }}
        >
            {compact && <span aria-hidden="true" style={apiAvatarStyle}><ApiIcon /></span>}
            <div style={{ flex: 1, minWidth: 210 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <span style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--fg-0)' }}>{connector.name}</span>
                    {!connector.is_active && (
                        <span data-testid={`${base}-disabled`} style={disabledBadgeStyle}>Disabled</span>
                    )}
                </div>
                <div
                    data-testid={`${base}-base-url`}
                    style={{ marginTop: 4, fontSize: 11.5, color: 'var(--fg-3)', fontFamily: 'var(--mono, ui-monospace, monospace)' }}
                >
                    {hostOf(connector.base_url)}
                </div>
                <div data-testid={`${base}-routes`} style={compact ? compactSummaryStyle : { marginTop: 7, fontSize: 12.5, color: 'var(--fg-2)' }}>
                    {routes.length} route{routes.length === 1 ? '' : 's'} · {activeTools} active tool
                    {activeTools === 1 ? '' : 's'}
                </div>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: compact ? 0 : 4, marginLeft: compact ? 'auto' : 0 }}>
                <button
                    type="button"
                    data-testid={`${base}-manage`}
                    className="focus-ring"
                    onClick={onManage}
                    style={{
                        fontSize: 12.5,
                        fontWeight: 600,
                        padding: '6px 12px',
                        borderRadius: 8,
                        border: '1px solid var(--hairline)',
                        background: 'var(--bg-2)',
                        color: 'var(--fg-0)',
                        cursor: 'pointer',
                    }}
                >
                    Manage
                </button>
                {!compact && <div style={{ flex: 1 }} />}
                <button
                    type="button"
                    data-testid={`${base}-edit`}
                    className="focus-ring"
                    aria-label={`Edit ${connector.name}`}
                    onClick={() => onEdit(connector)}
                    style={{
                        fontSize: 12.5,
                        padding: '6px 10px',
                        borderRadius: 8,
                        border: '1px solid var(--hairline)',
                        background: 'transparent',
                        color: 'var(--fg-2)',
                        cursor: 'pointer',
                    }}
                >
                    Edit
                </button>
                <button
                    type="button"
                    data-testid={`${base}-remove`}
                    className="focus-ring"
                    aria-label={`Remove ${connector.name}`}
                    onClick={() => onRemove(connector)}
                    style={{
                        fontSize: 12.5,
                        padding: '6px 10px',
                        borderRadius: 8,
                        border: '1px solid var(--hairline)',
                        background: 'transparent',
                        color: '#fca5a5',
                        cursor: 'pointer',
                    }}
                >
                    Remove
                </button>
            </div>
        </div>
    );
}

function ApiIcon() {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d="m8 9-3 3 3 3" />
            <path d="m16 9 3 3-3 3" />
            <path d="m14 5-4 14" />
        </svg>
    );
}

const apiAvatarStyle: React.CSSProperties = {
    width: 36,
    height: 36,
    display: 'grid',
    placeItems: 'center',
    flex: 'none',
    borderRadius: 9,
    color: '#c4b5fd',
    background: 'rgba(139,92,246,.09)',
    border: '1px solid rgba(139,92,246,.2)',
};

const disabledBadgeStyle: React.CSSProperties = {
    fontSize: 10.5,
    fontWeight: 600,
    color: 'var(--fg-3)',
    background: 'var(--bg-2)',
    borderRadius: 999,
    padding: '1px 7px',
};

const compactSummaryStyle: React.CSSProperties = {
    display: 'inline-block',
    marginTop: 8,
    padding: '3px 8px',
    borderRadius: 999,
    background: 'var(--bg-2)',
    color: 'var(--fg-2)',
    fontSize: 10.5,
};
