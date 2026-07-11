import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ConnectionActionsMenu } from './ConnectionActionsMenu';
import { buildConnections, type ConnectionVM } from './connection-vm';
import type { ConnectorEntry, ConnectorInstallationDto, ConnectorStatus } from './connectors.api';

/*
 * The per-connection ⋮ dropdown. R16: every "fires X" test actually clicks the
 * item, and the status/auth-kind gating is asserted on BOTH sides (present for
 * the state that owns it, absent for the state that doesn't).
 */

function installation(
    id: number,
    status: ConnectorStatus,
    overrides: Partial<ConnectorInstallationDto> = {},
): ConnectorInstallationDto {
    return {
        id,
        label: 'Support',
        project_key: null,
        status,
        last_sync_at: null,
        error: null,
        folders: { include: [] },
        date_window_days: null,
        ...overrides,
    };
}

function vmFor(status: ConnectorStatus, authKind: 'oauth' | 'credential' = 'credential'): ConnectionVM {
    const entry: ConnectorEntry = {
        key: authKind === 'credential' ? 'imap' : 'google-drive',
        display_name: authKind === 'credential' ? 'Email (IMAP)' : 'Google Drive',
        icon_url: '',
        oauth_scopes: [],
        auth_kind: authKind,
        credential_form_schema: authKind === 'credential' ? [] : null,
        installations: [installation(7, status)],
    };
    return buildConnections([entry])[0];
}

function renderMenu(
    vm: ConnectionVM,
    overrides: Partial<ComponentProps<typeof ConnectionActionsMenu>> = {},
) {
    const props: ComponentProps<typeof ConnectionActionsMenu> = {
        vm,
        isOpen: true,
        onToggle: vi.fn(),
        onClose: vi.fn(),
        onTestFetch: vi.fn(),
        onEdit: vi.fn(),
        onFolders: vi.fn(),
        onExport: vi.fn(),
        onDisable: vi.fn(),
        onEnable: vi.fn(),
        onRemove: vi.fn(),
        onCancelInstall: vi.fn(),
        writeLocked: false,
        enabling: false,
        busy: false,
        exporting: false,
        probing: false,
        ...overrides,
    };
    render(<ConnectionActionsMenu {...props} />);
    return props;
}

