import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { KpiStrip } from './KpiStrip';

describe('KpiStrip', () => {
    it('renders all six KPI cards with data and reports data-state=ready', () => {
        render(
            <KpiStrip
                state="ready"
                overview={{
                    total_docs: 12,
                    total_chunks: 345,
                    total_chats: 6_789,
                    avg_latency_ms: 1200,
                    failed_jobs: 0,
                    pending_jobs: 2,
                    cache_hit_rate: 67.4,
                    canonical_coverage_pct: 40.5,
                    storage_used_mb: 12.3,
                }}
            />,
        );

        expect(screen.getByTestId('kpi-strip')).toHaveAttribute('data-state', 'ready');
        for (const slug of ['docs', 'chunks', 'chats', 'latency', 'cache', 'coverage']) {
            expect(screen.getByTestId(`kpi-card-${slug}`)).toHaveAttribute('data-state', 'ready');
        }
        expect(screen.getByTestId('kpi-card-docs')).toHaveTextContent('12');
        expect(screen.getByTestId('kpi-card-chunks')).toHaveTextContent('345');
        expect(screen.getByTestId('kpi-card-chats')).toHaveTextContent('6.8k');
        expect(screen.getByTestId('kpi-card-latency')).toHaveTextContent('1.20s');
        expect(screen.getByTestId('kpi-card-cache')).toHaveTextContent('67.4%');
    });

    it('propagates data-state=loading when no overview is available yet', () => {
        render(<KpiStrip state="loading" overview={null} />);

        expect(screen.getByTestId('kpi-strip')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('kpi-card-docs')).toHaveAttribute('data-state', 'loading');
    });
});
