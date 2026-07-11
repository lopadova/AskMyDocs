import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { buildConnections } from './connection-vm';
import type { ConnectionActions, ConnectionInFlight } from './connections-shared';
import { ConnectionsCards } from './ConnectionsCards';
import type { ConnectorEntry, ConnectorInstallationDto, ConnectorStatus } from './connectors.api';

/*
 * Cards view of the flat Connections list — same testids as the table rows
 * (only one view mounts at a time). R16: label/state assertions use fixtures
 * that would fail under the opposite branch.
 */

function installation(
    id: number,
    label: string,
    status: ConnectorStatus,
    overrides: Partial<ConnectorInstallationDto> = {},
): ConnectorInstallationDto {
    return {
        id,
        label,
        project_key: null,
        status,
        last_sync_at: null,
        error: null,
        folders: { include: [] },
        date_window_days: null,
        ...overrides,
    };
}

function entries(installations: ConnectorInstallationDto[]): ConnectorEntry[] {
    return [
        {
            key: 'imap',
            display_name: 'Email (IMAP)',
            icon_url: '',
            oauth_scopes: [],
            auth_kind: 'credential',
            credential_form_schema: [],
            installations,
        },
    ];
}

function noopActions(overrides: Partial<ConnectionActions> = {}): ConnectionActions {
    return {
        onSync: vi.fn(),
        onTestFetch: vi.fn(),
        onEdit: vi.fn(),
        onFolders: vi.fn(),
        onExport: vi.fn(),
        onDisable: vi.fn(),
        onEnable: vi.fn(),
        onRemove: vi.fn(),
        onCancelInstall: vi.fn(),
        onOpenError: vi.fn(),
        onToggleMenu: vi.fn(),
        onCloseMenu: vi.fn(),
        ...overrides,
    };
}

function noInflight(overrides: Partial<ConnectionInFlight> = {}): ConnectionInFlight {
    return {
        syncingIds: new Set(),
        busyIds: new Set(),
        enablingIds: new Set(),
        probingIds: new Set(),
        exportingIds: new Set(),
        ...overrides,
    };
}

describe('ConnectionsCards', () => {
    it('renders a card with account, status, project sentinel and "Never synced"', () => {
        const rows = buildConnections(entries([installation(1, 'team@acme.io', 'active')]));
        render(
            <ConnectionsCards rows={rows} menuId={null} actions={noopActions()} inflight={noInflight()} />,
        );

        const card = screen.getByTestId('connector-connection-1');
        expect(card).toHaveAttribute('data-connection-status', 'active');
        expect(screen.getByTestId('connector-connection-1-account')).toHaveTextContent('team@acme.io');
        expect(screen.getByTestId('connector-connection-1-status')).toHaveTextContent('Active');
        expect(screen.getByTestId('connector-connection-1-project')).toHaveTextContent('Tenant default');
        expect(screen.getByTestId('connector-connection-1-last-sync')).toHaveTextContent('Never synced');
        expect(screen.getByTestId('connector-connection-1-sync')).toHaveTextContent('Sync now');
    });

    it('labels the sync button "Retry sync" on an errored card and fires onSync', async () => {
        const rows = buildConnections(
            entries([installation(2, 'broken', 'errored', { error: { message: 'HTTP 429.' } })]),
        );
        const actions = noopActions();
        render(<ConnectionsCards rows={rows} menuId={null} actions={actions} inflight={noInflight()} />);

        const sync = screen.getByTestId('connector-connection-2-sync');
        expect(sync).toHaveTextContent('Retry sync');
        await userEvent.click(sync);
        expect(actions.onSync).toHaveBeenCalledWith(2);
    });

    it('shows "Queuing…" and disables the button while the sync is in flight', () => {
        const rows = buildConnections(entries([installation(3, 'a', 'active')]));
        render(
            <ConnectionsCards
                rows={rows}
                menuId={null}
                actions={noopActions()}
                inflight={noInflight({ syncingIds: new Set([3]) })}
            />,
        );
        const sync = screen.getByTestId('connector-connection-3-sync');
        expect(sync).toBeDisabled();
        expect(sync).toHaveTextContent('Queuing…');
    });

    it('renders the error banner with a Details affordance that opens the error modal', async () => {
        const rows = buildConnections(
            entries([
                installation(4, 'broken', 'errored', {
                    error: { message: 'Rate limited by the Notion API (HTTP 429).' },
                }),
            ]),
        );
        const actions = noopActions();
        render(<ConnectionsCards rows={rows} menuId={null} actions={actions} inflight={noInflight()} />);

        const banner = screen.getByTestId('connector-connection-4-error');
        expect(banner).toHaveTextContent('Rate limited by the Notion API (HTTP 429).');
        expect(banner).toHaveTextContent('Details');
        await userEvent.click(banner);
        expect(actions.onOpenError).toHaveBeenCalledWith(expect.objectContaining({ id: 4 }));
    });

    it('hides the sync button on a disabled card (actions live in the ⋮ menu)', () => {
        const rows = buildConnections(entries([installation(5, 'paused', 'disabled')]));
        render(
            <ConnectionsCards rows={rows} menuId={null} actions={noopActions()} inflight={noInflight()} />,
        );
        expect(screen.queryByTestId('connector-connection-5-sync')).toBeNull();
        expect(screen.getByTestId('connector-connection-5-menu')).toBeVisible();
    });

    it('announces each card as a group with account, source and status (R15)', () => {
        // ARIA-name must sit on a role-bearing element — the card is role="group"
        // so screen readers announce "{account} on {source} — {status}".
        const rows = buildConnections(entries([installation(1, 'team@acme.io', 'active')]));
        render(
            <ConnectionsCards rows={rows} menuId={null} actions={noopActions()} inflight={noInflight()} />,
        );
        const card = screen.getByTestId('connector-connection-1');
        expect(card).toHaveAttribute('role', 'group');
        expect(card).toHaveAttribute('aria-label', 'team@acme.io on Email (IMAP) — active');
    });
});
