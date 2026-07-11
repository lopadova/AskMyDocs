import type { ConnectionVM } from './connection-vm';
import { statusBadgeStyle } from './status-utils';

/*
 * Shared contracts + tiny presentational bits for the Connections table AND
 * cards views, so both render identical status badges, resolve the same
 * per-row in-flight flags, and forward the same action callbacks to the ⋮ menu.
 */

/** Every per-connection callback, forwarded from ConnectorsView. */
export interface ConnectionActions {
    onSync: (id: number) => void;
    onTestFetch: (vm: ConnectionVM) => void;
    onEdit: (vm: ConnectionVM) => void;
    onFolders: (vm: ConnectionVM) => void;
    onExport: (vm: ConnectionVM) => void;
    onDisable: (id: number) => void;
    onEnable: (id: number) => void;
    onRemove: (id: number) => void;
    onCancelInstall: (id: number) => void;
    /** Open the Sync-failed detail modal for an errored connection. */
    onOpenError: (vm: ConnectionVM) => void;
    /** Toggle this connection's ⋮ menu (the view owns the single open id). */
    onToggleMenu: (id: number) => void;
    onCloseMenu: () => void;
}

/** The five per-account in-flight sets tracked by ConnectorsView. */
export interface ConnectionInFlight {
    syncingIds: ReadonlySet<number>;
    busyIds: ReadonlySet<number>;
    enablingIds: ReadonlySet<number>;
    probingIds: ReadonlySet<number>;
    exportingIds: ReadonlySet<number>;
}

export interface ConnectionsListProps {
    rows: ConnectionVM[];
    /** id of the connection whose ⋮ menu is open, or null. */
    menuId: number | null;
    actions: ConnectionActions;
    inflight: ConnectionInFlight;
}

export interface RowFlags {
    syncing: boolean;
    busy: boolean;
    enabling: boolean;
    probing: boolean;
    exporting: boolean;
    /** Any write action in flight → lock every write control. */
    writeLocked: boolean;
}

/** Resolve a connection's in-flight flags from the tracked id sets. */
export function rowFlags(id: number, inflight: ConnectionInFlight): RowFlags {
    const syncing = inflight.syncingIds.has(id);
    const busy = inflight.busyIds.has(id);
    const enabling = inflight.enablingIds.has(id);
    return {
        syncing,
        busy,
        enabling,
        probing: inflight.probingIds.has(id),
        exporting: inflight.exportingIds.has(id),
        writeLocked: syncing || busy || enabling,
    };
}

/** The circular-arrow "sync" glyph, spinning while a sync is in flight. */
export function SyncGlyph({ spinning, size = 15 }: { spinning: boolean; size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            style={
                spinning
                    ? { transformOrigin: 'center', animation: 'amd-cn-spin .8s linear infinite' }
                    : undefined
            }
        >
            <path d="M21 12a9 9 0 1 1-3-6.7" />
            <path d="M21 3v5h-5" />
        </svg>
    );
}

/**
 * The status pill (coloured dot + label), theme-aware via statusBadgeStyle.
 * `testid` overrides the default `connector-connection-{id}-status` — callers that
 * render this badge OUTSIDE a list row (e.g. the Edit-modal header, which stays
 * mounted alongside the list) MUST pass a distinct id so the testid stays unique
 * (R29).
 */
export function StatusBadge({ vm, testid }: { vm: ConnectionVM; testid?: string }) {
    const badge = statusBadgeStyle(vm.status);
    return (
        <span
            data-testid={testid ?? `connector-connection-${vm.id}-status`}
            data-status-value={vm.status}
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 6,
                padding: '3px 9px',
                borderRadius: 999,
                fontSize: 12,
                fontWeight: 600,
                color: badge.color,
                background: badge.background,
                border: `1px solid ${badge.border}`,
                whiteSpace: 'nowrap',
            }}
        >
            <span
                style={{ width: 6, height: 6, borderRadius: 999, background: badge.color }}
                aria-hidden="true"
            />
            {badge.label}
        </span>
    );
}
