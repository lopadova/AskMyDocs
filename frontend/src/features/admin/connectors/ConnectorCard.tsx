import { useState } from 'react';
import type { ConnectorEntry, ConnectorInstallationDto } from './connectors.api';
import { accountStatus, formatRelative, statusBadgeStyle } from './status-utils';

/*
 * v8.20 — multi-account connector card.
 *
 * One card per connector; inside it, a LIST of connected ACCOUNTS (each a
 * `connector_installations` row, disambiguated by `label` and optionally bound
 * to a KB `project_key`). The header carries an "Add account" CTA; each account
 * row carries its own status badge + per-account actions:
 *   - pending  → Cancel install (removes the PENDING row)
 *   - active   → Sync now · Edit · Disconnect
 *   - errored  → Retry sync · Edit · Disconnect
 *   - disabled → Re-enable (add w/ same label re-arms) · Edit · Remove
 *
 * Stateless aside from the inline per-account "confirm remove" toggle, which is
 * keyed by installation id so two accounts' confirms never collide.
 *
 * R11/R29: testids are hierarchical — `connector-{key}-add-account`,
 *          `connector-account-{id}-{action}`.
 * R15: icon-only / actionable elements carry accessible names; each account row
 *      is a `role="group"` announcing label + status.
 */

export interface ConnectorCardProps {
    entry: ConnectorEntry;
    onAddAccount: (key: string) => void;
    onSync: (installationId: number) => void;
    onDisable: (installationId: number) => void;
    onRemove: (installationId: number) => void;
    onEdit: (installation: ConnectorInstallationDto) => void;
    onCancelInstall: (installationId: number) => void;
    /** installation id whose sync is in flight, or null. */
    syncingId?: number | null;
    /** installation id whose disable/remove is in flight, or null. */
    busyId?: number | null;
    /** an add/connect for THIS connector is in flight. */
    addPending?: boolean;
    now?: Date;
}

export function ConnectorCard({
    entry,
    onAddAccount,
    onSync,
    onDisable,
    onRemove,
    onEdit,
    onCancelInstall,
    syncingId,
    busyId,
    addPending,
    now,
}: ConnectorCardProps) {
    const accounts = entry.installations ?? [];

    return (
        <div
            role="group"
            aria-label={`${entry.display_name} — ${accounts.length} account${accounts.length === 1 ? '' : 's'}`}
            data-testid={`connector-list-card-${entry.key}`}
            data-status={accounts.length === 0 ? 'not_installed' : 'installed'}
            data-account-count={accounts.length}
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
                <img
                    src={entry.icon_url}
                    alt=""
                    width={36}
                    height={36}
                    data-testid={`connector-${entry.key}-icon`}
                    style={{ borderRadius: 8, background: 'var(--bg-2)' }}
                    onError={(e) => {
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
                <button
                    type="button"
                    data-testid={`connector-${entry.key}-add-account`}
                    className="focus-ring"
                    disabled={addPending}
                    onClick={() => onAddAccount(entry.key)}
                    style={primaryButton(!!addPending)}
                >
                    {addPending ? 'Connecting…' : accounts.length === 0 ? 'Connect' : 'Add account'}
                </button>
            </div>

            {accounts.length === 0 ? (
                <div
                    data-testid={`connector-${entry.key}-no-accounts`}
                    role="status"
                    style={{
                        flex: 1,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        color: 'var(--fg-3)',
                        fontSize: 12.5,
                        border: '1px dashed var(--hairline)',
                        borderRadius: 8,
                        padding: 16,
                    }}
                >
                    No accounts connected yet.
                </div>
            ) : (
                <ul
                    data-testid={`connector-${entry.key}-accounts`}
                    style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 8 }}
                >
                    {accounts.map((acct) => (
                        <AccountRow
                            key={acct.id}
                            account={acct}
                            onSync={onSync}
                            onDisable={onDisable}
                            onRemove={onRemove}
                            onEdit={onEdit}
                            onCancelInstall={onCancelInstall}
                            syncing={syncingId === acct.id}
                            busy={busyId === acct.id}
                            now={now}
                        />
                    ))}
                </ul>
            )}
        </div>
    );
}

interface AccountRowProps {
    account: ConnectorInstallationDto;
    onSync: (id: number) => void;
    onDisable: (id: number) => void;
    onRemove: (id: number) => void;
    onEdit: (installation: ConnectorInstallationDto) => void;
    onCancelInstall: (id: number) => void;
    syncing: boolean;
    busy: boolean;
    now?: Date;
}

