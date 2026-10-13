import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { LocalIntegrationFixturesView } from './LocalIntegrationFixturesView';
import { localIntegrationsApi, type LocalIntegrationStatus } from './local-integrations.api';

vi.mock('./local-integrations.api', () => ({
    localIntegrationsApi: {
        status: vi.fn(),
        control: vi.fn(),
    },
}));

const runningStatus: LocalIntegrationStatus = {
    local_only: true,
    fixture_gate_enabled: true,
    refreshed_at: '2026-09-28T10:00:00+00:00',
    api: {
        address: '127.0.0.1:14310',
        state: 'running',
        health: { datasetRevision: 'fixture-v1' },
        error: null,
        metrics: {
            service: 'api', started_at: '2026-09-28T09:00:00+00:00', uptime_seconds: 600,
            totals: { requests: 4, errors: 1 },
            events: [{ id: 1, at: '2026-09-28T09:59:55+00:00', method: 'GET', path: '/v1/companies/rotta-logistics/context', status: 200, duration_ms: 12, company_key: 'rotta-logistics' }],
        },
    },
    mcp: {
        address: '127.0.0.1:14311',
        state: 'running',
        health: { datasetRevision: 'fixture-v1' },
        error: null,
        metrics: {
            service: 'mcp', started_at: '2026-09-28T09:00:00+00:00', uptime_seconds: 600,
            totals: { requests: 6, errors: 0 },
            events: [{
                id: 2,
                at: '2026-09-28T09:59:58+00:00',
                method: 'POST',
                path: '/mcp/passolibero-calzature',
                status: 200,
                duration_ms: 8,
                company_key: 'passolibero-calzature',
                exchange: {
                    request: { jsonrpc: '2.0', id: 8, method: 'tools/call', params: { name: 'get_operational_record', arguments: { record_id: 'purchase-order-FRN-2024-241' } } },
                    response: { jsonrpc: '2.0', id: 8, result: { structuredContent: { record: { title: 'Riassortimento modello Brezza' } } } },
                    response_truncated: false,
                },
            }],
        },
    },
};

const stoppedService = { state: 'stopped' as const, health: null, error: null, metrics: null };
const stoppedStatus: LocalIntegrationStatus = {
    ...runningStatus,
    api: { ...stoppedService, address: runningStatus.api.address },
    mcp: { ...stoppedService, address: runningStatus.mcp.address },
};

function renderView() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={client}><LocalIntegrationFixturesView /></QueryClientProvider>);
}

