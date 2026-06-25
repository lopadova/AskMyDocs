import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { FolderSettingsForm } from './FolderSettingsForm';
import type { ConnectorInstallationDto } from './connectors.api';

/*
 * v8.24 — the connection-settings folder picker. IMAP folder discovery is the
 * only external boundary, so the api client is mocked; the rest (selection,
 * payload assembly, states) runs real. R16: each test drives the behaviour its
 * name promises.
 */

vi.mock('./connectors.api', () => ({
    adminConnectorsApi: { listFolders: vi.fn() },
}));

import { adminConnectorsApi } from './connectors.api';
const mockListFolders = adminConnectorsApi.listFolders as unknown as ReturnType<typeof vi.fn>;

function account(overrides: Partial<ConnectorInstallationDto> = {}): ConnectorInstallationDto {
    return {
        id: 7,
        label: 'rotta-1',
        project_key: null,
        status: 'active',
        last_sync_at: null,
        error: null,
        folders: { include: ['rotta-logistics-1'] },
        date_window_days: 365,
        ...overrides,
    };
}

function renderForm(props: Partial<React.ComponentProps<typeof FolderSettingsForm>> = {}) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={qc}>
            <FolderSettingsForm
                connectorKey="imap"
                account={account()}
                onSubmit={vi.fn()}
                onClose={vi.fn()}
                {...props}
            />
        </QueryClientProvider>,
    );
}

describe('FolderSettingsForm', () => {
    beforeEach(() => {
        mockListFolders.mockReset();
    });

    it('renders the live folder list and pre-checks the saved include paths', async () => {
        mockListFolders.mockResolvedValue(['INBOX', 'rotta-logistics-1', 'Sent']);
        renderForm();

        await screen.findByTestId('connector-imap-folders-form-list');

        const saved = screen.getByTestId(
            'connector-imap-folders-form-folder-rotta-logistics-1',
        ) as HTMLInputElement;
        const inbox = screen.getByTestId('connector-imap-folders-form-folder-inbox') as HTMLInputElement;
        // The saved path is checked; a non-saved live path is not (R16).
        expect(saved.checked).toBe(true);
        expect(inbox.checked).toBe(false);
    });

    it('submits the selected include paths + the sync window', async () => {
        mockListFolders.mockResolvedValue(['INBOX', 'rotta-logistics-1']);
        const onSubmit = vi.fn();
        renderForm({ onSubmit });

        await screen.findByTestId('connector-imap-folders-form-list');
        // Add INBOX to the existing {rotta-logistics-1} selection.
        fireEvent.click(screen.getByTestId('connector-imap-folders-form-folder-inbox'));
        fireEvent.click(screen.getByTestId('connector-imap-folders-form-submit'));

        expect(onSubmit).toHaveBeenCalledTimes(1);
        // Options are sorted; the payload preserves that order.
        expect(onSubmit.mock.calls[0][0]).toEqual({
            include: ['INBOX', 'rotta-logistics-1'],
            dateWindowDays: 365,
        });
    });

    it('shows the empty state when the mailbox has no folders', async () => {
        mockListFolders.mockResolvedValue([]);
        renderForm({ account: account({ folders: { include: [] } }) });

        await screen.findByTestId('connector-imap-folders-form-empty');
        expect(screen.queryByTestId('connector-imap-folders-form-list')).not.toBeInTheDocument();
    });

    it('surfaces the fetch error state with a retry', async () => {
        mockListFolders.mockRejectedValue(new Error('IMAP connect failed'));
        renderForm();

        await screen.findByTestId('connector-imap-folders-form-fetch-error');
        expect(screen.getByTestId('connector-imap-folders-form-retry')).toBeInTheDocument();
    });

    it('sends dateWindowDays null when the window field is cleared', async () => {
        mockListFolders.mockResolvedValue(['INBOX']);
        const onSubmit = vi.fn();
        renderForm({ account: account({ folders: { include: [] }, date_window_days: null }), onSubmit });

        await screen.findByTestId('connector-imap-folders-form-list');
        fireEvent.click(screen.getByTestId('connector-imap-folders-form-submit'));

        expect(onSubmit.mock.calls[0][0]).toEqual({ include: [], dateWindowDays: null });
    });

    it('keeps a saved-but-missing folder visible (checked + flagged) so a save never drops it', async () => {
        // 'rotta-logistics-1' is saved but no longer on the server.
        mockListFolders.mockResolvedValue(['INBOX']);
        renderForm();

        await screen.findByTestId('connector-imap-folders-form-list');
        const cb = screen.getByTestId(
            'connector-imap-folders-form-folder-rotta-logistics-1',
        ) as HTMLInputElement;
        expect(cb.checked).toBe(true);
        expect(
            screen.getByTestId('connector-imap-folders-form-folder-rotta-logistics-1-missing'),
        ).toBeInTheDocument();
    });

    it('closes on Escape', async () => {
        mockListFolders.mockResolvedValue(['INBOX']);
        const onClose = vi.fn();
        renderForm({ onClose });
        await screen.findByTestId('connector-imap-folders-form-list');
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
