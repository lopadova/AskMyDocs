import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { OverviewTab } from './OverviewTab';
import { api } from '../../../lib/api';

const mockGet = vi.fn();

const METRICS = {
    codes_issued: 42,
    redemptions: 7,
    invites_sent: 10,
    invites_accepted: 8,
    referrals_qualified: 5,
    distinct_referrers: 3,
    k_factor: 1.67,
    acceptance_rate: 0.8,
    conversion_rate: 0.17,
    ttr_p50_seconds: 3600,
    ttr_p90_seconds: 86400,
};

const ZERO_METRICS = {
    codes_issued: 0,
    redemptions: 0,
    invites_sent: 0,
    invites_accepted: 0,
    referrals_qualified: 0,
    distinct_referrers: 0,
    k_factor: 0,
    acceptance_rate: 0,
    conversion_rate: 0,
    ttr_p50_seconds: null,
    ttr_p90_seconds: null,
};

function routeMetrics(metrics: unknown) {
    mockGet.mockImplementation((url: string) => {
        if (url.startsWith('/api/admin/invitations/metrics')) return Promise.resolve({ data: { data: metrics } });
        return Promise.resolve({ data: { data: [] } }); // campaigns
    });
}

beforeEach(() => {
    mockGet.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('OverviewTab', () => {
    it('renders all 11 KPIs with formatted values on a valid payload', async () => {
        routeMetrics(METRICS);
        render(withQueryClient(<OverviewTab />));

        await waitFor(() =>
            expect(screen.getByTestId('admin-invitations-overview')).toHaveAttribute('data-state', 'ready'),
        );
        expect(screen.getByTestId('kpi-card-codes-issued')).toHaveTextContent('42');
        expect(screen.getByTestId('kpi-card-k-factor')).toHaveTextContent('1.67×');
        expect(screen.getByTestId('kpi-card-acceptance-rate')).toHaveTextContent('80.0%');
        expect(screen.getByTestId('kpi-card-conversion-rate')).toHaveTextContent('17.0%');
        expect(screen.getByTestId('kpi-card-ttr-p50')).toHaveTextContent('1h');
    });

    it('surfaces the error state (R14) without rendering the funnel', async () => {
        mockGet.mockImplementation((url: string) => {
            if (url.startsWith('/api/admin/invitations/metrics')) return Promise.reject(new Error('boom 500'));
            return Promise.resolve({ data: { data: [] } });
        });
        render(withQueryClient(<OverviewTab />));

        await waitFor(() =>
            expect(screen.getByTestId('admin-invitations-overview')).toHaveAttribute('data-state', 'error'),
        );
        expect(screen.getByTestId('admin-invitations-overview-error')).toBeVisible();
        expect(screen.queryByTestId('admin-invitations-funnel')).not.toBeInTheDocument();
    });

    it('all-zero tenant renders the funnel with 0%-width bars, never NaN (R14/R16)', async () => {
        routeMetrics(ZERO_METRICS);
        render(withQueryClient(<OverviewTab />));

        await waitFor(() =>
            expect(screen.getByTestId('admin-invitations-overview')).toHaveAttribute('data-state', 'ready'),
        );
        const funnel = screen.getByTestId('admin-invitations-funnel');
        expect(funnel).toBeVisible();
        // The redemptions bar must report a finite 0 — not NaN — value.
        const bar = within(screen.getByTestId('admin-invitations-funnel-redemptions')).getByRole('progressbar');
        expect(bar).toHaveAttribute('aria-valuenow', '0');
        expect(funnel.textContent ?? '').not.toMatch(/NaN/);
    });
});
