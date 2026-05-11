import { useState } from 'react';
import type { ConnectorEntry } from './connectors.api';
import { derivedStatus, formatRelative, statusBadgeStyle } from './status-utils';

/*
 * Single connector card. Stateless aside from the inline "Confirm
 * disconnect" toggle which lives here so multiple cards on the page
 * don't share the same confirming state by accident.
 *
 * Actions are gated by `derivedStatus(entry)`:
 *   - not_installed → Connect
 *   - pending       → Cancel install (calls disconnect — removes the
 *                     PENDING row so the operator can retry from scratch)
 *   - active        → Sync now + Disconnect
 *   - errored       → Retry sync + Disconnect
 *   - disabled      → Re-enable (re-install) + Disconnect
 *
 * R11: every interactive surface has a stable, hierarchical
 *      `data-testid="connector-{key}-{action}"`.
 * R15: icon-only buttons carry `aria-label`; the card itself uses
 *      `aria-label` for the connector name + status combo so
 *      screen-readers announce the row in context.
 */

export interface ConnectorCardProps {
    entry: ConnectorEntry;
    onConnect: (key: string) => void;
    onSync: (installationId: number) => void;
    onDisconnect: (installationId: number) => void;
    onCancelInstall: (installationId: number) => void;
    pending: {
        connecting: boolean;
        syncing: boolean;
        disconnecting: boolean;
    };
    now?: Date;
}

