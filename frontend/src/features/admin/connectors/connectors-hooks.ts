import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    adminConnectorsApi,
    type ConnectorEntry,
    type DisableResponse,
} from './connectors.api';

/*
 * TanStack Query hooks over the /api/admin/connectors/* endpoints.
 * Mirrors the conventions used by features/admin/users + tags:
 *   - single shared partition key `['admin','connectors']`
 *   - every mutation invalidates the list query so the card grid
 *     refetches after any state transition (install / sync / disable /
 *     destroy).
 *   - no optimistic updates: connector ops are low-frequency and the
 *     server is the source of truth for the status enum + last_sync_at.
 */

export const CONNECTORS_KEY = ['admin', 'connectors'] as const;

export function useConnectors() {
    return useQuery<ConnectorEntry[]>({
        queryKey: [...CONNECTORS_KEY, 'list'],
        queryFn: () => adminConnectorsApi.list(),
        // 30s — connector status (pending → active, sync timestamps,
        // error_json) doesn't change every few seconds. The post-action
        // invalidation handles user-driven refreshes.
        staleTime: 30_000,
    });
}

export function useStartInstall() {
    return useMutation({
        mutationFn: (key: string) => adminConnectorsApi.startInstall(key),
        // No invalidation: the BE creates a `pending` row and returns
        // the OAuth redirect. The caller navigates away, so a refetch
        // would race with the navigation.
    });
}

export function useSyncNow() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (installationId: number) => adminConnectorsApi.syncNow(installationId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: CONNECTORS_KEY });
        },
    });
}

export function useDisableConnector() {
    const qc = useQueryClient();
    return useMutation<DisableResponse['data'], unknown, number>({
        mutationFn: (installationId: number) => adminConnectorsApi.disable(installationId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: CONNECTORS_KEY });
        },
    });
}

export function useDestroyConnector() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (installationId: number) => adminConnectorsApi.destroy(installationId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: CONNECTORS_KEY });
        },
    });
}
