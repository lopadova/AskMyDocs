import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { ConnectorInstallationActions } from './ConnectorInstallationActions';
import { connectorActionsApi, type ConnectorActionStatus, type ConnectorInstallationDto } from './connectors.api';

vi.mock('./connectors.api', () => ({ connectorActionsApi: { status: vi.fn(), start: vi.fn() } }));

const account: ConnectorInstallationDto = {
    id: 7, label: 'Support', project_key: 'support', status: 'active', last_sync_at: null, error: null,
    folders: { include: [] }, date_window_days: 45,
    actions: [{ key: 'historical-import', label: 'Prendi tutto', description: 'Mantiene il periodo configurato.', enabled: true }],
};
const running: ConnectorActionStatus = { id: 12, mode: 'history', status: 'running', counts: { documents_added: 2 }, error: null, phase: 'tickets' };
function mount(value = account) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(<QueryClientProvider client={client}><ConnectorInstallationActions account={value} /></QueryClientProvider>);
}
beforeEach(() => vi.clearAllMocks());

describe('connector installation actions', () => {
    it('starts with the keyboard, blocks repeated clicks and displays progress without changing the window', async () => {
        vi.mocked(connectorActionsApi.status).mockResolvedValue(null);
        let finish!: (value: ConnectorActionStatus) => void;
        vi.mocked(connectorActionsApi.start).mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
        mount();
        const button = screen.getByRole('button', { name: 'Prendi tutto' });
        await waitFor(() => expect(button).toBeEnabled());
        expect(button.querySelector('svg')).not.toBeNull();
        await userEvent.tab();
        expect(button).toHaveFocus();
        await userEvent.keyboard('{Enter}');
        await waitFor(() => expect(button).toBeDisabled());
        expect(button).toHaveAttribute('aria-busy', 'true');
        await userEvent.click(button);
        expect(connectorActionsApi.start).toHaveBeenCalledTimes(1);
        expect(connectorActionsApi.start).toHaveBeenCalledWith(7, 'historical-import');
        finish(running);
        await screen.findByText('Importazione in corso');
        expect(screen.getByRole('status')).toHaveTextContent('2 nuovi');
        expect(account.date_window_days).toBe(45);
    });

    it('allows resuming a failed run and exposes the error', async () => {
        vi.mocked(connectorActionsApi.status).mockResolvedValue({ ...running, status: 'failed', error: 'Freshdesk rate limit.' });
        vi.mocked(connectorActionsApi.start).mockResolvedValue({ ...running, status: 'queued' });
        mount();
        await screen.findByText('Importazione interrotta: premi per riprendere');
        expect(screen.getByRole('alert')).toHaveTextContent('Freshdesk rate limit.');
        await userEvent.click(screen.getByRole('button', { name: 'Prendi tutto' }));
        await screen.findByText('In coda');
    });

    it('does not fetch status for a disabled installation', () => {
        mount({ ...account, actions: [{ ...account.actions![0], enabled: false }] });
        expect(screen.getByRole('button', { name: 'Prendi tutto' })).toBeDisabled();
        expect(connectorActionsApi.status).not.toHaveBeenCalled();
    });
});


it('shows a paused import and lets an enabled connection resume it', async () => {
    vi.mocked(connectorActionsApi.status).mockResolvedValue({ ...running, status: 'paused' });
    vi.mocked(connectorActionsApi.start).mockResolvedValue({ ...running, status: 'queued' });
    mount();
    await screen.findByText('Importazione sospesa: riattiva l’ingest nelle impostazioni e premi per riprendere');
    await userEvent.click(screen.getByRole('button', { name: 'Prendi tutto' }));
    await screen.findByText('In coda');
    expect(connectorActionsApi.start).toHaveBeenCalledWith(7, 'historical-import');
});
