import type { CSSProperties } from 'react';
import { SourceAvatar } from './SourceAvatar';

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
            <SourceAvatar connectorKey={kind} displayName={title} />
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

const tileStyle: CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: 12,
    padding: '13px 13px 13px 14px',
    background: 'linear-gradient(135deg, var(--bg-1), color-mix(in srgb, var(--accent) 5%, var(--bg-1)))',
    border: '1px solid color-mix(in srgb, var(--accent) 24%, var(--hairline))',
    borderRadius: 12,
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
