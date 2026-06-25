import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConnectionSettingsForm } from './ConnectionSettingsForm';
import type { ConnectorInstallationDto, CredentialFieldSchema } from './connectors.api';

const { mockFolders } = vi.hoisted(() => ({ mockFolders: vi.fn() }));
vi.mock('./connectors-hooks', () => ({
    useInstallationFolders: (...args: unknown[]) => mockFolders(...args),
}));

function field(partial: Partial<CredentialFieldSchema>): CredentialFieldSchema {
    return {
        name: 'x',
        label: 'X',
        type: 'text',
        target: 'config',
        required: false,
        secret: false,
        default: null,
        options: {},
        showIf: null,
        help: null,
        group: null,
        discovery: null,
        ...partial,
    };
}

const SCHEMA: CredentialFieldSchema[] = [
    field({ name: 'folders.include', label: 'Folders to sync', type: 'multiselect', default: [], discovery: 'folders', group: 'Folders' }),
    field({ name: 'folders.exclude', label: 'Folders to skip', type: 'multiselect', default: [], discovery: 'folders', group: 'Folders' }),
    field({ name: 'date_window_days', label: 'Sync window (days)', type: 'number', default: 365, group: 'Sync window' }),
    field({ name: 'senders.exclude', label: 'Exclude senders', type: 'tags', default: [], group: 'Filtering' }),
    field({ name: 'body_format', label: 'Body format', type: 'select', default: 'prefer_text', options: { prefer_text: 'Text', prefer_html: 'HTML' }, group: 'Content' }),
    field({ name: 'skip_auto_generated', label: 'Skip auto-generated', type: 'checkbox', default: true, group: 'Content' }),
];

function makeAccount(settings: Record<string, unknown> = {}): ConnectorInstallationDto {
    return {
        id: 7,
        label: 'mbox',
        project_key: null,
        status: 'active',
        last_sync_at: null,
        error: null,
        folders: { include: [] },
        date_window_days: null,
        connection_settings_schema: SCHEMA,
        settings,
    };
}

function renderForm(opts: { settings?: Record<string, unknown> } = {}) {
    const onSubmit = vi.fn();
    const onClose = vi.fn();
    render(
        <ConnectionSettingsForm
            connectorKey="imap"
            account={makeAccount(opts.settings)}
            onSubmit={onSubmit}
            onClose={onClose}
        />,
    );
    return { onSubmit, onClose };
}

describe('ConnectionSettingsForm', () => {
    beforeEach(() => {
        mockFolders.mockReturnValue({ data: ['INBOX', 'Archive', 'Trash'], isLoading: false, isError: false, refetch: vi.fn() });
    });

    it('renders fields grouped and seeded from the current settings', () => {
        renderForm({
            settings: {
                folders: { include: ['INBOX'], exclude: ['Trash'] },
                date_window_days: 90,
                senders: { exclude: ['noreply@x.com'] },
                body_format: 'prefer_html',
                skip_auto_generated: false,
            },
        });

        // Grouped sections present.
        expect(screen.getByTestId('connector-imap-settings-group-folders')).toBeTruthy();
        expect(screen.getByTestId('connector-imap-settings-group-filtering')).toBeTruthy();

        // Folder include seeds INBOX checked; the live 'Archive' is present + unchecked.
        expect((screen.getByTestId('connector-imap-settings-folders-include-opt-inbox') as HTMLInputElement).checked).toBe(true);
        expect((screen.getByTestId('connector-imap-settings-folders-include-opt-archive') as HTMLInputElement).checked).toBe(false);

        // Number seeds the stored value (not the schema default).
        expect((screen.getByTestId('connector-imap-settings-date-window-days') as HTMLInputElement).value).toBe('90');

        // Tags seed a chip.
        expect(screen.getByTestId('connector-imap-settings-senders-exclude-chip-noreply-x-com-remove')).toBeTruthy();

        // Select + checkbox seed.
        expect((screen.getByTestId('connector-imap-settings-body-format') as HTMLSelectElement).value).toBe('prefer_html');
        expect((screen.getByTestId('connector-imap-settings-skip-auto-generated') as HTMLInputElement).checked).toBe(false);
    });

    it('assembles a nested settings payload on submit', () => {
        const { onSubmit } = renderForm({ settings: { folders: { exclude: ['Trash'] }, date_window_days: 90 } });

        // Toggle a folder ON (include INBOX), change the window, add a sender tag.
        fireEvent.click(screen.getByTestId('connector-imap-settings-folders-include-opt-inbox'));
        fireEvent.change(screen.getByTestId('connector-imap-settings-date-window-days'), { target: { value: '30' } });
        const sender = screen.getByTestId('connector-imap-settings-senders-exclude-input');
        fireEvent.change(sender, { target: { value: 'spam@x.com' } });
        fireEvent.keyDown(sender, { key: 'Enter' });

        fireEvent.submit(screen.getByTestId('connector-imap-settings-form'));

        expect(onSubmit).toHaveBeenCalledTimes(1);
        const payload = onSubmit.mock.calls[0][0] as Record<string, unknown>;
        expect((payload.folders as Record<string, unknown>).include).toEqual(['INBOX']);
        expect((payload.folders as Record<string, unknown>).exclude).toEqual(['Trash']);
        // Number is sent as a real number, not a string.
        expect(payload.date_window_days).toBe(30);
        expect((payload.senders as Record<string, unknown>).exclude).toEqual(['spam@x.com']);
    });

    it('removes a folder from the selection when unchecked', () => {
        const { onSubmit } = renderForm({ settings: { folders: { exclude: ['Trash'] } } });

        // Trash is seeded checked in the exclude list — uncheck it.
        const trash = screen.getByTestId('connector-imap-settings-folders-exclude-opt-trash') as HTMLInputElement;
        expect(trash.checked).toBe(true);
        fireEvent.click(trash);

        fireEvent.submit(screen.getByTestId('connector-imap-settings-form'));
        const payload = onSubmit.mock.calls[0][0] as Record<string, unknown>;
        expect((payload.folders as Record<string, unknown>).exclude).toEqual([]);
    });

    it('removes a tag chip', () => {
        const { onSubmit } = renderForm({ settings: { senders: { exclude: ['a@x.com', 'b@x.com'] } } });

        fireEvent.click(screen.getByTestId('connector-imap-settings-senders-exclude-chip-a-x-com-remove'));
        fireEvent.submit(screen.getByTestId('connector-imap-settings-form'));

        const payload = onSubmit.mock.calls[0][0] as Record<string, unknown>;
        expect((payload.senders as Record<string, unknown>).exclude).toEqual(['b@x.com']);
    });

    it('shows an error state with retry when folder discovery fails', () => {
        const refetch = vi.fn();
        mockFolders.mockReturnValue({ data: undefined, isLoading: false, isError: true, refetch });

        renderForm();

        const err = screen.getAllByTestId('connector-imap-settings-folders-include-fetch-error');
        expect(err.length).toBeGreaterThan(0);
        fireEvent.click(screen.getByTestId('connector-imap-settings-folders-include-retry'));
        expect(refetch).toHaveBeenCalled();
    });

    it('cancel calls onClose', () => {
        const { onClose } = renderForm();
        fireEvent.click(screen.getByTestId('connector-imap-settings-form-cancel'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
