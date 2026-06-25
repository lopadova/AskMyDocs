import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConnectorCard } from './ConnectorCard';
import type { ConnectorEntry, ConnectorInstallationDto, ConnectorStatus } from './connectors.api';

/*
 * v8.20 — multi-account connector card. Tests assert the testid hierarchy and
 * that each per-account surface fires the correct callback. R16: a "fires X when
 * Y clicked" test actually clicks Y.
 */

function account(
    id: number,
    label: string,
    status: ConnectorStatus,
    project_key: string | null = null,
): ConnectorInstallationDto {
    return {
        id,
        label,
        project_key,
        status,
        last_sync_at: '2026-05-11T11:00:00Z',
        error: status === 'errored' ? { message: 'Token revoked by provider.' } : null,
        folders: { include: [] },
        date_window_days: null,
    };
}

function entry(installations: ConnectorInstallationDto[]): ConnectorEntry {
    return {
        key: 'google-drive',
        display_name: 'Google Drive',
        icon_url: '/connectors/google-drive.svg',
        oauth_scopes: ['drive.readonly'],
        auth_kind: 'oauth',
        credential_form_schema: null,
        installations,
    };
}

/** A credential connector (IMAP) — the auth_kind that exposes Folders + Test fetch. */
function credentialEntry(installations: ConnectorInstallationDto[]): ConnectorEntry {
    return {
        key: 'imap',
        display_name: 'Email (IMAP)',
        icon_url: '/connectors/imap.svg',
        oauth_scopes: [],
        auth_kind: 'credential',
        credential_form_schema: [],
        installations,
    };
}

const NOOP = {
    onAddAccount: vi.fn(),
    onSync: vi.fn(),
    onDisable: vi.fn(),
    onEnable: vi.fn(),
    onRemove: vi.fn(),
    onEdit: vi.fn(),
    onTestFetch: vi.fn(),
    onCancelInstall: vi.fn(),
};

