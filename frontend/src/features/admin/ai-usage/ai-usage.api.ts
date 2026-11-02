import { api } from '../../../lib/api';

export type UsageMetrics = {
    cost: number; tokens: number; tokens_input: number; tokens_output: number;
    tokens_cached: number; tokens_reasoning: number; entries: number;
    unpriced_entries: number; estimated_entries: number;
    cost_per_million: number | null; average_latency_ms: number | null;
};
export type UsageBucket = UsageMetrics & { from: string; to: string };
export type UsageModel = UsageMetrics & { id: string; provider: string; model: string };
export type UsageSeries = { id: string; name: string; provider: string | null; other: boolean; values: UsageMetrics[] };
export type UsageFilters = {
    from: string; to: string; timezone: string; provider: string; purpose: string; status: string; currency: string;
};
export type UsageActivity = {
    ranking_metric: 'cost' | 'tokens';
    status: 'available' | 'unavailable'; reason: 'ledger_unavailable' | 'interval_too_broad' | null;
    from: string; to: string; timezone: string; currency: string; bucket_days: number; metering_enabled: boolean;
    summary: UsageMetrics | null; buckets: UsageBucket[]; series: UsageSeries[]; models: UsageModel[];
    top_models: UsageModel[]; top_providers: (UsageMetrics & { provider: string })[];
    options: { providers: string[]; purposes: string[]; statuses: string[]; currencies: string[] };
};
export const aiUsageApi = {
    async activity(filters: UsageFilters, signal?: AbortSignal): Promise<UsageActivity> {
        const { data } = await api.get<UsageActivity>('/api/admin/ai-usage', { params: filters, signal });
        return data;
    },
};
