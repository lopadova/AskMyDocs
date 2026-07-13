import { ConnectionActionsMenu } from './ConnectionActionsMenu';
import type { ConnectionVM } from './connection-vm';
import { SourceAvatar } from './SourceAvatar';
import {
    rowFlags,
    StatusBadge,
    SyncGlyph,
    type ConnectionsListProps,
} from './connections-shared';

/*
 * Cards view of the flat Connections list — the alternate to the table, chosen
 * via the toolbar toggle. Each card carries the same testids as the table row
 * (`connector-connection-{id}-*`); only one view is mounted at a time, so the
 * ids never collide.
 */

export function ConnectionsCards({ rows, menuId, actions, inflight }: ConnectionsListProps) {
    return (
        <div
            data-testid="connector-connections-cards"
            style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))',
                gap: 12,
            }}
        >
            {rows.map((vm) => (
                <ConnectionCard
                    key={vm.id}
                    vm={vm}
                    menuId={menuId}
                    actions={actions}
                    inflight={inflight}
                />
            ))}
        </div>
    );
}

function ConnectionCard({
    vm,
    menuId,
    actions,
    inflight,
}: {
    vm: ConnectionVM;
} & Pick<ConnectionsListProps, 'menuId' | 'actions' | 'inflight'>) {
    const flags = rowFlags(vm.id, inflight);
    const showSync = vm.status === 'active' || vm.status === 'errored';
    const base = `connector-connection-${vm.id}`;

    return (
        <div
            role="group"
            data-testid={base}
            data-connection-status={vm.status}
            aria-label={`${vm.account} on ${vm.sourceName} — ${vm.status}`}
            style={{
                background: 'var(--bg-1)',
                border: '1px solid var(--hairline)',
                borderRadius: 13,
                padding: '15px 16px',
            }}
        >
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 11 }}>
                <SourceAvatar
                    connectorKey={vm.connectorKey}
                    displayName={vm.sourceName}
                    iconUrl={vm.iconUrl}
                    size={34}
                    radius={9}
                />
                <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 14, fontWeight: 600, lineHeight: 1.15, color: 'var(--fg-0)' }}>
                        {vm.sourceName}
                    </div>
                    <div
                        data-testid={`${base}-account`}
                        style={{
                            fontFamily: 'var(--font-mono)',
                            fontSize: 12,
                            color: 'var(--fg-2)',
                            marginTop: 2,
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                        }}
                    >
                        {vm.account}
                    </div>
                </div>
                <ConnectionActionsMenu
                    vm={vm}
                    isOpen={menuId === vm.id}
                    onToggle={() => actions.onToggleMenu(vm.id)}
                    onClose={actions.onCloseMenu}
                    onTestFetch={actions.onTestFetch}
                    onEdit={actions.onEdit}
                    onFolders={actions.onFolders}
                    onExport={actions.onExport}
                    onDisable={actions.onDisable}
                    onEnable={actions.onEnable}
                    onRemove={actions.onRemove}
                    onCancelInstall={actions.onCancelInstall}
                    writeLocked={flags.writeLocked}
                    enabling={flags.enabling}
                    busy={flags.busy}
                    exporting={flags.exporting}
                    probing={flags.probing}
                />
            </div>

            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    flexWrap: 'wrap',
                    marginTop: 13,
                }}
            >
                <StatusBadge vm={vm} />
                <span data-testid={`${base}-project`} style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                    {vm.projectLabel}
                </span>
            </div>

            <div
                data-testid={`${base}-last-sync`}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    marginTop: 11,
                    fontSize: 12.5,
                    color: vm.status === 'errored' ? 'var(--err)' : 'var(--fg-3)',
                }}
            >
                <svg
                    width="13"
                    height="13"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.9"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 8v4l2.5 1.5" />
                </svg>
                {vm.lastSync ? `Last sync ${vm.lastSync}` : 'Never synced'}
            </div>

            {vm.status === 'errored' && vm.errorMessage && (
                <button
                    type="button"
                    data-testid={`${base}-error`}
                    className="amd-cn-issue focus-ring"
                    onClick={() => actions.onOpenError(vm)}
                    style={{
                        marginTop: 11,
                        width: '100%',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        padding: '8px 10px',
                        borderRadius: 8,
                        fontSize: 12.5,
                        fontWeight: 500,
                        color: '#fca5a5',
                        background: 'rgba(248,113,113,.1)',
                        border: '1px solid rgba(248,113,113,.26)',
                        cursor: 'pointer',
                        textAlign: 'left',
                    }}
                >
                    <svg
                        style={{ flex: 'none' }}
                        width="14"
                        height="14"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <path d="M12 9v4" />
                        <path d="M12 17h.01" />
                    </svg>
                    <span
                        style={{
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                            flex: 1,
                        }}
                    >
                        {vm.errorMessage}
                    </span>
                    <span style={{ flex: 'none', color: '#f87171', fontWeight: 600 }}>Details</span>
                </button>
            )}

            {showSync && (
                <div style={{ display: 'flex', gap: 8, marginTop: 13 }}>
                    <button
                        type="button"
                        data-testid={`${base}-sync`}
                        className="amd-cn-icon-btn focus-ring"
                        disabled={flags.writeLocked}
                        onClick={() => actions.onSync(vm.id)}
                        style={{
                            flex: 1,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            gap: 7,
                            background: 'var(--bg-2)',
                            border: '1px solid var(--hairline)',
                            color: 'var(--fg-1)',
                            font: 'inherit',
                            fontSize: 13,
                            fontWeight: 600,
                            padding: '8px 10px',
                            borderRadius: 9,
                            cursor: 'pointer',
                        }}
                    >
                        <SyncGlyph spinning={flags.syncing} />
                        {flags.syncing
                            ? 'Queuing…'
                            : vm.status === 'errored'
                              ? 'Retry sync'
                              : 'Sync now'}
                    </button>
                </div>
            )}
        </div>
    );
}
