import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminProjectsApi, type AdminProject } from '../projects/admin-projects.api';
import {
    adminConnectorsApi,
    type ConfigureConnectorPayload,
    type ConfigureResponse,
    type ConnectorEntry,
    type ConnectorInstallationDto,
    type DisableResponse,
    type StartInstallParams,
    type UpdateInstallationParams,
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
        mutationFn: (params: StartInstallParams) => adminConnectorsApi.startInstall(params),
        // No invalidation: the BE creates a `pending` row and returns
        // the OAuth redirect. The caller navigates away, so a refetch
        // would race with the navigation.
    });
}

/**
 * v8.20 — real project registry for the account binding dropdown (R18: options
 * derive from the DB, never a hard-coded subset). Tenant-scoped server-side.
 */
export function useProjectOptions() {
    return useQuery<AdminProject[]>({
        // Share the SAME cache key as the projects admin surface (ProjectsList)
        // so the dropdown refreshes when a project is created/renamed/deleted
        // there — and so the two don't duplicate-fetch the same list.
        queryKey: ['admin-projects'],
        queryFn: () => adminProjectsApi.list(),
        staleTime: 60_000,
    });
}

/**
 * v8.20 — PATCH metadata edit (rename label / rebind project) of an existing
 * account. Invalidates the list so the cards reflect the change.
 */
export function useUpdateInstallation() {
    const qc = useQueryClient();
    return useMutation<ConnectorInstallationDto, unknown, UpdateInstallationParams>({
        mutationFn: (params) => adminConnectorsApi.update(params),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: CONNECTORS_KEY });
        },
    });
}

/**
 * v8.24 — live folder list for an IMAP installation, fetched when the
 * connection-settings picker opens. `retry:false` so a 503 (mailbox unreachable)
 * surfaces immediately instead of spinning; `staleTime:0` so each open re-lists
 * the real folders. Gated by `enabled` so it only fires while the modal is open.
 */
export function useInstallationFolders(installationId: number, enabled: boolean) {
    return useQuery<string[]>({
        queryKey: [...CONNECTORS_KEY, 'folders', installationId],
        queryFn: () => adminConnectorsApi.listFolders(installationId),
        enabled,
        staleTime: 0,
        retry: false,
    });
}

/*
 * v8.17 — configure a credential-based connector (IMAP). On success the BE has
 * upserted the installation (active for basic-auth, pending for xoauth2); we
 * invalidate the list so the card reflects the new status. The xoauth2
 * `redirect_to` is handled by the caller (navigates the browser to the provider).
 * A failed basic-auth credential surfaces as a 422 the caller renders inline.
 */
export function useConfigureConnector() {
    const qc = useQueryClient();
    return useMutation<
        ConfigureResponse['data'],
        unknown,
        { key: string; payload: ConfigureConnectorPayload }
    >({
        mutationFn: ({ key, payload }) => adminConnectorsApi.configure(key, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: CONNECTORS_KEY });
        },
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
