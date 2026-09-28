import { api } from '../../lib/api';

export type LocalServiceState = 'running' | 'stopped' | 'degraded';
export type LocalServiceName = 'api' | 'mcp';
export type LocalIntegrationAction = 'start' | 'stop' | 'restart';

export interface LocalIntegrationEvent {
    id: number;
    at: string;
    method: string;
    path: string;
    status: number;
    duration_ms: number;
    company_key: string | null;
    exchange?: LocalMcpExchange;
}

export interface LocalMcpExchange {
    request: unknown;
    response: unknown;
    response_truncated: boolean;
}

export interface LocalIntegrationMetrics {
    service: LocalServiceName;
    started_at: string;
    uptime_seconds: number;
    totals: {
        requests: number;
        errors: number;
    };
    events: LocalIntegrationEvent[];
}

export interface LocalIntegrationServiceStatus {
    state: LocalServiceState;
    health: Record<string, unknown> | null;
    metrics: LocalIntegrationMetrics | null;
    error: string | null;
}

export interface LocalIntegrationStatus {
    local_only: true;
    fixture_gate_enabled: boolean;
    refreshed_at: string;
    api: LocalIntegrationServiceStatus;
    mcp: LocalIntegrationServiceStatus;
}

export const localIntegrationsApi = {
    async status(): Promise<LocalIntegrationStatus> {
        const { data } = await api.get<LocalIntegrationStatus>('/api/developer/local-integrations');
        return data;
    },

    async control(action: LocalIntegrationAction): Promise<{ message: string; status: LocalIntegrationStatus }> {
        const { data } = await api.post<{ message: string; status: LocalIntegrationStatus }>(
            `/api/developer/local-integrations/${action}`,
        );
        return data;
    },
};
