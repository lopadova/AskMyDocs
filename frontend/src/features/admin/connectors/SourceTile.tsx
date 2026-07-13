import { useRef } from 'react';
import type { ConnectorEntry } from './connectors.api';
import { SourceAvatar } from './SourceAvatar';

/*
 * One tile in the "Available sources" grid: the source icon + name + key, a
 * count badge (accounts connected on this source), an "Add connection" (+)
 * button, and — for credential connectors (IMAP) — an Import affordance backed
 * by a hidden file input (prefills a new account from an exported config).
 *
 * R15/R29 — the "+" is an icon button with an accessible name; the import
 * button + hidden input keep their established testids
 * (`connector-{key}-import-account` / `connector-{key}-import-file`).
 */

export interface SourceTileProps {
    entry: ConnectorEntry;
    connectionCount: number;
    onAdd: (key: string) => void;
    onImport?: (key: string, file: File) => void;
    /** An add/connect for THIS source is in flight. */
    addPending?: boolean;
}

export function SourceTile({ entry, connectionCount, onAdd, onImport, addPending }: SourceTileProps) {
    const importInputRef = useRef<HTMLInputElement>(null);
    const isCredential = entry.auth_kind === 'credential';

    return (
        <div
            data-testid={`connector-source-${entry.key}`}
            data-connection-count={connectionCount}
            className="amd-cn-src-tile"
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 12,
                padding: '13px 13px 13px 14px',
                background: 'var(--bg-1)',
                border: '1px solid var(--hairline)',
                borderRadius: 12,
            }}
        >
            <SourceAvatar
                connectorKey={entry.key}
                displayName={entry.display_name}
                iconUrl={entry.icon_url}
                size={36}
                radius={9}
            />
            <div style={{ flex: 1, minWidth: 0 }}>
                <div
                    style={{
                        fontSize: 13.5,
                        fontWeight: 600,
                        lineHeight: 1.15,
                        color: 'var(--fg-0)',
                        whiteSpace: 'nowrap',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                    }}
                >
                    {entry.display_name}
                </div>
                <div
                    style={{
                        fontFamily: 'var(--font-mono)',
                        fontSize: 11,
                        color: 'var(--fg-3)',
                        marginTop: 2,
                    }}
                >
                    {entry.key}
                </div>
            </div>

            {connectionCount > 0 && (
                <span
                    data-testid={`connector-source-${entry.key}-count`}
                    aria-label={`${connectionCount} connected`}
                    style={{
                        flex: 'none',
                        fontSize: 11.5,
                        fontWeight: 600,
                        color: 'var(--accent-a)',
                        background: 'var(--grad-accent-soft)',
                        border: '1px solid rgba(139,92,246,.3)',
                        padding: '2px 7px',
                        borderRadius: 999,
                    }}
                >
                    {connectionCount}
                </span>
            )}

            {isCredential && onImport && (
                <>
                    <input
                        ref={importInputRef}
                        type="file"
                        accept="application/json,.json"
                        data-testid={`connector-${entry.key}-import-file`}
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            // Reset first so re-selecting the SAME file still fires change.
                            e.target.value = '';
                            if (file) onImport(entry.key, file);
                        }}
                        style={{ display: 'none' }}
                    />
                    <button
                        type="button"
                        data-testid={`connector-${entry.key}-import-account`}
                        className="amd-cn-icon-btn focus-ring"
                        aria-label={`Import ${entry.display_name} configuration`}
                        title="Import configuration"
                        disabled={addPending}
                        onClick={() => importInputRef.current?.click()}
                        style={iconButtonStyle}
                    >
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <path d="M17 8l-5-5-5 5" />
                            <path d="M12 3v12" />
                        </svg>
                    </button>
                </>
            )}

            <button
                type="button"
                data-testid={`connector-${entry.key}-add-account`}
                className="amd-cn-icon-btn focus-ring"
                aria-label={`Add ${entry.display_name} connection`}
                title="Add connection"
                disabled={addPending}
                onClick={() => onAdd(entry.key)}
                style={iconButtonStyle}
            >
                {addPending ? (
                    <span
                        aria-hidden="true"
                        style={{
                            width: 14,
                            height: 14,
                            border: '2px solid var(--fg-4)',
                            borderTopColor: 'var(--fg-1)',
                            borderRadius: 999,
                            display: 'inline-block',
                            animation: 'amd-cn-spin .7s linear infinite',
                        }}
                    />
                ) : (
                    <svg
                        width="17"
                        height="17"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                )}
            </button>
        </div>
    );
}

const iconButtonStyle: React.CSSProperties = {
    flex: 'none',
    width: 32,
    height: 32,
    borderRadius: 9,
    border: '1px solid var(--hairline)',
    background: 'var(--bg-2)',
    color: 'var(--fg-1)',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    cursor: 'pointer',
};
