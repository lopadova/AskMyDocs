import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiConnectorsApi, type AgentRunOverview } from './api-connectors.api';
import { AgentRuntimeOverview } from './AgentRuntimeOverview';

afterEach(() => vi.restoreAllMocks());

const overview: AgentRunOverview = {
    window: { hours: 24, since: '2026-08-07T12:00:00Z' },
    metrics: {
        runs: 12, successful_runs: 10, failed_runs: 1, cancelled_runs: 1,
        success_rate: 83.3, logical_calls: 31, physical_requests: 74,
        tool_executions: 31, tool_failures: 2, average_duration_ms: 3400,
    },
    status_counts: { completed: 10, failed: 1, cancelled: 1 },
    policy: { logical_soft: 12, logical_hard: 25, physical_hard: 100 },
    recent_runs: [{
        run_id: 'run-1', project_key: 'orders', channel: 'chat', locale: 'it-IT',
        status: 'completed', error_code: null, logical_calls: 2, physical_calls: 6,
        tool_executions: 2, created_at: '2026-08-08T12:00:00Z', completed_at: '2026-08-08T12:00:03Z',
    }],
};

function renderOverview() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={client}><AgentRuntimeOverview /></QueryClientProvider>);
}

describe('AgentRuntimeOverview', () => {
    it('renders effective limits and PII-free operational metrics', async () => {
        vi.spyOn(apiConnectorsApi, 'agentOverview').mockResolvedValue(overview);
        renderOverview();

        await waitFor(() => expect(screen.getByTestId('agent-runtime-overview')).toHaveAttribute('data-state', 'ready'));
        expect(screen.getByTestId('agent-runtime-runs')).toHaveTextContent('12');
        expect(screen.getByTestId('agent-runtime-calls')).toHaveTextContent('31');
        expect(screen.getByTestId('agent-runtime-calls')).toHaveTextContent('74 HTTP');
        expect(screen.getByTestId('agent-runtime-policy-summary')).toHaveTextContent('hard 25 logical · 100 HTTP');
        expect(screen.getByTestId('agent-runtime-recent')).toHaveTextContent('orders');
    });

    it('offers retry when telemetry cannot be loaded', async () => {
        vi.spyOn(apiConnectorsApi, 'agentOverview').mockRejectedValue(new Error('offline'));
        renderOverview();

        await waitFor(() => expect(screen.getByTestId('agent-runtime-overview')).toHaveAttribute('data-state', 'error'));
        expect(screen.getByTestId('agent-runtime-retry')).toBeInTheDocument();
    });
});