function AccountRow({
    account,
    onSync,
    onDisable,
    onRemove,
    onEdit,
    onCancelInstall,
    syncing,
    busy,
    now,
}: AccountRowProps) {
    const status = accountStatus(account);
    const badge = statusBadgeStyle(status);
    const lastSync = formatRelative(account.last_sync_at, now);
    const [confirmingRemove, setConfirmingRemove] = useState(false);

    return (
        <li
            role="group"
            aria-label={`${account.label} — ${badge.label}`}
            data-testid={`connector-account-${account.id}`}
            data-account-status={status}
            style={{
                padding: 10,
                borderRadius: 8,
                border: '1px solid var(--hairline)',
                background: 'var(--bg-2)',
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <span
                    data-testid={`connector-account-${account.id}-label`}
                    style={{ fontSize: 13, fontWeight: 600, color: 'var(--fg-0)' }}
                >
                    {account.label}
                </span>
                <span
                    data-testid={`connector-account-${account.id}-status`}
                    data-status-value={status}
                    style={{
                        padding: '2px 8px',
                        borderRadius: 99,
                        fontSize: 10.5,
                        fontWeight: 500,
                        background: badge.background,
                        border: `1px solid ${badge.border}`,
                        color: badge.color,
                    }}
                >
                    {badge.label}
                </span>
                <span
                    data-testid={`connector-account-${account.id}-project`}
                    style={{ fontSize: 11, color: 'var(--fg-3)' }}
                >
                    {account.project_key ? `→ ${account.project_key}` : '→ Global (tenant default)'}
                </span>
                {status === 'active' && lastSync !== null && (
                    <span
                        data-testid={`connector-account-${account.id}-last-sync`}
                        style={{ marginLeft: 'auto', fontSize: 11, color: 'var(--fg-3)' }}
                    >
                        Last sync {lastSync}
                    </span>
                )}
            </div>

            {status === 'errored' && account.error && (
                <div
                    data-testid={`connector-account-${account.id}-error`}
                    role="alert"
                    style={{
                        fontSize: 11.5,
                        color: '#fca5a5',
                        padding: 6,
                        borderRadius: 6,
                        background: 'rgba(239, 68, 68, 0.08)',
                        border: '1px solid rgba(239, 68, 68, 0.30)',
                    }}
                >
                    {String(account.error.message ?? 'Connector reported an error.')}
                </div>
            )}

            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {status === 'pending' && (
                    <button
                        type="button"
                        data-testid={`connector-account-${account.id}-cancel-install`}
                        className="focus-ring"
                        disabled={busy}
                        onClick={() => onCancelInstall(account.id)}
                        style={ghostButton(busy)}
                    >
                        {busy ? 'Cancelling…' : 'Cancel install'}
                    </button>
                )}

                {(status === 'active' || status === 'errored') && (
                    <button
                        type="button"
                        data-testid={`connector-account-${account.id}-sync`}
                        className="focus-ring"
                        disabled={syncing}
                        onClick={() => onSync(account.id)}
                        style={primaryButton(syncing)}
                    >
                        {syncing ? 'Queuing…' : status === 'errored' ? 'Retry sync' : 'Sync now'}
                    </button>
                )}

                {status === 'disabled' && (
                    <button
                        type="button"
                        data-testid={`connector-account-${account.id}-reenable`}
                        className="focus-ring"
                        disabled={syncing}
                        onClick={() => onSync(account.id)}
                        style={primaryButton(syncing)}
                    >
                        Re-enable sync
                    </button>
                )}

                {status !== 'pending' && (
                    <button
                        type="button"
                        data-testid={`connector-account-${account.id}-edit`}
                        className="focus-ring"
                        onClick={() => onEdit(account)}
                        style={ghostButton()}
                    >
                        Edit
                    </button>
                )}

                {(status === 'active' || status === 'errored') && (
                    <button
                        type="button"
                        data-testid={`connector-account-${account.id}-disable`}
                        className="focus-ring"
                        disabled={busy}
                        onClick={() => onDisable(account.id)}
                        style={ghostButton(busy)}
                    >
                        Disable
                    </button>
                )}

                {status !== 'pending' && !confirmingRemove && (
                    <button
                        type="button"
                        data-testid={`connector-account-${account.id}-remove`}
                        className="focus-ring"
                        onClick={() => setConfirmingRemove(true)}
                        style={ghostButton()}
                    >
                        Remove
                    </button>
                )}
                {confirmingRemove && (
                    <>
                        <button
                            type="button"
                            data-testid={`connector-account-${account.id}-remove-confirm`}
                            className="focus-ring"
                            disabled={busy}
                            onClick={() => {
                                onRemove(account.id);
                                setConfirmingRemove(false);
                            }}
                            style={dangerButton(busy)}
                        >
                            Confirm remove
                        </button>
                        <button
                            type="button"
                            data-testid={`connector-account-${account.id}-remove-cancel`}
                            className="focus-ring"
                            onClick={() => setConfirmingRemove(false)}
                            style={ghostButton()}
                        >
                            Cancel
                        </button>
                    </>
                )}
            </div>
        </li>
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

function ghostButton(disabled = false): React.CSSProperties {
    return {
        padding: '6px 12px',
        fontSize: 12.5,
        background: 'transparent',
        color: 'var(--fg-1)',
        border: '1px solid var(--hairline)',
        borderRadius: 8,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.6 : 1,
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
