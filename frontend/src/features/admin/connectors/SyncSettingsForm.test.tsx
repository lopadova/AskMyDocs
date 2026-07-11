import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SyncSettingsForm } from './SyncSettingsForm';
import type { ConnectorInstallationDto, CredentialFieldSchema } from './connectors.api';

/*
 * v8.31 — the redesigned tri-state Sync-settings tab. R16: rule-change tests click
 * the real segment and assert BOTH the new active state AND the serialized payload;
 * the folder list is driven by a mocked live-folder hook (the only boundary).
 */

const { foldersMock } = vi.hoisted(() => ({ foldersMock: vi.fn() }));
vi.mock('./connectors-hooks', () => ({
    useInstallationFolders: (...args: unknown[]) => foldersMock(...args),
}));

function field(partial: Partial<CredentialFieldSchema>): CredentialFieldSchema {
    return {
        name: 'x', label: 'X', type: 'text', target: 'config', required: false, secret: false,
        default: null, options: {}, showIf: null, help: null, group: null, discovery: null, ...partial,
    };
}

const SCHEMA: CredentialFieldSchema[] = [
    field({ name: 'folders.include', label: 'Folders to sync', type: 'multiselect', discovery: 'folders', group: 'Folders', default: [] }),
    field({ name: 'folders.exclude', label: 'Folders to skip', type: 'multiselect', discovery: 'folders', group: 'Folders', default: [] }),
    field({ name: 'date_window_days', label: 'Sync window (days)', type: 'number', group: 'Sync window', default: 365 }),
    field({ name: 'only_unseen', label: 'Only unread messages', type: 'checkbox', group: 'Scope', default: false }),
    field({ name: 'reconcile_deletions', label: 'Remove docs for deleted emails', type: 'checkbox', group: 'Scope', default: false }),
    field({ name: 'body_format', label: 'Body format', type: 'select', group: 'Content', default: 'prefer_text', options: { prefer_text: 'Plain', prefer_html: 'HTML' } }),
];

function account(settings: Record<string, unknown> = {}): ConnectorInstallationDto {
    return {
        id: 7, label: 'Support', project_key: null, status: 'active', last_sync_at: null, error: null,
        folders: { include: [] }, date_window_days: null,
        connection_settings_schema: SCHEMA, settings,
    };
}

function readyFolders(list: string[]) {
    foldersMock.mockReturnValue({ data: list, isLoading: false, isError: false, refetch: vi.fn() });
}

function renderForm(settings: Record<string, unknown> = {}, onSubmit = vi.fn()) {
    render(
        <SyncSettingsForm
            connectorKey="imap"
            account={account(settings)}
            onSubmit={onSubmit}
            onClose={vi.fn()}
        />,
    );
    return onSubmit;
}

