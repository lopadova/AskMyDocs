import type { CSSProperties, ReactNode } from 'react';

interface ConnectionTypeTileProps {
    kind: 'api' | 'mcp';
    title: string;
    description: string;
    onAdd: () => void;
}

/**
 * An action tile for connection types that are not backed by a registered
 * document connector. It deliberately follows SourceTile's visual grammar so
 * API and MCP feel like first-class ways to add a connection.
 */
export function ConnectionTypeTile({ kind, title, description, onAdd }: ConnectionTypeTileProps) {
    const testId = `connector-source-${kind}`;

    return (
        <div data-testid={testId} className="amd-cn-src-tile" style={tileStyle}>
            <span aria-hidden="true" style={{ ...avatarStyle, color: kind === 'mcp' ? '#67e8f9' : '#c4b5fd' }}>
                {kind === 'mcp' ? <McpIcon /> : <ApiIcon />}
            </span>
            <div style={{ flex: 1, minWidth: 0 }}>
                <div style={titleStyle}>{title}</div>
                <div style={descriptionStyle}>{description}</div>
            </div>
            <button
                type="button"
                data-testid={`connector-${kind}-add-connection`}
                className="amd-cn-icon-btn focus-ring"
                aria-label={`Add ${title}`}
                title={`Add ${title}`}
                onClick={onAdd}
                style={iconButtonStyle}
            >
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                    <path d="M12 5v14" />
                    <path d="M5 12h14" />
                </svg>
            </button>
        </div>
    );
}

function ApiIcon(): ReactNode {
    return (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d="m8 9-3 3 3 3" />
            <path d="m16 9 3 3-3 3" />
            <path d="m14 5-4 14" />
        </svg>
    );
}

function McpIcon(): ReactNode {
    return (
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="6" cy="7" r="2.5" />
            <circle cx="18" cy="7" r="2.5" />
            <circle cx="12" cy="17" r="2.5" />
            <path d="m8.2 8.2 2.6 6.5M15.8 8.2l-2.6 6.5M8.5 7h7" />
        </svg>
    );
}

const tileStyle: CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: 12,
    padding: '13px 13px 13px 14px',
    background: 'linear-gradient(135deg, var(--bg-1), color-mix(in srgb, var(--accent) 5%, var(--bg-1)))',
    border: '1px solid color-mix(in srgb, var(--accent) 24%, var(--hairline))',
    borderRadius: 12,
};

const avatarStyle: CSSProperties = {
    width: 36,
    height: 36,
    flex: 'none',
    display: 'grid',
    placeItems: 'center',
    borderRadius: 9,
    border: '1px solid color-mix(in srgb, currentColor 24%, var(--hairline))',
    background: 'color-mix(in srgb, currentColor 9%, var(--bg-2))',
};

const titleStyle: CSSProperties = {
    color: 'var(--fg-0)',
    fontSize: 13.5,
    fontWeight: 650,
    lineHeight: 1.15,
};

const descriptionStyle: CSSProperties = {
    marginTop: 3,
    color: 'var(--fg-3)',
    fontSize: 11,
    whiteSpace: 'nowrap',
    overflow: 'hidden',
    textOverflow: 'ellipsis',
};

const iconButtonStyle: CSSProperties = {
    flex: 'none',
    width: 32,
    height: 32,
    borderRadius: 9,
    border: '1px solid color-mix(in srgb, var(--accent) 35%, var(--hairline))',
    background: 'var(--grad-accent-soft)',
    color: 'var(--fg-0)',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    cursor: 'pointer',
};
