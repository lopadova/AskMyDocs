import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AccountEditModal } from './AccountEditModal';
import type { ConnectorEntry, ConnectorInstallationDto, CredentialFieldSchema } from './connectors.api';

/*
 * v8.29 — the tabbed Edit modal. R16: every "does X" test drives the transition it
 * claims (switches the tab, dispatches the submit) rather than asserting the
 * initial render. The export prefill + folder hooks are the only mocked boundary.
 */

const { mockExport } = vi.hoisted(() => ({ mockExport: vi.fn() }));
vi.mock('./connectors-hooks', () => ({
    useInstallationExport: (...args: unknown[]) => mockExport(...args),
    // The Sync tab (SyncSettingsForm) pulls the live folder list from here.
    useInstallationFolders: () => ({ data: [], isLoading: false, isError: false, refetch: vi.fn() }),
    // v8.31 — the Details tab's stat cards.
    useInstallationStats: () => ({
        data: { documents_synced: 4182, last_sync_at: null },
        isLoading: false,
        isError: false,
    }),
}));

function field(partial: Partial<CredentialFieldSchema>): CredentialFieldSchema {
    return {
        name: 'x', label: 'X', type: 'text', target: 'connection', required: false, secret: false,
        default: null, options: {}, showIf: null, help: null, group: null, discovery: null, ...partial,
    };
}

const CREDENTIAL_SCHEMA: CredentialFieldSchema[] = [
    field({ name: 'auth_mode', label: 'Auth', type: 'select', target: 'auth_mode', required: true, default: 'basic', options: { basic: 'Password' } }),
    field({ name: 'host', label: 'IMAP Host', target: 'connection', required: true, showIf: { field: 'auth_mode', equals: 'basic' } }),
    field({ name: 'username', label: 'Username', target: 'connection', required: true }),
    field({ name: 'password', label: 'Password', type: 'password', target: 'secret', required: true, secret: true, showIf: { field: 'auth_mode', equals: 'basic' } }),
];

function credentialEntry(): ConnectorEntry {
    return {
        key: 'imap', display_name: 'Email (IMAP)', icon_url: '/connectors/imap.svg', oauth_scopes: [],
        auth_kind: 'credential', credential_form_schema: CREDENTIAL_SCHEMA, installations: [],
    };
}

function account(): ConnectorInstallationDto {
    return {
        id: 7, label: 'Date', project_key: 'support-mailbox', status: 'active', last_sync_at: null, error: null,
        folders: { include: [] }, date_window_days: null, connection_settings_schema: [], settings: {},
    };
}

function exportReady() {
    mockExport.mockReturnValue({
        data: { params: { auth_mode: 'basic', host: 'imap.example.com', username: 'alice@example.com', port: 993 } },
        isLoading: false, isError: false, refetch: vi.fn(), dataUpdatedAt: 1,
    });
}

interface Handlers {
    onSubmitDetails?: ReturnType<typeof vi.fn>;
    onSubmitConnection?: ReturnType<typeof vi.fn>;
    onTestConnection?: ReturnType<typeof vi.fn>;
    onSubmitSettings?: ReturnType<typeof vi.fn>;
    onClose?: ReturnType<typeof vi.fn>;
    initialTab?: 'details' | 'connection' | 'settings';
}

function renderModal(h: Handlers = {}) {
    const handlers = {
        onSubmitDetails: h.onSubmitDetails ?? vi.fn().mockResolvedValue(undefined),
        onSubmitConnection: h.onSubmitConnection ?? vi.fn().mockResolvedValue(undefined),
        onTestConnection: h.onTestConnection ?? vi.fn().mockResolvedValue({ ok: true }),
        onSubmitSettings: h.onSubmitSettings ?? vi.fn().mockResolvedValue(undefined),
        onClose: h.onClose ?? vi.fn(),
    };
    render(
        <AccountEditModal
            entry={credentialEntry()}
            account={account()}
            projects={[]}
            initialTab={h.initialTab}
            {...handlers}
        />,
    );
    return handlers;
}

beforeEach(() => {
    mockExport.mockReset();
    mockExport.mockReturnValue({ data: undefined, isLoading: false, isError: false, refetch: vi.fn(), dataUpdatedAt: 0 });
});