describe('SyncSettingsForm (tri-state)', () => {
    it('derives each folder rule from include (sync) / exclude (skip) / neither (auto)', () => {
        readyFolders(['INBOX', 'Archive', 'Spam']);
        renderForm({ folders: { include: ['INBOX'], exclude: ['Spam'] } });

        expect(screen.getByTestId('connector-imap-settings-folder-inbox')).toHaveAttribute('data-rule', 'sync');
        expect(screen.getByTestId('connector-imap-settings-folder-spam')).toHaveAttribute('data-rule', 'skip');
        expect(screen.getByTestId('connector-imap-settings-folder-archive')).toHaveAttribute('data-rule', 'auto');
        // Summary reflects the counts (over all known folders).
        expect(screen.getByTestId('connector-imap-settings-folder-summary')).toHaveTextContent('1 sync · 1 skip · 1 auto');
    });

    it('flips a folder Auto→Skip and serializes it into folders.exclude', async () => {
        readyFolders(['INBOX', 'Archive']);
        const onSubmit = renderForm();

        // Archive starts Auto.
        expect(screen.getByTestId('connector-imap-settings-folder-archive')).toHaveAttribute('data-rule', 'auto');
        await userEvent.click(screen.getByTestId('connector-imap-settings-folder-archive-skip'));
        expect(screen.getByTestId('connector-imap-settings-folder-archive')).toHaveAttribute('data-rule', 'skip');
        expect(screen.getByTestId('connector-imap-settings-folder-summary')).toHaveTextContent('0 sync · 1 skip · 1 auto');

        await userEvent.click(screen.getByTestId('connector-imap-settings-form-submit'));
        const settings = onSubmit.mock.calls[0][0];
        expect(settings.folders).toEqual({ include: [], exclude: ['Archive'] });
    });

    it('Reset all to Auto clears include + exclude', async () => {
        readyFolders(['INBOX', 'Spam']);
        const onSubmit = renderForm({ folders: { include: ['INBOX'], exclude: ['Spam'] } });

        await userEvent.click(screen.getByTestId('connector-imap-settings-folder-reset'));
        expect(screen.getByTestId('connector-imap-settings-folder-inbox')).toHaveAttribute('data-rule', 'auto');
        expect(screen.getByTestId('connector-imap-settings-folder-spam')).toHaveAttribute('data-rule', 'auto');

        await userEvent.click(screen.getByTestId('connector-imap-settings-form-submit'));
        expect(onSubmit.mock.calls[0][0].folders).toEqual({ include: [], exclude: [] });
    });

    it('filters the folder list by the search box', async () => {
        readyFolders(['INBOX', 'INBOX/Sent', 'Archive']);
        renderForm();

        await userEvent.type(screen.getByTestId('connector-imap-settings-folder-search'), 'arch');
        expect(screen.getByTestId('connector-imap-settings-folder-archive')).toBeVisible();
        expect(screen.queryByTestId('connector-imap-settings-folder-inbox')).toBeNull();
    });

    it('flags a saved-but-vanished folder as "not on server" but keeps it editable', () => {
        // "Legacy" is in exclude but NOT in the live list → still shown + flagged.
        readyFolders(['INBOX']);
        renderForm({ folders: { include: [], exclude: ['Legacy'] } });

        const row = screen.getByTestId('connector-imap-settings-folder-legacy');
        expect(row).toHaveAttribute('data-rule', 'skip');
        expect(screen.getByTestId('connector-imap-settings-folder-legacy-missing')).toBeVisible();
    });

    it('edits the sync window and serializes it as a number', async () => {
        readyFolders(['INBOX']);
        const onSubmit = renderForm({ date_window_days: 365 });

        const input = screen.getByTestId('connector-imap-settings-date-window-days');
        await userEvent.clear(input);
        await userEvent.type(input, '90');
        await userEvent.click(screen.getByTestId('connector-imap-settings-form-submit'));

        expect(onSubmit.mock.calls[0][0].date_window_days).toBe(90);
    });

    it('renders scope checkboxes as switches and toggles them into the payload', async () => {
        readyFolders(['INBOX']);
        const onSubmit = renderForm();

        const toggle = screen.getByTestId('connector-imap-settings-only-unseen');
        expect(toggle).toHaveAttribute('role', 'switch');
        expect(toggle).toHaveAttribute('aria-checked', 'false');
        await userEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-checked', 'true');

        await userEvent.click(screen.getByTestId('connector-imap-settings-form-submit'));
        expect(onSubmit.mock.calls[0][0].only_unseen).toBe(true);
    });

    it('preserves non-mockup schema groups (Content) via the generic renderer', () => {
        readyFolders(['INBOX']);
        renderForm();
        // The Content group's body_format select is still present (not dropped).
        const content = screen.getByTestId('connector-imap-settings-group-content');
        expect(within(content).getByTestId('connector-imap-settings-body-format')).toBeVisible();
    });

    it('surfaces the folder-fetch error state (R14)', () => {
        foldersMock.mockReturnValue({ data: undefined, isLoading: false, isError: true, refetch: vi.fn() });
        render(
            <SyncSettingsForm connectorKey="imap" account={account()} onSubmit={vi.fn()} onClose={vi.fn()} />,
        );
        expect(screen.getByTestId('connector-imap-settings-form')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('connector-imap-settings-folder-fetch-error')).toBeVisible();
    });
});
