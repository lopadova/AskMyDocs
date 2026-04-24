import { Suspense } from 'react';
import type { AdminRatingDistribution } from '../admin.api';
import { ChartCard, ChartFallback, EmptyChart, type ChartState } from './ChartCard';
import { LazyDonutBody } from './LazyRecharts';

export interface RatingDonutCardProps {
    distribution: AdminRatingDistribution | null;
    state: ChartState;
    days: number;
}

export function RatingDonutCard({ distribution, state, days }: RatingDonutCardProps) {
    const hasData = distribution !== null && distribution.total > 0;
    const resolvedState: ChartState = state === 'ready' && !hasData ? 'empty' : state;

    return (
        <ChartCard
            slug="rating"
            title="Rating distribution"
            subtitle={`${days}-day window`}
            state={resolvedState}
        >
            {resolvedState === 'ready' && distribution ? (
                <Suspense fallback={<ChartFallback slug="rating" />}>
                    <LazyDonutBody distribution={distribution} />
                </Suspense>
            ) : resolvedState === 'empty' ? (
                <EmptyChart slug="rating" />
            ) : resolvedState === 'error' ? (
                <EmptyChart slug="rating" message="Ratings unavailable" />
            ) : (
                <ChartFallback slug="rating" />
            )}
        </ChartCard>
    );
}
