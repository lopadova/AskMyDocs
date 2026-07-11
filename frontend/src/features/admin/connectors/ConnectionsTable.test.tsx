import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { buildConnections } from './connection-vm';
import type { ConnectionActions, ConnectionInFlight } from './connections-shared';
import { ConnectionsTable } from './ConnectionsTable';
import type { ConnectorEntry, ConnectorInstallationDto, ConnectorStatus } from './connectors.api';

/*
 * Table view of the flat Connections list. R16: action tests click the real
 * control; status-gating asserts both presence and absence.
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

describe('ConnectionsTable', () => {
    it('renders source, account, project sentinel, status and a dash for a never-synced row', () => {
        const rows = buildConnections(entries([installation(1, 'team@acme.io', 'active')]));
        render(
            <ConnectionsTable rows={rows} menuId={null} actions={noopActions()} inflight={noInflight()} />,
        );

        const row = screen.getByTestId('connector-connection-1');
        expect(row).toHaveAttribute('data-connection-status', 'active');
        expect(screen.getByText('Email (IMAP)')).toBeVisible();
        expect(screen.getByTestId('connector-connection-1-account')).toHaveTextContent('team@acme.io');
        expect(screen.getByTestId('connector-connection-1-project')).toHaveTextContent('Tenant default');
        expect(screen.getByTestId('connector-connection-1-status')).toHaveTextContent('Active');
        expect(screen.getByTestId('connector-connection-1-last-sync')).toHaveTextContent('—');
    });

    it('fires onSync with the connection id from the inline sync button', async () => {
        const rows = buildConnections(entries([installation(42, 'a', 'active')]));
        const actions = noopActions();
        render(<ConnectionsTable rows={rows} menuId={null} actions={actions} inflight={noInflight()} />);
        await userEvent.click(screen.getByTestId('connector-connection-42-sync'));
        expect(actions.onSync).toHaveBeenCalledWith(42);
    });

    it('hides the inline sync button on disabled and pending rows', () => {
        const rows = buildConnections(
            entries([installation(1, 'a', 'disabled'), installation(2, 'b', 'pending')]),
        );
        render(
            <ConnectionsTable rows={rows} menuId={null} actions={noopActions()} inflight={noInflight()} />,
        );
        expect(screen.queryByTestId('connector-connection-1-sync')).toBeNull();
        expect(screen.queryByTestId('connector-connection-2-sync')).toBeNull();
    });

    it('disables the sync button while that row has a write in flight', () => {
        const rows = buildConnections(entries([installation(42, 'a', 'active')]));
        render(
            <ConnectionsTable
                rows={rows}
                menuId={null}
                actions={noopActions()}
                inflight={noInflight({ syncingIds: new Set([42]) })}
            />,
        );
        expect(screen.getByTestId('connector-connection-42-sync')).toBeDisabled();
    });

    it('shows the Issue button only on an errored row and opens the error modal callback', async () => {
        const rows = buildConnections(
            entries([
                installation(1, 'ok', 'active'),
                installation(2, 'broken', 'errored', {
                    error: { message: 'Authentication failed: 535.' },
                }),
            ]),
        );
        const actions = noopActions();
        render(<ConnectionsTable rows={rows} menuId={null} actions={actions} inflight={noInflight()} />);

        expect(screen.queryByTestId('connector-connection-1-error')).toBeNull();
        const issue = screen.getByTestId('connector-connection-2-error');
        expect(issue).toHaveTextContent('Authentication failed: 535.');
        await userEvent.click(issue);
        expect(actions.onOpenError).toHaveBeenCalledWith(expect.objectContaining({ id: 2 }));
    });

    it('toggles the row ⋮ menu through onToggleMenu', async () => {
        const rows = buildConnections(entries([installation(9, 'a', 'active')]));
        const actions = noopActions();
        render(<ConnectionsTable rows={rows} menuId={null} actions={actions} inflight={noInflight()} />);
        await userEvent.click(screen.getByTestId('connector-connection-9-menu'));
        expect(actions.onToggleMenu).toHaveBeenCalledWith(9);
    });

    it('renders the open menu panel for the row whose id matches menuId', () => {
        const rows = buildConnections(entries([installation(9, 'a', 'active')]));
        render(
            <ConnectionsTable rows={rows} menuId={9} actions={noopActions()} inflight={noInflight()} />,
        );
        expect(screen.getByTestId('connector-connection-9-menu-panel')).toBeVisible();
    });
});
