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
    planner_shadow: {
        reports: 20, agreement_rate: 85, agreements: 17, disagreements: 2, invalid_plan_rate: 15, errors: 1,
        fallbacks: 1, validation_corrections: 3, premature_insufficient_avoided: 4,
        average_candidates: 5.2, average_router_latency_ms: 120, average_planner_latency_ms: 310,
        average_tokens: 900,
    },
    mcp_transport: {
        executions: 9,
        physical_requests: 11,
        negotiation_cache_hit_rate: 88.9,
        average_oauth_refresh_ms: 2,
        average_endpoint_guard_dns_ms: 3,
        average_discovery_ms: 7,
        average_tool_call_ms: 61,
        average_decode_ms: 1,
        recoveries: { renegotiated: 1 },
        error_codes: { mcp_remote_error: 2 },
    },
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
        expect(screen.getByTestId('agent-planner-shadow')).toHaveTextContent('85% agreement');
        expect(screen.getByTestId('agent-planner-invalid')).toHaveTextContent('15%');
        expect(screen.getByTestId('agent-mcp-transport')).toHaveTextContent('88.9% discovery cache hit');
        expect(screen.getByTestId('agent-mcp-tool-call')).toHaveTextContent('61ms');
        expect(screen.getByTestId('agent-mcp-recoveries')).toHaveTextContent('2 errors');
    });

    it('offers retry when telemetry cannot be loaded', async () => {
        vi.spyOn(apiConnectorsApi, 'agentOverview').mockRejectedValue(new Error('offline'));
        renderOverview();

        await waitFor(() => expect(screen.getByTestId('agent-runtime-overview')).toHaveAttribute('data-state', 'error'));
        expect(screen.getByTestId('agent-runtime-retry')).toBeInTheDocument();
    });
});
