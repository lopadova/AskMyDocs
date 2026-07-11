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
 * Table view of the flat Connections list (design handoff default view).
 * Columns: Source · Account · Project · Status · Last sync · Issue · Actions.
 * The Actions cell carries the inline "sync now / retry" icon (active/errored
 * only) + the shared ⋮ menu. Horizontal overflow scrolls inside its own
 * container so the page body never scrolls sideways.
 */

const TH: React.CSSProperties = {
    padding: '11px 16px',
    fontWeight: 600,
    color: 'var(--fg-3)',
    fontSize: 11.5,
    textTransform: 'uppercase',
    letterSpacing: '.05em',
    whiteSpace: 'nowrap',
};

const TD: React.CSSProperties = {
    padding: '13px 16px',
    verticalAlign: 'middle',
    borderTop: '1px solid var(--hairline)',
};

export function ConnectionsTable({ rows, menuId, actions, inflight }: ConnectionsListProps) {
    return (
        <div
            data-testid="connector-connections-table"
            style={{
                border: '1px solid var(--hairline)',
                borderRadius: 14,
                overflow: 'hidden',
                background: 'var(--bg-1)',
            }}
        >
            <div style={{ overflowX: 'auto' }}>
                <table
                    style={{
                        width: '100%',
                        borderCollapse: 'collapse',
                        fontSize: 13,
                        minWidth: 900,
                    }}
                >
                    <thead>
                        <tr style={{ background: 'var(--bg-2)', textAlign: 'left' }}>
                            <th style={TH}>Source</th>
                            <th style={TH}>Account</th>
                            <th style={TH}>Project</th>
                            <th style={TH}>Status</th>
                            <th style={TH}>Last sync</th>
                            <th style={TH}>Issue</th>
                            <th style={{ ...TH, textAlign: 'right' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((vm) => (
                            <ConnectionRow
                                key={vm.id}
                                vm={vm}
                                menuId={menuId}
                                actions={actions}
                                inflight={inflight}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function ConnectionRow({
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
        <tr
            className="amd-cn-row"
            data-testid={base}
            data-connection-status={vm.status}
            aria-label={`${vm.account} on ${vm.sourceName} — ${vm.status}`}
        >
            <td style={TD}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <SourceAvatar
                        connectorKey={vm.connectorKey}
                        displayName={vm.sourceName}
                        iconUrl={vm.iconUrl}
                        size={28}
                        radius={7}
                    />
                    <span style={{ fontWeight: 600, color: 'var(--fg-0)', whiteSpace: 'nowrap' }}>
                        {vm.sourceName}
                    </span>
                </div>
            </td>
            <td style={TD}>
                <span
                    data-testid={`${base}-account`}
                    style={{ fontFamily: 'var(--font-mono)', fontSize: 12.5, color: 'var(--fg-1)' }}
                >
                    {vm.account}
                </span>
            </td>
            <td style={TD}>
                <span data-testid={`${base}-project`} style={{ color: 'var(--fg-2)' }}>
                    {vm.projectLabel}
                </span>
            </td>
            <td style={TD}>
                <StatusBadge vm={vm} />
            </td>
            <td style={TD}>
                <span
                    data-testid={`${base}-last-sync`}
                    style={{
                        color: vm.status === 'errored' ? '#f87171' : 'var(--fg-3)',
                        whiteSpace: 'nowrap',
                    }}
                >
                    {vm.lastSync ?? '—'}
                </span>
            </td>
            <td style={TD}>
                {vm.status === 'errored' && vm.errorMessage ? (
                    <button
                        type="button"
                        data-testid={`${base}-error`}
                        className="amd-cn-issue focus-ring"
                        onClick={() => actions.onOpenError(vm)}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6,
                            maxWidth: 200,
                            padding: '3px 9px',
                            borderRadius: 7,
                            fontSize: 12,
                            fontWeight: 500,
                            color: '#fca5a5',
                            background: 'rgba(248,113,113,.12)',
                            border: '1px solid rgba(248,113,113,.28)',
                            cursor: 'pointer',
                        }}
                    >
                        <AlertGlyph />
                        <span
                            style={{
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                            }}
                        >
                            {vm.errorMessage}
                        </span>
                    </button>
                ) : (
                    <span style={{ color: 'var(--fg-4)' }}>—</span>
                )}
            </td>
            <td style={TD}>
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'flex-end',
                        gap: 6,
                    }}
                >
                    {showSync && (
                        <button
                            type="button"
                            data-testid={`${base}-sync`}
                            className="amd-cn-icon-btn focus-ring"
                            aria-label={vm.status === 'errored' ? 'Retry sync' : 'Sync now'}
                            title={vm.status === 'errored' ? 'Retry sync' : 'Sync now'}
                            disabled={flags.writeLocked}
                            onClick={() => actions.onSync(vm.id)}
                            style={{
                                width: 30,
                                height: 30,
                                borderRadius: 8,
                                border: '1px solid var(--hairline)',
                                background: 'var(--bg-2)',
                                color: 'var(--fg-1)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                cursor: 'pointer',
                            }}
                        >
                            <SyncGlyph spinning={flags.syncing} />
                        </button>
                    )}
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
            </td>
        </tr>
    );
}

function AlertGlyph() {
    return (
        <svg
            style={{ flex: 'none' }}
            width="13"
            height="13"
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
    );
}
