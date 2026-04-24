import { Suspense } from 'react';
import type { AdminChatVolumeRow } from '../admin.api';
import { ChartCard, ChartFallback, EmptyChart, type ChartState } from './ChartCard';
import { LazyAreaChartBody } from './LazyRecharts';

export interface ChatVolumeCardProps {
    data: AdminChatVolumeRow[];
    state: ChartState;
    days: number;
}

/**
 * Area chart of chat volume per day across the selected window. Uses
 * recharts — lazily loaded — for polish; empty window renders the
 * <EmptyChart /> SVG placeholder.
 */
export function ChatVolumeCard({ data, state, days }: ChatVolumeCardProps) {
    const hasData = data.length > 0 && data.some((row) => row.count > 0);
    const resolvedState: ChartState = state === 'ready' && !hasData ? 'empty' : state;

    return (
        <ChartCard
            slug="chat-volume"
            title="Chat volume"
            subtitle={`${days}-day window`}
            state={resolvedState}
        >
            {resolvedState === 'ready' ? (
                <Suspense fallback={<ChartFallback slug="chat-volume" />}>
                    <LazyAreaChartBody data={data} />
                </Suspense>
            ) : resolvedState === 'empty' ? (
                <EmptyChart slug="chat-volume" />
            ) : resolvedState === 'error' ? (
                <EmptyChart slug="chat-volume" message="Chat volume unavailable" />
            ) : (
                <ChartFallback slug="chat-volume" />
            )}
        </ChartCard>
    );
}