export function ConnectorCard({
    entry,
    onConnect,
    onSync,
    onDisconnect,
    onCancelInstall,
    pending,
    now,
}: ConnectorCardProps) {
    const status = derivedStatus(entry);
    const badge = statusBadgeStyle(status);
    const lastSync = formatRelative(entry.installation?.last_sync_at ?? null, now);
    const [confirmingDisconnect, setConfirmingDisconnect] = useState(false);

    return (
        <div
            role="group"
            aria-label={`${entry.display_name} — ${badge.label}`}
            data-testid={`connector-list-card-${entry.key}`}
            data-status={status}
            style={{
                padding: 16,
                borderRadius: 12,
                border: '1px solid var(--hairline)',
                background: 'var(--bg-1)',
                display: 'flex',
                flexDirection: 'column',
                gap: 12,
                minHeight: 200,
                opacity: status === 'pending' ? 0.7 : 1,
                position: 'relative',
            }}
        >
            {status === 'pending' && (
                <div
                    data-testid={`connector-${entry.key}-spinner`}
                    aria-hidden="true"
                    style={{
                        position: 'absolute',
                        top: 10,
                        right: 10,
                        width: 12,
                        height: 12,
                        border: '2px solid rgba(250, 204, 21, 0.25)',
                        borderTopColor: '#fbbf24',
                        borderRadius: '50%',
                        animation: 'amd-spin 0.8s linear infinite',
                    }}
                />
            )}
            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <img
                    src={entry.icon_url}
                    alt=""
                    width={36}
                    height={36}
                    data-testid={`connector-${entry.key}-icon`}
                    style={{ borderRadius: 8, background: 'var(--bg-2)' }}
                    onError={(e) => {
                        // Hide a broken icon rather than rendering the
                        // missing-image glyph. The alt is empty so SR
                        // users aren't penalised for the failure.
                        (e.currentTarget as HTMLImageElement).style.visibility = 'hidden';
                    }}
                />
                <div style={{ flex: 1, minWidth: 0 }}>
                    <div
                        data-testid={`connector-${entry.key}-name`}
                        style={{
                            fontSize: 15,
                            fontWeight: 600,
                            color: 'var(--fg-0)',
                            letterSpacing: '-0.01em',
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                        }}
                    >
                        {entry.display_name}
                    </div>
                    <div
                        style={{
                            fontSize: 11,
                            color: 'var(--fg-3)',
                            fontFamily: 'var(--font-mono)',
                            marginTop: 2,
                        }}
                    >
                        {entry.key}
                    </div>
                </div>
            </div>

            <div>
                <span
                    data-testid={`connector-${entry.key}-status`}
                    data-status-value={status}
                    style={{
                        display: 'inline-block',
                        padding: '3px 10px',
                        borderRadius: 99,
                        fontSize: 11.5,
                        fontWeight: 500,
                        background: badge.background,
                        border: `1px solid ${badge.border}`,
                        color: badge.color,
                    }}
                >
                    {badge.label}
                </span>
                {status === 'active' && lastSync !== null && (
                    <span
                        data-testid={`connector-${entry.key}-last-sync`}
                        style={{
                            marginLeft: 8,
                            fontSize: 11.5,
                            color: 'var(--fg-3)',
                        }}
                    >
                        Last sync {lastSync}
                    </span>
                )}
            </div>

            {status === 'errored' && entry.installation?.error && (
                <div
                    data-testid={`connector-${entry.key}-error`}
                    role="alert"
                    style={{
                        fontSize: 12,
                        color: '#fca5a5',
                        padding: 8,
                        borderRadius: 8,
                        background: 'rgba(239, 68, 68, 0.08)',
                        border: '1px solid rgba(239, 68, 68, 0.30)',
                        lineHeight: 1.45,
                    }}
                >
                    {String(entry.installation.error.message ?? 'Connector reported an error.')}
                </div>
            )}

            <div style={{ flex: 1 }} />

            <div
                style={{
                    display: 'flex',
                    gap: 8,
                    flexWrap: 'wrap',
                }}
            >
                {status === 'not_installed' && (
                    <button
                        type="button"
                        data-testid={`connector-${entry.key}-connect`}
                        className="focus-ring"
                        disabled={pending.connecting}
                        onClick={() => onConnect(entry.key)}
                        style={primaryButton(pending.connecting)}
                    >
                        {pending.connecting ? 'Connecting…' : 'Connect'}
                    </button>
                )}

                {status === 'pending' && entry.installation && (
                    <button
                        type="button"
                        data-testid={`connector-${entry.key}-cancel-install`}
                        className="focus-ring"
                        onClick={() => onCancelInstall(entry.installation!.id)}
                        style={ghostButton()}
                    >
                        Cancel install
                    </button>
                )}

                {(status === 'active' || status === 'errored') && entry.installation && (
                    <>
                        <button
                            type="button"
                            data-testid={`connector-${entry.key}-sync`}
                            className="focus-ring"
                            disabled={pending.syncing}
                            onClick={() => onSync(entry.installation!.id)}
                            style={primaryButton(pending.syncing)}
                        >
                            {pending.syncing
                                ? 'Queuing…'
                                : status === 'errored'
                                  ? 'Retry sync'
                                  : 'Sync now'}
                        </button>
                        {!confirmingDisconnect && (
                            <button
                                type="button"
                                data-testid={`connector-${entry.key}-disconnect`}
                                className="focus-ring"
                                onClick={() => setConfirmingDisconnect(true)}
                                style={ghostButton()}
                            >
                                Disconnect
                            </button>
                        )}
                        {confirmingDisconnect && (
                            <>
                                <button
                                    type="button"
                                    data-testid={`connector-${entry.key}-disconnect-confirm`}
                                    className="focus-ring"
                                    disabled={pending.disconnecting}
                                    onClick={() => {
                                        onDisconnect(entry.installation!.id);
                                        setConfirmingDisconnect(false);
                                    }}
                                    style={dangerButton(pending.disconnecting)}
                                >
                                    Confirm disconnect
                                </button>
                                <button
                                    type="button"
                                    data-testid={`connector-${entry.key}-disconnect-cancel`}
                                    className="focus-ring"
                                    onClick={() => setConfirmingDisconnect(false)}
                                    style={ghostButton()}
                                >
                                    Cancel
                                </button>
                            </>
                        )}
                    </>
                )}

                {status === 'disabled' && entry.installation && (
                    <>
                        <button
                            type="button"
                            data-testid={`connector-${entry.key}-reenable`}
                            className="focus-ring"
                            onClick={() => onConnect(entry.key)}
                            style={primaryButton(pending.connecting)}
                        >
                            Re-enable
                        </button>
                        <button
                            type="button"
                            data-testid={`connector-${entry.key}-disconnect`}
                            className="focus-ring"
                            onClick={() => onDisconnect(entry.installation!.id)}
                            style={ghostButton()}
                        >
                            Remove
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}

function primaryButton(disabled: boolean): React.CSSProperties {
    return {
        padding: '6px 14px',
        fontSize: 12.5,
        background: disabled ? 'var(--bg-3)' : 'var(--grad-accent)',
        color: '#fff',
        border: '1px solid transparent',
        borderRadius: 8,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.7 : 1,
    };
}

function ghostButton(): React.CSSProperties {
    return {
        padding: '6px 12px',
        fontSize: 12.5,
        background: 'transparent',
        color: 'var(--fg-1)',
        border: '1px solid var(--hairline)',
        borderRadius: 8,
        cursor: 'pointer',
    };
}

function dangerButton(disabled: boolean): React.CSSProperties {
    return {
        padding: '6px 12px',
        fontSize: 12.5,
        background: disabled ? 'rgba(239, 68, 68, 0.4)' : 'rgba(239, 68, 68, 0.85)',
        color: '#fff',
        border: '1px solid rgba(239, 68, 68, 0.6)',
        borderRadius: 8,
        cursor: disabled ? 'not-allowed' : 'pointer',
    };
}
