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
        // Fail fast on a polled query — surface the error immediately and let
        // the next poll / manual retry recover, rather than fanning 3 retries
        // out of every 10s tick during an outage.
        retry: false,
    });
}

export function useSyncRuns(installationId: number | null) {
    return useQuery<SyncRunDto[]>({
        queryKey: [...INGESTION_KEY, 'sync-runs', installationId],
        queryFn: () => {
            // Runtime guard (not a cast): if `enabled` gating ever regresses,
            // fail loudly instead of requesting `/sync-runs` for a null id.
            if (installationId === null) {
                throw new Error('useSyncRuns invoked without an installationId');
            }
            return adminIngestionApi.syncRuns(installationId);
        },
        enabled: installationId !== null,
        refetchInterval: 15_000,
        staleTime: 5_000,
        retry: false,
    });
}
