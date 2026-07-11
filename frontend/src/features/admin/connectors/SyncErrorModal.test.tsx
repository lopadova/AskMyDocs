import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { buildConnections, type ConnectionVM } from './connection-vm';
import type { ConnectorEntry } from './connectors.api';
import { SyncErrorModal } from './SyncErrorModal';

/*
 * "Sync failed" detail modal. R16: retry/dismiss tests click the real buttons;
 * the full (untruncated) error text is asserted verbatim.
 */

const LONG_ERROR =
    'Authentication failed: the IMAP server rejected the supplied credentials ' +
    '(535 5.7.8 Username and Password not accepted). Re-authorize the account.';

function erroredVm(): ConnectionVM {
    const entry: ConnectorEntry = {
        key: 'imap',
        display_name: 'Email (IMAP)',
        icon_url: '',
        oauth_scopes: [],
        auth_kind: 'credential',
        credential_form_schema: [],
        installations: [
            {
                id: 11,
                label: 'support@acme.io',
                project_key: null,
                status: 'errored',
                last_sync_at: null,
                error: { message: LONG_ERROR },
                folders: { include: [] },
                date_window_days: null,
            },
        ],
    };
    return buildConnections([entry])[0];
}

describe('SyncErrorModal', () => {
    it('renders source · account and the FULL error detail (R14 — never truncated)', () => {
        render(<SyncErrorModal vm={erroredVm()} onClose={vi.fn()} onRetry={vi.fn()} />);
        expect(screen.getByRole('dialog')).toBeVisible();
        expect(screen.getByText('Sync failed')).toBeVisible();
        expect(screen.getByText('support@acme.io')).toBeVisible();
        expect(screen.getByTestId('connector-sync-error-detail')).toHaveTextContent(LONG_ERROR);
    });

    it('Retry sync fires onRetry with the connection id, then closes', async () => {
        const onClose = vi.fn();
        const onRetry = vi.fn();
        render(<SyncErrorModal vm={erroredVm()} onClose={onClose} onRetry={onRetry} />);
        await userEvent.click(screen.getByTestId('connector-sync-error-retry'));
        expect(onRetry).toHaveBeenCalledWith(11);
        expect(onClose).toHaveBeenCalled();
    });

    it('Dismiss closes without retrying', async () => {
        const onClose = vi.fn();
        const onRetry = vi.fn();
        render(<SyncErrorModal vm={erroredVm()} onClose={onClose} onRetry={onRetry} />);
        await userEvent.click(screen.getByTestId('connector-sync-error-dismiss'));
        expect(onClose).toHaveBeenCalled();
        expect(onRetry).not.toHaveBeenCalled();
    });

    it('closes on backdrop click but NOT on a click inside the dialog', async () => {
        const onClose = vi.fn();
        render(<SyncErrorModal vm={erroredVm()} onClose={onClose} onRetry={vi.fn()} />);

        await userEvent.click(screen.getByTestId('connector-sync-error-detail'));
        expect(onClose).not.toHaveBeenCalled();

        await userEvent.click(screen.getByTestId('connector-sync-error-backdrop'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('closes on Escape and focuses the close button on open (R15)', async () => {
        const onClose = vi.fn();
        render(<SyncErrorModal vm={erroredVm()} onClose={onClose} onRetry={vi.fn()} />);
        expect(screen.getByTestId('connector-sync-error-close')).toHaveFocus();
        await userEvent.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalled();
    });
});