describe('LocalIntegrationFixturesView', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        vi.mocked(localIntegrationsApi.status).mockResolvedValue(runningStatus);
        vi.mocked(localIntegrationsApi.control).mockResolvedValue({ message: 'Done.', status: runningStatus });
    });

    it('shows both service states, live activity, and the latest MCP request/response', async () => {
        renderView();

        await waitFor(() => expect(screen.getByTestId('local-integration-call-count')).toHaveTextContent('2 mostrate'));
        expect(screen.getByRole('region', { name: 'Server mock' })).toBeInTheDocument();
        expect(screen.getByText('Mock API')).toBeInTheDocument();
        expect(screen.getByText('Server MCP')).toBeInTheDocument();
        expect(screen.getByText('127.0.0.1:14310')).toBeInTheDocument();
        expect(screen.getByText('127.0.0.1:14311')).toBeInTheDocument();
        expect(screen.getByText('Chiamate negli ultimi 60 secondi')).toBeInTheDocument();
        const callsTable = screen.getByRole('table');
        expect(callsTable).toHaveTextContent('v1/companies/rotta-logistics/context');
        expect(callsTable).toHaveTextContent('mcp/passolibero-calzature');
        expect(screen.getByTestId('local-integration-mcp-request-2')).toHaveTextContent('get_operational_record');
        expect(screen.getByTestId('local-integration-mcp-response-2')).toHaveTextContent('Riassortimento modello Brezza');
        expect(screen.getByRole('button', { name: 'Avvia server' })).toBeDisabled();
    });

    it('restarts both local services through the guarded control endpoint', async () => {
        renderView();

        const restart = screen.getByRole('button', { name: 'Riavvia' });
        await waitFor(() => expect(restart).toBeEnabled());
        fireEvent.click(restart);

        await waitFor(() => expect(localIntegrationsApi.control).toHaveBeenCalledWith('restart', expect.anything()));
    });

    it('starts and stops both mocks and updates their states and available actions', async () => {
        vi.mocked(localIntegrationsApi.status).mockResolvedValue(stoppedStatus);
        vi.mocked(localIntegrationsApi.control)
            .mockResolvedValueOnce({ message: 'Avviati.', status: runningStatus })
            .mockResolvedValueOnce({ message: 'Arrestati.', status: stoppedStatus });
        renderView();

        const start = screen.getByRole('button', { name: 'Avvia server' });
        const stop = screen.getByRole('button', { name: 'Arresta server' });
        await waitFor(() => expect(start).toBeEnabled());
        expect(stop).toBeDisabled();
        fireEvent.click(start);

        await waitFor(() => expect(within(screen.getByTestId('local-integration-api-status')).getByText('Attivo')).toBeInTheDocument());
        expect(within(screen.getByTestId('local-integration-mcp-status')).getByText('Attivo')).toBeInTheDocument();
        expect(start).toBeDisabled();
        expect(stop).toBeEnabled();
        expect(localIntegrationsApi.control).toHaveBeenCalledWith('start', expect.anything());
        fireEvent.click(stop);

        await waitFor(() => expect(within(screen.getByTestId('local-integration-api-status')).getByText('Fermo')).toBeInTheDocument());
        expect(within(screen.getByTestId('local-integration-mcp-status')).getByText('Fermo')).toBeInTheDocument();
        expect(start).toBeEnabled();
        expect(stop).toBeDisabled();
        expect(localIntegrationsApi.control).toHaveBeenCalledWith('stop', expect.anything());
        expect(screen.getByText('Arrestati.')).toBeInTheDocument();
        expect(screen.queryByTestId('toast-host')).not.toBeInTheDocument();
    });

    it('keeps the initial state unknown and prevents actions until the first status arrives', () => {
        vi.mocked(localIntegrationsApi.status).mockReturnValue(new Promise(() => {}));
        renderView();

        expect(screen.getAllByText('In verifica')).toHaveLength(2);
        expect(screen.queryByText('Fermo')).not.toBeInTheDocument();
        for (const name of ['Avvia server', 'Arresta server', 'Riavvia']) {
            expect(screen.getByRole('button', { name })).toBeDisabled();
        }
    });

    it('announces a pending action and blocks overlapping commands', async () => {
        vi.mocked(localIntegrationsApi.status).mockResolvedValue(stoppedStatus);
        vi.mocked(localIntegrationsApi.control).mockReturnValue(new Promise(() => {}));
        renderView();
        const start = screen.getByRole('button', { name: 'Avvia server' });
        await waitFor(() => expect(start).toBeEnabled());
        fireEvent.click(start);

        expect(await screen.findByText('Avvio dei server in corso…')).toBeInTheDocument();
        expect(start).toHaveAttribute('aria-busy', 'true');
        for (const name of ['Avvia server', 'Arresta server', 'Riavvia', 'Aggiorna lo stato dei servizi locali']) {
            expect(screen.getByRole('button', { name })).toBeDisabled();
        }
    });

    it('shows unavailable states after a failed status request and allows a manual retry', async () => {
        vi.mocked(localIntegrationsApi.status).mockRejectedValueOnce(new Error('Unavailable'));
        renderView();

        expect(await screen.findAllByText('Non disponibile')).toHaveLength(2);
        expect(screen.getByRole('button', { name: 'Avvia server' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Arresta server' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Aggiorna lo stato dei servizi locali' }));

        await waitFor(() => expect(within(screen.getByTestId('local-integration-api-status')).getByText('Attivo')).toBeInTheDocument());
        expect(screen.getByRole('button', { name: 'Arresta server' })).toBeEnabled();
    });

    it('keeps a failed command visible with its cause and lets the operator retry', async () => {
        vi.mocked(localIntegrationsApi.status).mockResolvedValue(stoppedStatus);
        vi.mocked(localIntegrationsApi.control).mockRejectedValueOnce(new Error('La porta è già occupata.'));
        renderView();
        const start = screen.getByRole('button', { name: 'Avvia server' });
        await waitFor(() => expect(start).toBeEnabled());
        fireEvent.click(start);

        expect(await screen.findByText('Comando non riuscito.')).toBeInTheDocument();
        expect(screen.getAllByText('La porta è già occupata.')).toHaveLength(1);
        await waitFor(() => expect(start).toBeEnabled());
        fireEvent.click(start);

        await waitFor(() => expect(within(screen.getByTestId('local-integration-api-status')).getByText('Attivo')).toBeInTheDocument());
        expect(screen.queryByText('Comando non riuscito.')).not.toBeInTheDocument();
    });

    it('shows one precise inline error after repeated failed commands without overlay toasts', async () => {
        const message = 'Impossibile avviare api: la porta è già occupata.';
        vi.mocked(localIntegrationsApi.status).mockResolvedValue(stoppedStatus);
        vi.mocked(localIntegrationsApi.control).mockRejectedValue({
            isAxiosError: true,
            response: { status: 422, data: { message, code: 'local_integration_control_failed' } },
        });
        renderView();
        const start = screen.getByRole('button', { name: 'Avvia server' });

        for (let attempt = 1; attempt <= 4; attempt += 1) {
            await waitFor(() => expect(start).toBeEnabled());
            fireEvent.click(start);
            await waitFor(() => expect(localIntegrationsApi.control).toHaveBeenCalledTimes(attempt));
            await waitFor(() => expect(start).toBeEnabled());
            expect(screen.getAllByRole('alert')).toHaveLength(1);
            expect(screen.getAllByText(message)).toHaveLength(1);
        }
        expect(screen.queryByTestId('toast-host')).not.toBeInTheDocument();
    });
});
