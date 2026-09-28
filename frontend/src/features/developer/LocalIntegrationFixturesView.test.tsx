import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
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
        state: 'running',
        health: { datasetRevision: 'fixture-v1' },
        error: null,
        metrics: {
            service: 'mcp', started_at: '2026-09-28T09:00:00+00:00', uptime_seconds: 600,
            totals: { requests: 6, errors: 0 },
            events: [{ id: 2, at: '2026-09-28T09:59:58+00:00', method: 'POST', path: '/mcp/passolibero-calzature', status: 200, duration_ms: 8, company_key: 'passolibero-calzature' }],
        },
    },
};

function renderView() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={client}><LocalIntegrationFixturesView /></QueryClientProvider>);
}

describe('LocalIntegrationFixturesView', () => {
    beforeEach(() => {
        vi.mocked(localIntegrationsApi.status).mockResolvedValue(runningStatus);
        vi.mocked(localIntegrationsApi.control).mockResolvedValue({ message: 'Done.', status: runningStatus });
    });

    it('shows both service states, the activity graph, and credential-free recent calls', async () => {
        renderView();

        await waitFor(() => expect(screen.getByTestId('local-integration-call-count')).toHaveTextContent('2 mostrate'));
        expect(screen.getByText('API statica')).toBeInTheDocument();
        expect(screen.getByText('MCP Streamable HTTP')).toBeInTheDocument();
        expect(screen.getByText('Chiamate negli ultimi 60 secondi')).toBeInTheDocument();
        const callsTable = screen.getByRole('table');
        expect(callsTable).toHaveTextContent('v1/companies/rotta-logistics/context');
        expect(callsTable).toHaveTextContent('mcp/passolibero-calzature');
        expect(screen.getByRole('button', { name: 'Avvia servizi' })).toBeDisabled();
    });

    it('restarts both local services through the guarded control endpoint', async () => {
        renderView();

        fireEvent.click(await screen.findByRole('button', { name: 'Riavvia' }));

        await waitFor(() => expect(localIntegrationsApi.control).toHaveBeenCalledWith('restart', expect.anything()));
    });
});
