import { Suspense } from 'react';
import type { AdminTokenBurnRow } from '../admin.api';
import { ChartCard, ChartFallback, EmptyChart, type ChartState } from './ChartCard';
import { LazyBarChartBody } from './LazyRecharts';

export interface TokenBurnCardProps {
    data: AdminTokenBurnRow[];
    state: ChartState;
    days: number;
}

/**
 * Stacked bar of prompt + completion tokens per provider. The backend
 * sorts alphabetically by provider so colour assignment is stable
 * across reloads.
 */
export function TokenBurnCard({ data, state, days }: TokenBurnCardProps) {
    const hasData = data.length > 0 && data.some((row) => row.total_tokens > 0);
    const resolvedState: ChartState = state === 'ready' && !hasData ? 'empty' : state;

    return (
        <ChartCard
            slug="token-burn"
            title="Token burn"
            subtitle={`${days}-day window`}
            state={resolvedState}
        >
            {resolvedState === 'ready' ? (
                <Suspense fallback={<ChartFallback slug="token-burn" />}>
                    <LazyBarChartBody data={data} />
                </Suspense>
            ) : resolvedState === 'empty' ? (
                <EmptyChart slug="token-burn" />
            ) : resolvedState === 'error' ? (
                <EmptyChart slug="token-burn" message="Token burn unavailable" />
            ) : (
                <ChartFallback slug="token-burn" />
            )}
        </ChartCard>
    );
}
