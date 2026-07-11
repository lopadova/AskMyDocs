import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ConnectorCard } from './ConnectorCard';
import type { ConnectorEntry, ConnectorInstallationDto, ConnectorStatus } from './connectors.api';

/*
 * v8.29 — the card's Export (per account) + Import (header) affordances. R16: each
 * "fires X" test actually triggers the control (a click / a file-input change).
 */

function account(id: number, label: string, status: ConnectorStatus = 'active'): ConnectorInstallationDto {
    return {
        id, label, project_key: null, status, last_sync_at: null, error: null,
        folders: { include: [] }, date_window_days: null,
    };
}

function credentialEntry(installations: ConnectorInstallationDto[]): ConnectorEntry {
    return {
        key: 'imap', display_name: 'Email (IMAP)', icon_url: '/connectors/imap.svg', oauth_scopes: [],
        auth_kind: 'credential', credential_form_schema: [], installations,
    };
}

function oauthEntry(installations: ConnectorInstallationDto[]): ConnectorEntry {
    return {
        key: 'google-drive', display_name: 'Google Drive', icon_url: '/g.svg', oauth_scopes: ['x'],
        auth_kind: 'oauth', credential_form_schema: null, installations,
    };
}

function renderCard(
    entry: ConnectorEntry,
    overrides: Partial<ComponentProps<typeof ConnectorCard>> = {},
) {
    const props: ComponentProps<typeof ConnectorCard> = {
        entry,
        onAddAccount: vi.fn(),
        onSync: vi.fn(),
        onDisable: vi.fn(),
        onEnable: vi.fn(),
        onRemove: vi.fn(),
        onEdit: vi.fn(),
        onCancelInstall: vi.fn(),
        onExport: vi.fn(),
        onImport: vi.fn(),
        ...overrides,
    };
    render(<ConnectorCard {...props} />);
    return props;
}

describe('ConnectorCard — export / import', () => {
    it('fires onExport with the account when Export is clicked', async () => {
        const acct = account(7, 'Date');
        const { onExport } = renderCard(credentialEntry([acct]));

        await userEvent.click(screen.getByTestId('connector-account-7-export'));

        expect(onExport).toHaveBeenCalledTimes(1);
        expect(onExport).toHaveBeenCalledWith(expect.objectContaining({ id: 7, label: 'Date' }));
    });

    it('fires onImport with the connector key + the chosen file', () => {
        const { onImport } = renderCard(credentialEntry([]));

        const file = new File(['{}'], 'cfg.json', { type: 'application/json' });
        const input = screen.getByTestId('connector-imap-import-file');
        fireEvent.change(input, { target: { files: [file] } });

        expect(onImport).toHaveBeenCalledTimes(1);
        expect(onImport).toHaveBeenCalledWith('imap', file);
    });

    it('opens the file picker when the Import button is clicked', async () => {
        renderCard(credentialEntry([]));
        const input = screen.getByTestId('connector-imap-import-file') as HTMLInputElement;
        const clickSpy = vi.spyOn(input, 'click');

        await userEvent.click(screen.getByTestId('connector-imap-import-account'));

        expect(clickSpy).toHaveBeenCalled();
    });

    it('shows "Exporting…" and disables the button while an export is in flight', () => {
        const acct = account(7, 'Date');
        renderCard(credentialEntry([acct]), { exportingIds: new Set([7]) });
        const btn = screen.getByTestId('connector-account-7-export');
        expect(btn).toBeDisabled();
        expect(btn).toHaveTextContent('Exporting…');
    });

    it('does NOT render Export / Import for an OAuth (non-credential) connector', () => {
        const acct = account(3, 'Drive');
        renderCard(oauthEntry([acct]));
        expect(screen.queryByTestId('connector-account-3-export')).not.toBeInTheDocument();
        expect(screen.queryByTestId('connector-google-drive-import-account')).not.toBeInTheDocument();
    });

    it('does NOT render Export on a pending account (no verified credentials yet)', () => {
        const acct = account(9, 'Pending', 'pending');
        renderCard(credentialEntry([acct]));
        expect(screen.queryByTestId('connector-account-9-export')).not.toBeInTheDocument();
    });
});
