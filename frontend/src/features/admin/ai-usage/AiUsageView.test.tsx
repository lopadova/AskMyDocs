import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AiUsageView } from './AiUsageView';
import { aiUsageApi, type UsageActivity, type UsageMetrics } from './ai-usage.api';

vi.mock('./ai-usage.api', () => ({ aiUsageApi: { activity: vi.fn() } }));
vi.mock('../../../lib/ui-locale', () => ({ useUiTranslation: () => ({ t: (s: string) => s, locale: 'en' }) }));
vi.mock('./ActivityCharts', () => ({ default: ({ title, points }: { title: string; points: unknown[] }) => <div aria-label={title}>{points.length} buckets</div> }));

const metrics: UsageMetrics = { cost: .003, spend: .003, estimated_cost: 0, repriced_entries: 0, unresolved_entries: 0, tokens: 150, tokens_input: 100, tokens_output: 50,
    tokens_cached: 30, tokens_reasoning: 20, entries: 2, unpriced_entries: 0, estimated_entries: 0,
    cost_per_million: 20, average_latency_ms: null };
const report = (): UsageActivity => ({ price_sources: {}, ranking_metric: 'cost', status: 'available', reason: null, from: '2026-11-01', to: '2026-11-02', timezone: 'Europe/Rome', currency: 'USD', bucket_days: 1, metering_enabled: true,
    summary: { ...metrics }, buckets: [{ ...metrics, from: '2026-11-01', to: '2026-11-01' }, { ...metrics, from: '2026-11-02', to: '2026-11-02' }],
    series: [{ id: 'model-a', name: 'Model Alpha', provider: 'example', other: false, values: [metrics, metrics] }],
    models: [{ ...metrics, id: 'model-a', provider: 'example', model: 'Model Alpha' }, { ...metrics, id: 'model-b', provider: 'second', model: 'Model Beta' }],
    top_models: [{ ...metrics, id: 'model-a', provider: 'example', model: 'Model Alpha' }], top_providers: [{ ...metrics, provider: 'example' }],
    options: { providers: ['example', 'second'], purposes: ['chat', 'embeddings'], statuses: ['recorded'], currencies: ['USD', 'EUR'] } });

function mount() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={client}><AiUsageView /></QueryClientProvider>);
    return client;
}