describe('ConnectionActionsMenu', () => {
    it('lists the credential active-account actions (and no enable/cancel)', () => {
        renderMenu(vmFor('active'));
        expect(screen.getByTestId('connector-connection-7-test-fetch')).toBeVisible();
        expect(screen.getByTestId('connector-connection-7-edit')).toBeVisible();
        expect(screen.getByTestId('connector-connection-7-folders')).toBeVisible();
        expect(screen.getByTestId('connector-connection-7-export')).toBeVisible();
        expect(screen.getByTestId('connector-connection-7-disable')).toBeVisible();
        expect(screen.getByTestId('connector-connection-7-remove')).toBeVisible();
        expect(screen.queryByTestId('connector-connection-7-enable')).toBeNull();
        expect(screen.queryByTestId('connector-connection-7-cancel-install')).toBeNull();
    });

    it('hides the credential-only items (test fetch / folders / export) on an OAuth source', () => {
        renderMenu(vmFor('active', 'oauth'));
        expect(screen.queryByTestId('connector-connection-7-test-fetch')).toBeNull();
        expect(screen.queryByTestId('connector-connection-7-folders')).toBeNull();
        expect(screen.queryByTestId('connector-connection-7-export')).toBeNull();
        expect(screen.getByTestId('connector-connection-7-edit')).toBeVisible();
        expect(screen.getByTestId('connector-connection-7-disable')).toBeVisible();
    });

    it('offers Enable (not Disable) on a disabled account and fires onEnable', async () => {
        const { onEnable, onClose } = renderMenu(vmFor('disabled'));
        expect(screen.queryByTestId('connector-connection-7-disable')).toBeNull();
        await userEvent.click(screen.getByTestId('connector-connection-7-enable'));
        expect(onEnable).toHaveBeenCalledWith(7);
        expect(onClose).toHaveBeenCalled();
    });

    it('offers ONLY Cancel install on a pending account and fires onCancelInstall', async () => {
        const { onCancelInstall } = renderMenu(vmFor('pending'));
        // A pending account has no verified credentials yet — none of the
        // active-account items (edit / remove / test-fetch / folders / export /
        // disable) may appear (carries the old ConnectorCard.export-import
        // 'does NOT render Export on a pending account' guard).
        expect(screen.queryByTestId('connector-connection-7-edit')).toBeNull();
        expect(screen.queryByTestId('connector-connection-7-remove')).toBeNull();
        expect(screen.queryByTestId('connector-connection-7-test-fetch')).toBeNull();
        expect(screen.queryByTestId('connector-connection-7-folders')).toBeNull();
        expect(screen.queryByTestId('connector-connection-7-export')).toBeNull();
        expect(screen.queryByTestId('connector-connection-7-disable')).toBeNull();
        await userEvent.click(screen.getByTestId('connector-connection-7-cancel-install'));
        expect(onCancelInstall).toHaveBeenCalledWith(7);
    });

    it('offers Test fetch + Remove (but NOT Disable) on an errored credential account', () => {
        // The errored diagnostic menu (carries the old ConnectorCard 'offers Test
        // fetch even on an errored credential account' guard). Disable is scoped
        // to active accounts only; the inline icon offers "Retry sync".
        renderMenu(vmFor('errored'));
        expect(screen.getByTestId('connector-connection-7-test-fetch')).toBeVisible();
        expect(screen.getByTestId('connector-connection-7-edit')).toBeVisible();
        expect(screen.getByTestId('connector-connection-7-export')).toBeVisible();
        expect(screen.getByTestId('connector-connection-7-remove')).toBeVisible();
        expect(screen.queryByTestId('connector-connection-7-disable')).toBeNull();
    });

    it('requires the two-step confirm before firing onRemove', async () => {
        const { onRemove, onClose } = renderMenu(vmFor('active'));
        await userEvent.click(screen.getByTestId('connector-connection-7-remove'));
        expect(onRemove).not.toHaveBeenCalled();
        await userEvent.click(screen.getByTestId('connector-connection-7-remove-confirm'));
        expect(onRemove).toHaveBeenCalledWith(7);
        expect(onClose).toHaveBeenCalled();
    });

    it('backs out of the remove confirm without firing onRemove', async () => {
        const { onRemove } = renderMenu(vmFor('active'));
        await userEvent.click(screen.getByTestId('connector-connection-7-remove'));
        await userEvent.click(screen.getByTestId('connector-connection-7-remove-cancel'));
        expect(onRemove).not.toHaveBeenCalled();
        // Back to the normal item list.
        expect(screen.getByTestId('connector-connection-7-remove')).toBeVisible();
    });

    it('fires onEdit with the connection VM and closes the menu', async () => {
        const vm = vmFor('active');
        const { onEdit, onClose } = renderMenu(vm);
        await userEvent.click(screen.getByTestId('connector-connection-7-edit'));
        expect(onEdit).toHaveBeenCalledWith(vm);
        expect(onClose).toHaveBeenCalled();
    });

    it('locks write items while a write is in flight, leaving read-only probes usable', () => {
        renderMenu(vmFor('active'), { writeLocked: true });
        expect(screen.getByTestId('connector-connection-7-edit')).toBeDisabled();
        expect(screen.getByTestId('connector-connection-7-disable')).toBeDisabled();
        expect(screen.getByTestId('connector-connection-7-remove')).toBeDisabled();
        // Test fetch + export are read-only — a write in flight must not lock them.
        expect(screen.getByTestId('connector-connection-7-test-fetch')).toBeEnabled();
        expect(screen.getByTestId('connector-connection-7-export')).toBeEnabled();
    });

    it('shows the in-flight labels: Enabling… / Fetching… / Exporting…', () => {
        renderMenu(vmFor('disabled'), { enabling: true, probing: true, exporting: true, writeLocked: true });
        expect(screen.getByTestId('connector-connection-7-enable')).toHaveTextContent('Enabling…');
        expect(screen.getByTestId('connector-connection-7-test-fetch')).toHaveTextContent('Fetching…');
        expect(screen.getByTestId('connector-connection-7-test-fetch')).toBeDisabled();
        expect(screen.getByTestId('connector-connection-7-export')).toHaveTextContent('Exporting…');
        expect(screen.getByTestId('connector-connection-7-export')).toBeDisabled();
    });

    it('keeps the Enable label "Enable" when a NON-enable write is in flight', () => {
        // Regression carried over from the old card: Remove in flight raises
        // writeLocked (busy), not `enabling` — Enable locks but must not claim
        // to be "Enabling…".
        renderMenu(vmFor('disabled'), { writeLocked: true, busy: true });
        const btn = screen.getByTestId('connector-connection-7-enable');
        expect(btn).toBeDisabled();
        expect(btn).toHaveTextContent('Enable');
        expect(btn).not.toHaveTextContent('Enabling…');
    });

    it('renders only the trigger when closed and toggles via the trigger', async () => {
        const { onToggle } = renderMenu(vmFor('active'), { isOpen: false });
        expect(screen.queryByTestId('connector-connection-7-menu-panel')).toBeNull();
        await userEvent.click(screen.getByTestId('connector-connection-7-menu'));
        expect(onToggle).toHaveBeenCalled();
    });

    it('closes on Escape', async () => {
        const { onClose } = renderMenu(vmFor('active'));
        await userEvent.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalled();
    });
});