describe('AccountEditModal', () => {
    it('renders all three tabs for a credential connector and Details by default', () => {
        renderModal();
        expect(screen.getByTestId('connector-account-7-edit-tab-details')).toBeInTheDocument();
        expect(screen.getByTestId('connector-account-7-edit-tab-connection')).toBeInTheDocument();
        expect(screen.getByTestId('connector-account-7-edit-tab-settings')).toBeInTheDocument();
        expect(screen.getByTestId('connector-account-7-edit-modal')).toHaveAttribute('data-active-tab', 'details');
        // The tabpanel is labelled by its active tab (a11y).
        expect(screen.getByRole('tabpanel')).toHaveAttribute(
            'aria-labelledby',
            'connector-account-7-edit-tab-details',
        );
        // Details tab body: the metadata form, pre-filled with the label.
        expect(screen.getByTestId('connector-imap-account-form-label')).toHaveValue('Date');
        // v8.31 — the redesigned Details tab shows the two stat cards.
        expect(screen.getByTestId('connector-account-7-edit-stat-documents')).toHaveTextContent('4,182');
        expect(screen.getByTestId('connector-account-7-edit-stat-last-sync')).toHaveTextContent('Never');
    });

    it('switches to the Connection tab and prefills the fetched host', async () => {
        exportReady();
        renderModal();
        await userEvent.click(screen.getByTestId('connector-account-7-edit-tab-connection'));

        expect(screen.getByTestId('connector-account-7-edit-modal')).toHaveAttribute('data-active-tab', 'connection');
        // Prefilled non-secret connection field (edit mode).
        expect(screen.getByTestId('connector-imap-form-host')).toHaveValue('imap.example.com');
        // The password is NEVER prefilled and is optional in edit mode (no required).
        const pw = screen.getByTestId('connector-imap-form-password');
        expect(pw).toHaveValue('');
        expect(pw).not.toBeRequired();
        // The label/project account fields are NOT part of the Connection tab.
        expect(screen.queryByTestId('connector-imap-form-label')).not.toBeInTheDocument();
    });

    it('shows a loading state while the connection prefill is in flight', async () => {
        mockExport.mockReturnValue({ data: undefined, isLoading: true, isError: false, refetch: vi.fn(), dataUpdatedAt: 0 });
        renderModal();
        await userEvent.click(screen.getByTestId('connector-account-7-edit-tab-connection'));
        expect(screen.getByTestId('connector-imap-connection-prefill-loading')).toBeInTheDocument();
    });

    it('shows an error + retry when the connection prefill fails', async () => {
        const refetch = vi.fn();
        mockExport.mockReturnValue({ data: undefined, isLoading: false, isError: true, refetch, dataUpdatedAt: 0 });
        renderModal();
        await userEvent.click(screen.getByTestId('connector-account-7-edit-tab-connection'));
        expect(screen.getByTestId('connector-imap-connection-prefill-error')).toBeInTheDocument();
        await userEvent.click(screen.getByTestId('connector-imap-connection-prefill-retry'));
        expect(refetch).toHaveBeenCalled();
    });

    it('saves Details from the shared footer and closes on success', async () => {
        // v8.31 — the redesigned modal owns ONE footer Save (submits the active
        // tab's footerless form via the `form` attribute).
        const onSubmitDetails = vi.fn().mockResolvedValue(undefined);
        const onClose = vi.fn();
        renderModal({ onSubmitDetails, onClose });

        await userEvent.click(screen.getByTestId('connector-account-7-edit-save'));

        await waitFor(() => expect(onSubmitDetails).toHaveBeenCalledTimes(1));
        expect(onSubmitDetails.mock.calls[0][0]).toMatchObject({ label: 'Date' });
        await waitFor(() => expect(onClose).toHaveBeenCalled());
    });

    it('keeps the modal open and shows the error in the tab body when a save fails', async () => {
        // R16 failure path — the reject actually fires and the modal must NOT close.
        // ONE error surface: the message renders in the active tab body (Details =
        // the metadata form), never duplicated in the footer.
        const onSubmitDetails = vi.fn().mockRejectedValue({ response: { data: { error: 'Label already taken' } } });
        const onClose = vi.fn();
        renderModal({ onSubmitDetails, onClose });

        await userEvent.click(screen.getByTestId('connector-account-7-edit-save'));

        await waitFor(() =>
            expect(screen.getByTestId('connector-imap-account-form-error')).toHaveTextContent('Label already taken'),
        );
        // No duplicate footer error copy.
        expect(screen.queryByTestId('connector-account-7-edit-error')).toBeNull();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('saves the Connection tab with a blank password (keep current) without a pre-test', async () => {
        exportReady();
        const onSubmitConnection = vi.fn().mockResolvedValue(undefined);
        renderModal({ onSubmitConnection });

        await userEvent.click(screen.getByTestId('connector-account-7-edit-tab-connection'));
        // In edit mode Save is NOT gated on a passing test (blank password = keep);
        // the footer Save submits the Connection form.
        const save = screen.getByTestId('connector-account-7-edit-save');
        expect(save).toBeEnabled();
        await userEvent.click(save);

        await waitFor(() => expect(onSubmitConnection).toHaveBeenCalledTimes(1));
        const payload = onSubmitConnection.mock.calls[0][0];
        expect(payload).toMatchObject({ host: 'imap.example.com', username: 'alice@example.com' });
        // A blank password is omitted (keep current) and no account label is injected.
        expect(payload).not.toHaveProperty('password');
        expect(payload).not.toHaveProperty('label');
    });

    it('disables the footer Save while the Connection prefill is still loading', async () => {
        mockExport.mockReturnValue({ data: undefined, isLoading: true, isError: false, refetch: vi.fn(), dataUpdatedAt: 0 });
        renderModal();
        await userEvent.click(screen.getByTestId('connector-account-7-edit-tab-connection'));
        expect(screen.getByTestId('connector-account-7-edit-save')).toBeDisabled();
    });
});
