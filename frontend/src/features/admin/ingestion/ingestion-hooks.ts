import { useQuery } from '@tanstack/react-query';
import { adminIngestionApi, type QueueDepth, type SyncRunDto } from './ingestion.api';

/*
 * TanStack Query hooks over /api/admin/ingestion/* + the per-account
 * sync-runs endpoint. Queue depth polls (operators watch backlog drain);
 * sync runs refetch on selection + a slower poll.
 */

export const INGESTION_KEY = ['admin', 'ingestion'] as const;

export function useQueueDepths() {
    return useQuery<QueueDepth[]>({
        queryKey: [...INGESTION_KEY, 'queue'],
        queryFn: () => adminIngestionApi.queueDepths(),
        // Backlog changes second-to-second as workers drain it.
        refetchInterval: 10_000,
        staleTime: 5_000,
    });
}

export function useSyncRuns(installationId: number | null) {
    return useQuery<SyncRunDto[]>({
        queryKey: [...INGESTION_KEY, 'sync-runs', installationId],
        queryFn: () => adminIngestionApi.syncRuns(installationId as number),
        enabled: installationId !== null,
        refetchInterval: 15_000,
        staleTime: 5_000,
    });
}
