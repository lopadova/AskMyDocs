import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConnectorCard } from './ConnectorCard';
import type { ConnectorEntry } from './connectors.api';

/*
 * Component-level tests for the connector card. We assert that the
 * rendered DOM matches the documented testid hierarchy + that each
 * status surface fires the correct callback. Mocking aligned with
 * R16 — a "fires onSync when Sync now is clicked" test must actually
 * click that button.
 */

type MakeEntryStatus = 'not_installed' | 'active' | 'pending' | 'errored' | 'disabled';

function makeEntry(status: MakeEntryStatus): ConnectorEntry {
    if (status === 'not_installed') {
        return {
            key: 'google-drive',
            display_name: 'Google Drive',
            icon_url: '/connectors/google-drive.svg',
            oauth_scopes: ['drive.readonly'],
            installation: null,
        };
    }
    return {
        key: 'google-drive',
        display_name: 'Google Drive',
        icon_url: '/connectors/google-drive.svg',
        oauth_scopes: ['drive.readonly'],
        installation: {
            id: 42,
            status,
            last_sync_at: '2026-05-11T11:00:00Z',
            error:
                status === 'errored'
                    ? { message: 'Token revoked by provider.' }
                    : null,
        },
    };
}

const NOOP_PROPS = {
    onConnect: vi.fn(),
    onSync: vi.fn(),
    onDisconnect: vi.fn(),
    onCancelInstall: vi.fn(),
    pending: { connecting: false, syncing: false, disconnecting: false },
};

describe('ConnectorCard', () => {
    it('renders the Connect button on not_installed and fires onConnect with the key', async () => {
        const onConnect = vi.fn();
        render(
            <ConnectorCard
                {...NOOP_PROPS}
                onConnect={onConnect}
                entry={makeEntry('not_installed')}
            />,
        );

        expect(screen.getByTestId('connector-list-card-google-drive')).toHaveAttribute(
            'data-status',
            'not_installed',
        );
        const btn = screen.getByTestId('connector-google-drive-connect');
        expect(btn).toBeVisible();
        await userEvent.click(btn);
        expect(onConnect).toHaveBeenCalledTimes(1);
        expect(onConnect).toHaveBeenCalledWith('google-drive');
    });

    it('renders Sync now + Disconnect on active and fires the correct handler', async () => {
        const onSync = vi.fn();
        render(
            <ConnectorCard
                {...NOOP_PROPS}
                onSync={onSync}
                entry={makeEntry('active')}
            />,
        );

        expect(screen.getByTestId('connector-list-card-google-drive')).toHaveAttribute(
            'data-status',
            'active',
        );
        expect(screen.getByTestId('connector-google-drive-disconnect')).toBeVisible();
        const syncBtn = screen.getByTestId('connector-google-drive-sync');
        await userEvent.click(syncBtn);
        expect(onSync).toHaveBeenCalledWith(42);
    });

    it('opens a confirm step before firing Disconnect', async () => {
        const onDisconnect = vi.fn();
        render(
            <ConnectorCard
                {...NOOP_PROPS}
                onDisconnect={onDisconnect}
                entry={makeEntry('active')}
            />,
        );

        // First click → confirm + cancel pair appear, no callback yet.
        await userEvent.click(screen.getByTestId('connector-google-drive-disconnect'));
        expect(onDisconnect).not.toHaveBeenCalled();
        const confirmBtn = screen.getByTestId('connector-google-drive-disconnect-confirm');
        expect(confirmBtn).toBeVisible();
        expect(screen.getByTestId('connector-google-drive-disconnect-cancel')).toBeVisible();

        // Confirm → callback fires.
        await userEvent.click(confirmBtn);
        expect(onDisconnect).toHaveBeenCalledWith(42);
    });

    it('renders the error block + Retry sync on errored', () => {
        render(<ConnectorCard {...NOOP_PROPS} entry={makeEntry('errored')} />);
        expect(screen.getByTestId('connector-google-drive-error')).toHaveTextContent(
            'Token revoked by provider.',
        );
        expect(screen.getByTestId('connector-google-drive-sync')).toHaveTextContent('Retry sync');
    });

    it('renders the Cancel install affordance on pending', async () => {
        const onCancel = vi.fn();
        render(
            <ConnectorCard
                {...NOOP_PROPS}
                onCancelInstall={onCancel}
                entry={makeEntry('pending')}
            />,
        );
        expect(screen.getByTestId('connector-list-card-google-drive')).toHaveAttribute(
            'data-status',
            'pending',
        );
        await userEvent.click(screen.getByTestId('connector-google-drive-cancel-install'));
        expect(onCancel).toHaveBeenCalledWith(42);
    });

    it('shows the spinner only while pending', () => {
        const { rerender } = render(
            <ConnectorCard {...NOOP_PROPS} entry={makeEntry('pending')} />,
        );
        expect(screen.queryByTestId('connector-google-drive-spinner')).toBeInTheDocument();

        rerender(<ConnectorCard {...NOOP_PROPS} entry={makeEntry('active')} />);
        expect(screen.queryByTestId('connector-google-drive-spinner')).not.toBeInTheDocument();
    });

    it('renders the Re-enable + Remove pair on disabled', () => {
        render(<ConnectorCard {...NOOP_PROPS} entry={makeEntry('disabled')} />);
        expect(screen.getByTestId('connector-google-drive-reenable')).toBeVisible();
        expect(screen.getByTestId('connector-google-drive-disconnect')).toBeVisible();
    });

    it('exposes an aria-label combining display name + status', () => {
        render(<ConnectorCard {...NOOP_PROPS} entry={makeEntry('active')} />);
        const card = screen.getByTestId('connector-list-card-google-drive');
        expect(card).toHaveAttribute('aria-label', 'Google Drive — Active');
    });

    it('disables Sync now and shows "Queuing…" while a sync mutation is pending', () => {
        render(
            <ConnectorCard
                {...NOOP_PROPS}
                pending={{ connecting: false, syncing: true, disconnecting: false }}
                entry={makeEntry('active')}
            />,
        );
        const btn = screen.getByTestId('connector-google-drive-sync');
        expect(btn).toBeDisabled();
        expect(btn).toHaveTextContent('Queuing…');
    });
});
