import type {
    ConnectorAuthKind,
    ConnectorEntry,
    ConnectorInstallationDto,
} from './connectors.api';
import { accountStatus, formatRelative, type DerivedConnectorStatus } from './status-utils';

/*
 * The redesigned Connectors page (design handoff "Connectors.dc.html") shows a
 * FLAT list of every connected account across all sources — not the old
 * per-connector card. `buildConnections()` flattens `ConnectorEntry[]` (each with
 * N installations) into one sortable list of `ConnectionVM`s, each carrying the
 * source it belongs to so the table/cards can render source + account + project +
 * status in one row.
 *
 * Pure + unit-tested (connection-vm.test.ts) so the flatten/sort/filter/search
 * logic is verified without React (R16).
 */

/** The tenant-default sentinel shown when an account is not bound to a project. */
export const TENANT_DEFAULT_LABEL = 'Tenant default';

export interface ConnectionVM {
    id: number;
    connectorKey: string;
    sourceName: string;
    iconUrl: string;
    authKind: ConnectorAuthKind;
    /** Credential connectors (IMAP) expose Test fetch / Folders / Export. */
    isCredential: boolean;
    /** The account label (disambiguates N accounts on one source). */
    account: string;
    /** `project_key`, or the tenant-default sentinel when unbound. */
    projectLabel: string;
    boundToProject: boolean;
    status: DerivedConnectorStatus;
    /** Coarse relative last-sync string, or null when never synced. */
    lastSync: string | null;
    /** Human error message when the account is errored, else null. */
    errorMessage: string | null;
    /** The raw installation + parent entry, for the modal callbacks. */
    installation: ConnectorInstallationDto;
    entry: ConnectorEntry;
}

/** Read a human message out of the free-form `error_json` blob. */
function errorMessageOf(installation: ConnectorInstallationDto): string | null {
    if (!installation.error) {
        return null;
    }
    const message = installation.error.message;
    return typeof message === 'string' && message.trim() !== ''
        ? message
        : 'Connector reported an error.';
}

/**
 * Flatten every source's installations into one list of connection view-models,
 * sorted by source name then account label for a stable, deterministic order
 * (important for E2E/Vitest row targeting).
 */
export function buildConnections(entries: ConnectorEntry[], now?: Date): ConnectionVM[] {
    const rows: ConnectionVM[] = [];
    for (const entry of entries) {
        for (const installation of entry.installations ?? []) {
            rows.push({
                id: installation.id,
                connectorKey: entry.key,
                sourceName: entry.display_name,
                iconUrl: entry.icon_url,
                authKind: entry.auth_kind,
                isCredential: entry.auth_kind === 'credential',
                account: installation.label,
                projectLabel: installation.project_key ?? TENANT_DEFAULT_LABEL,
                boundToProject: !!installation.project_key,
                status: accountStatus(installation),
                lastSync: formatRelative(installation.last_sync_at, now),
                errorMessage: errorMessageOf(installation),
                installation,
                entry,
            });
        }
    }
    rows.sort(
        (a, b) => a.sourceName.localeCompare(b.sourceName) || a.account.localeCompare(b.account),
    );
    return rows;
}

/**
 * Case-insensitive search over account label + source name + project label. An
 * empty/blank query returns the list unchanged.
 */
export function filterConnections(rows: ConnectionVM[], query: string): ConnectionVM[] {
    const q = query.trim().toLowerCase();
    if (q === '') {
        return rows;
    }
    return rows.filter((r) =>
        `${r.account} ${r.sourceName} ${r.projectLabel}`.toLowerCase().includes(q),
    );
}

/**
 * The ids eligible for a "Sync all" sweep — active or errored accounts only
 * (a paused/disabled account is intentionally left paused; a pending one has no
 * verified credentials yet).
 */
export function syncableIds(rows: ConnectionVM[]): number[] {
    return rows.filter((r) => r.status === 'active' || r.status === 'errored').map((r) => r.id);
}