describe('ConnectorCard (multi-account)', () => {
    it('renders the empty state + a Connect CTA when there are no accounts', async () => {
        const onAdd = vi.fn();
        render(<ConnectorCard {...NOOP} onAddAccount={onAdd} entry={entry([])} />);

        const card = screen.getByTestId('connector-list-card-google-drive');
        expect(card).toHaveAttribute('data-status', 'not_installed');
        expect(card).toHaveAttribute('data-account-count', '0');
        expect(screen.getByTestId('connector-google-drive-no-accounts')).toBeVisible();

        const addBtn = screen.getByTestId('connector-google-drive-add-account');
        expect(addBtn).toHaveTextContent('Connect');
        await userEvent.click(addBtn);
        expect(onAdd).toHaveBeenCalledWith('google-drive');
    });

    it('lists multiple accounts with their label + project binding', () => {
        render(
            <ConnectorCard
                {...NOOP}
                entry={entry([account(1, 'support', 'active', 'acme-hr'), account(2, 'sales', 'active')])}
            />,
        );
        const card = screen.getByTestId('connector-list-card-google-drive');
        expect(card).toHaveAttribute('data-account-count', '2');
        expect(screen.getByTestId('connector-account-1-label')).toHaveTextContent('support');
        expect(screen.getByTestId('connector-account-1-project')).toHaveTextContent('acme-hr');
        // Unbound account shows the tenant-default sentinel.
        expect(screen.getByTestId('connector-account-2-project')).toHaveTextContent('Global');
        // The header CTA becomes "Add account" once at least one exists.
        expect(screen.getByTestId('connector-google-drive-add-account')).toHaveTextContent('Add account');
    });

    it('fires onSync with the account id when Sync now is clicked', async () => {
        const onSync = vi.fn();
        render(<ConnectorCard {...NOOP} onSync={onSync} entry={entry([account(42, 'support', 'active')])} />);
        await userEvent.click(screen.getByTestId('connector-account-42-sync'));
        expect(onSync).toHaveBeenCalledWith(42);
    });

    it('fires onEdit with the account when Edit is clicked', async () => {
        const onEdit = vi.fn();
        const acct = account(7, 'support', 'active', 'acme-hr');
        render(<ConnectorCard {...NOOP} onEdit={onEdit} entry={entry([acct])} />);
        await userEvent.click(screen.getByTestId('connector-account-7-edit'));
        expect(onEdit).toHaveBeenCalledWith(acct);
    });

    it('requires a confirm step before firing onRemove', async () => {
        const onRemove = vi.fn();
        render(<ConnectorCard {...NOOP} onRemove={onRemove} entry={entry([account(9, 'support', 'active')])} />);
        await userEvent.click(screen.getByTestId('connector-account-9-remove'));
        expect(onRemove).not.toHaveBeenCalled();
        await userEvent.click(screen.getByTestId('connector-account-9-remove-confirm'));
        expect(onRemove).toHaveBeenCalledWith(9);
    });

    it('renders the error block + Retry sync on an errored account', () => {
        render(<ConnectorCard {...NOOP} entry={entry([account(3, 'support', 'errored')])} />);
        expect(screen.getByTestId('connector-account-3-error')).toHaveTextContent('Token revoked by provider.');
        expect(screen.getByTestId('connector-account-3-sync')).toHaveTextContent('Retry sync');
    });

    it('renders Cancel install on a pending account', async () => {
        const onCancel = vi.fn();
        render(<ConnectorCard {...NOOP} onCancelInstall={onCancel} entry={entry([account(5, 'support', 'pending')])} />);
        await userEvent.click(screen.getByTestId('connector-account-5-cancel-install'));
        expect(onCancel).toHaveBeenCalledWith(5);
    });

    it('disables sync + shows "Queuing…" for the account whose sync is in flight', () => {
        render(
            <ConnectorCard {...NOOP} syncingIds={new Set([42])} entry={entry([account(42, 'support', 'active')])} />,
        );
        const btn = screen.getByTestId('connector-account-42-sync');
        expect(btn).toBeDisabled();
        expect(btn).toHaveTextContent('Queuing…');
    });

    it('announces each account row with its label + status', () => {
        render(<ConnectorCard {...NOOP} entry={entry([account(1, 'support', 'active')])} />);
        expect(screen.getByTestId('connector-account-1')).toHaveAttribute('aria-label', 'support — Active');
    });

    it('exposes Enable (not Sync/Disable) on a disabled account and fires onEnable', async () => {
        // The fix for "disabled accounts could not be re-enabled": a disabled row
        // shows Enable + Edit + Remove, and NO Sync / Disable.
        const onEnable = vi.fn();
        render(<ConnectorCard {...NOOP} onEnable={onEnable} entry={entry([account(11, 'support', 'disabled')])} />);

        expect(screen.queryByTestId('connector-account-11-sync')).toBeNull();
        expect(screen.queryByTestId('connector-account-11-disable')).toBeNull();
        const enableBtn = screen.getByTestId('connector-account-11-enable');
        expect(enableBtn).toHaveTextContent('Enable');
        await userEvent.click(enableBtn);
        expect(onEnable).toHaveBeenCalledWith(11);
    });

    it('disables Enable + shows "Enabling…" while the enable is in flight', () => {
        render(
            <ConnectorCard {...NOOP} busyIds={new Set([11])} entry={entry([account(11, 'support', 'disabled')])} />,
        );
        const btn = screen.getByTestId('connector-account-11-enable');
        expect(btn).toBeDisabled();
        expect(btn).toHaveTextContent('Enabling…');
    });

    it('renders Test fetch on a credential connector account and fires onTestFetch', async () => {
        const onTestFetch = vi.fn();
        const acct = account(20, 'prometeo-1', 'active');
        render(
            <ConnectorCard {...NOOP} onTestFetch={onTestFetch} entry={credentialEntry([acct])} />,
        );
        const btn = screen.getByTestId('connector-account-20-test-fetch');
        expect(btn).toHaveTextContent('Test fetch');
        await userEvent.click(btn);
        expect(onTestFetch).toHaveBeenCalledWith(acct);
    });

    it('offers Test fetch even on an errored credential account (operator diagnostic)', () => {
        render(<ConnectorCard {...NOOP} entry={credentialEntry([account(21, 'prometeo-2', 'errored')])} />);
        expect(screen.getByTestId('connector-account-21-test-fetch')).toBeVisible();
    });

    it('does NOT render Test fetch for an OAuth (non-credential) connector', () => {
        render(<ConnectorCard {...NOOP} entry={entry([account(30, 'support', 'active')])} />);
        expect(screen.queryByTestId('connector-account-30-test-fetch')).toBeNull();
    });

    it('shows "Fetching…" + disables Test fetch while a probe is in flight, leaving Sync usable', () => {
        // Probe tracking is independent of the write actions: a probe in flight must
        // NOT disable Sync/Disable, and vice-versa.
        render(
            <ConnectorCard
                {...NOOP}
                probingIds={new Set([22])}
                entry={credentialEntry([account(22, 'prometeo-1', 'active')])}
            />,
        );
        const probe = screen.getByTestId('connector-account-22-test-fetch');
        expect(probe).toBeDisabled();
        expect(probe).toHaveTextContent('Fetching…');
        // Sync stays enabled — write actions are not blocked by a read-only probe.
        expect(screen.getByTestId('connector-account-22-sync')).toBeEnabled();
    });
});
