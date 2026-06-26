import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TestFetchResultModal } from './TestFetchResultModal';
import type { ConnectorInstallationDto, TestFetchResponse } from './connectors.api';

/*
 * Read-only test-fetch result modal. R16: each "renders X" test inspects the
 * branch it claims (message vs empty), and the close test actually clicks Close.
 */

const account: ConnectorInstallationDto = {
    id: 7,
    label: 'prometeo-1',
    project_key: null,
    status: 'active',
    last_sync_at: null,
    error: null,
    folders: { include: [] },
    date_window_days: null,
};

function messageResult(): TestFetchResponse['data'] {
    return {
        folder: 'INBOX',
        message: {
            uid: 4211,
            subject: 'Allarme rilevatore zona 3',
            from_name: 'Centrale Prometeo',
            from_email: 'noreply@prometeo.test',
            date: '2026-06-24T08:15:00+00:00',
            to_count: 2,
            has_attachments: true,
            attachments_count: 1,
            snippet: 'Il rilevatore della zona 3 ha segnalato un evento alle 08:14…',
        },
    };
}

describe('TestFetchResultModal', () => {
    it('renders the message preview branch with subject + from + snippet', () => {
        render(<TestFetchResultModal account={account} result={messageResult()} onClose={vi.fn()} />);

        const dialog = screen.getByTestId('connector-test-fetch-result');
        expect(dialog).toHaveAttribute('data-result-state', 'message');
        expect(screen.getByTestId('connector-test-fetch-folder')).toHaveTextContent('INBOX');
        expect(screen.getByTestId('connector-test-fetch-subject')).toHaveTextContent('Allarme rilevatore zona 3');
        expect(screen.getByTestId('connector-test-fetch-from')).toHaveTextContent(
            'Centrale Prometeo <noreply@prometeo.test>',
        );
        expect(screen.getByTestId('connector-test-fetch-meta')).toHaveTextContent('2 recipients · 1 attachment');
        expect(screen.getByTestId('connector-test-fetch-snippet')).toHaveTextContent('zona 3');
        // No empty-state node in the message branch.
        expect(screen.queryByTestId('connector-test-fetch-empty')).toBeNull();
    });

    it('renders the empty-folder branch when message is null', () => {
        render(
            <TestFetchResultModal
                account={account}
                result={{ folder: 'INBOX', message: null }}
                onClose={vi.fn()}
            />,
        );
        const dialog = screen.getByTestId('connector-test-fetch-result');
        expect(dialog).toHaveAttribute('data-result-state', 'empty');
        expect(screen.getByTestId('connector-test-fetch-empty')).toBeVisible();
        expect(screen.queryByTestId('connector-test-fetch-message')).toBeNull();
    });

    it('fires onClose when the Close button is clicked', async () => {
        const onClose = vi.fn();
        render(<TestFetchResultModal account={account} result={messageResult()} onClose={onClose} />);
        await userEvent.click(screen.getByTestId('connector-test-fetch-close'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