describe('tenant AI usage', () => {
    beforeEach(() => { vi.clearAllMocks(); vi.mocked(aiUsageApi.activity).mockResolvedValue(report()); });

    it('shows real ledger metrics and unavailable latency without inventing a duration', async () => {
        mount(); await screen.findByText('Recorded spend');
        expect(screen.getByTestId('admin-ai-usage')).toHaveAttribute('data-state', 'ready');
        expect(screen.getByText('Not available')).toBeInTheDocument();
        expect(screen.queryByText('Requests')).not.toBeInTheDocument();
        await screen.findByLabelText('Cost by model');
        expect(screen.getByLabelText('Token composition')).toBeInTheDocument();
    });

    it('applies global filters and the period to one shared dataset', async () => {
        mount(); await screen.findByText('Recorded spend');
        fireEvent.change(screen.getByLabelText('Provider', { exact: true }), { target: { value: 'second' } });
        await waitFor(() => expect(aiUsageApi.activity).toHaveBeenLastCalledWith(expect.objectContaining({ provider: 'second' }), expect.any(AbortSignal)));
        fireEvent.change(screen.getByLabelText('Quick period'), { target: { value: '7' } });
        await waitFor(() => expect(aiUsageApi.activity).toHaveBeenCalledTimes(3));
        expect(vi.mocked(aiUsageApi.activity).mock.calls[2][0].provider).toBe('second');
    });

    it('keeps model search and pagination separate from global KPI totals', async () => {
        const client = mount(); await screen.findByText('Recorded spend');
        fireEvent.click(screen.getByRole('tab', { name: 'Models' }));
        fireEvent.change(screen.getByLabelText('Search models'), { target: { value: 'Beta' } });
        expect(screen.getByText('Model Beta')).toBeInTheDocument();
        expect(screen.queryByText('Model Alpha')).not.toBeInTheDocument();
        expect(aiUsageApi.activity).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Total tokens')).toBeInTheDocument();
        act(() => client.clear());
    });

    it('distinguishes an empty available period from unavailable accounting', async () => {
        vi.mocked(aiUsageApi.activity).mockResolvedValue({ ...report(), status: 'unavailable', summary: null });
        mount(); await screen.findByText('The usage ledger is unavailable. Costs and tokens cannot be calculated right now.');
        expect(screen.queryByText('Recorded spend')).not.toBeInTheDocument();
    });

    it('shows an explicit zero-usage period with no fabricated rankings', async () => {
        vi.mocked(aiUsageApi.activity).mockResolvedValue({ ...report(), summary: { ...metrics, entries: 0, tokens: 0, cost: 0, spend: 0 }, top_models: [], top_providers: [] });
        mount(); await screen.findByText('No recorded usage matches this period and these filters.');
        expect(screen.getByTestId('admin-ai-usage')).toHaveAttribute('data-state', 'empty');
        expect(screen.queryByText('Top models')).not.toBeInTheDocument();
    });

    it('warns about unpriced rows and disabled metering without calling them free', async () => {
        vi.mocked(aiUsageApi.activity).mockResolvedValue({ ...report(), metering_enabled: false, summary: { ...metrics, unpriced_entries: 1, unresolved_entries: 1, cost_per_million: null } });
        mount(); await screen.findByText('Usage metering is disabled. These are historical records; new activity may be missing.');
        expect(screen.getByText(/entries have no verifiable price\. Recorded spend is partial/)).toBeInTheDocument();
    });

    it('shows the fetch error with a usable retry action', async () => {
        vi.mocked(aiUsageApi.activity).mockRejectedValueOnce(new Error('offline'));
        mount(); await screen.findByRole('alert');
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        await screen.findByText('Recorded spend');
    });

    it('uses real token charts when all prices are unknown instead of charting free spend', async () => {
        vi.mocked(aiUsageApi.activity).mockResolvedValue({ ...report(), ranking_metric: 'tokens', summary: { ...metrics, unpriced_entries: metrics.entries, unresolved_entries: metrics.entries, spend: null, cost_per_million: null } });
        mount(); await screen.findByLabelText('Tokens by model');
        expect(screen.getByLabelText('Entries over time')).toBeInTheDocument();
        expect(screen.queryByLabelText('Cost by model')).not.toBeInTheDocument();
        expect(screen.getAllByText('Not available')).toHaveLength(3);
    });

    it('supports keyboard navigation between the actual tabs', async () => {
        mount(); await screen.findByText('Recorded spend');
        fireEvent.keyDown(screen.getByRole('tab', { name: 'Overview' }), { key: 'ArrowRight' });
        expect(screen.getByRole('tab', { name: 'Models' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tab', { name: 'Models' })).toHaveFocus();
    });

    it('shows historical cost estimates as estimates with catalog provenance', async () => {
        vi.mocked(aiUsageApi.activity).mockResolvedValue({ ...report(), price_sources: { openrouter: '2026-11-02T10:00:00Z' },
            summary: { ...metrics, cost: 0, spend: 12.34, estimated_cost: 12.34, repriced_entries: 2, unpriced_entries: 2 } });
        mount(); await screen.findByText('Estimated spend');
        expect(screen.getByText('$12.34')).toBeInTheDocument();
        expect(screen.getByText(/historical entries are estimated at current catalog prices/)).toBeInTheDocument();
        expect(screen.getByText(/Price catalogs:/)).toHaveTextContent('openrouter');
        await screen.findByLabelText('Estimated cost by model');
        expect(screen.getByLabelText('Estimated spend over time')).toBeInTheDocument();
    });
});
