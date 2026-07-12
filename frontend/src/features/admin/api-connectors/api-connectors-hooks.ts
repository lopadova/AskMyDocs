import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminProjectsApi, type AdminProject } from '../projects/admin-projects.api';
import {
    apiConnectorsApi,
    type ApiAuthProfile,
    type ApiConnector,
    type ApiRoute,
    type ApiRouteRelation,
    type AuthProfilePayload,
    type ConnectorPayload,
    type DrillResult,
    type ProbePayload,
    type ProbeResult,
    type RelationPayload,
    type RoutePayload,
    type AnalyzeResponse,
    type DetectPaginationResponse,
    type PaginationConfig,
    type TestPaginationResponse,
    type TestRouteResponse,
    type ToolDefinition,
} from './api-connectors.api';

/*
 * TanStack Query hooks over the /api/admin/api-connectors/* endpoints.
 * Mirrors features/admin/connectors conventions:
 *   - single shared partition key `['admin','api-connectors']`.
 *   - EVERY mutation invalidates the list query so the card grid refetches
 *     after any state transition (CRUD / test / activate / disable). The list
 *     is the source of truth for status badges + route summaries.
 *   - read-only diagnostics (`test`, `try`) do NOT invalidate the list — they
 *     mutate no list-visible state directly; the test mutation DOES invalidate
 *     because the BE flips the route status draft→tested as a side effect.
 *   - errors are surfaced by the caller (R14): no mutation swallows failures.
 */

export const API_CONNECTORS_KEY = ['admin', 'api-connectors'] as const;

export function useApiConnectors() {
    return useQuery<ApiConnector[]>({
        queryKey: [...API_CONNECTORS_KEY, 'list'],
        queryFn: () => apiConnectorsApi.list(),
        // 30s — connector/route status doesn't change every few seconds; the
        // post-action invalidation handles user-driven refreshes.
        staleTime: 30_000,
    });
}

/**
 * Single connector detail (routes + auth profiles eager-loaded). Gated by
 * `enabled` so it only fires while a connector drill-in / route editor is open.
 */
export function useApiConnector(id: number | null, enabled: boolean) {
    return useQuery<ApiConnector>({
        queryKey: [...API_CONNECTORS_KEY, 'detail', id],
        queryFn: () => apiConnectorsApi.show(id as number),
        enabled: enabled && id !== null,
        staleTime: 0,
    });
}

/**
 * Real project registry for the connector project-binding dropdown (R18: options
 * derive from the DB, never a hard-coded subset). Shares the SAME cache key as
 * the projects admin surface so the two never duplicate-fetch.
 */
export function useProjectOptions() {
    return useQuery<AdminProject[]>({
        queryKey: ['admin-projects'],
        queryFn: () => adminProjectsApi.list(),
        staleTime: 60_000,
    });
}

export function useCreateConnector() {
    const qc = useQueryClient();
    return useMutation<ApiConnector, unknown, ConnectorPayload>({
        mutationFn: (payload) => apiConnectorsApi.create(payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useUpdateConnector() {
    const qc = useQueryClient();
    return useMutation<ApiConnector, unknown, { id: number; payload: Partial<ConnectorPayload> }>({
        mutationFn: ({ id, payload }) => apiConnectorsApi.update(id, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useDeleteConnector() {
    const qc = useQueryClient();
    return useMutation<void, unknown, number>({
        mutationFn: (id) => apiConnectorsApi.destroy(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

// --- auth profiles ---

export function useCreateAuthProfile() {
    const qc = useQueryClient();
    return useMutation<ApiAuthProfile, unknown, { connectorId: number; payload: AuthProfilePayload }>({
        mutationFn: ({ connectorId, payload }) =>
            apiConnectorsApi.createAuthProfile(connectorId, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useUpdateAuthProfile() {
    const qc = useQueryClient();
    return useMutation<ApiAuthProfile, unknown, { profileId: number; payload: AuthProfilePayload }>({
        mutationFn: ({ profileId, payload }) =>
            apiConnectorsApi.updateAuthProfile(profileId, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useDeleteAuthProfile() {
    const qc = useQueryClient();
    return useMutation<void, unknown, number>({
        mutationFn: (profileId) => apiConnectorsApi.destroyAuthProfile(profileId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

// --- routes ---

export function useCreateRoute() {
    const qc = useQueryClient();
    return useMutation<ApiRoute, unknown, { connectorId: number; payload: RoutePayload }>({
        mutationFn: ({ connectorId, payload }) => apiConnectorsApi.createRoute(connectorId, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useUpdateRoute() {
    const qc = useQueryClient();
    return useMutation<ApiRoute, unknown, { routeId: number; payload: Partial<RoutePayload> }>({
        mutationFn: ({ routeId, payload }) => apiConnectorsApi.updateRoute(routeId, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useDeleteRoute() {
    const qc = useQueryClient();
    return useMutation<void, unknown, number>({
        mutationFn: (routeId) => apiConnectorsApi.destroyRoute(routeId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

/**
 * Run a live test. The BE flips the route status draft→tested + writes the
 * inferred schema as a side effect, so we DO invalidate the list afterwards so
 * the status badge reflects the new state. The returned preview is consumed by
 * the caller (TestConnectionPanel); a failed call is HTTP 200 with `ok:false`.
 */
export function useTestRoute() {
    const qc = useQueryClient();
    return useMutation<TestRouteResponse, unknown, { routeId: number; exampleArgs?: Record<string, unknown> }>({
        mutationFn: ({ routeId, exampleArgs }) => apiConnectorsApi.testRoute(routeId, exampleArgs),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useRegenerateDescription() {
    return useMutation<ToolDefinition | null, unknown, number>({
        mutationFn: (routeId) => apiConnectorsApi.regenerateDescription(routeId),
    });
}

export function useActivateRoute() {
    const qc = useQueryClient();
    return useMutation<ApiRoute, unknown, number>({
        mutationFn: (routeId) => apiConnectorsApi.activateRoute(routeId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useDisableRoute() {
    const qc = useQueryClient();
    return useMutation<ApiRoute, unknown, number>({
        mutationFn: (routeId) => apiConnectorsApi.disableRoute(routeId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

/**
 * Execute the route end-to-end (the "Prova tool" action). Read-only w.r.t. the
 * list — no invalidation; the caller renders the returned `result`.
 */
export function useTryRoute() {
    return useMutation<unknown, unknown, { routeId: number; args?: Record<string, unknown> }>({
        mutationFn: ({ routeId, args }) => apiConnectorsApi.tryRoute(routeId, args),
    });
}

/**
 * Workbench "Analisi" — read-only diagnostic (non-persisting dryRun on the BE),
 * so it does NOT invalidate the connectors cache (mirrors useTryRoute).
 */
export function useAnalyzeRoute() {
    return useMutation<AnalyzeResponse, unknown, { routeId: number; exampleArgs?: Record<string, unknown> }>({
        mutationFn: ({ routeId, exampleArgs }) => apiConnectorsApi.analyzeRoute(routeId, exampleArgs),
    });
}

/** Workbench "Paginazione" detect — read-only diagnostic, no cache invalidation. */
export function useDetectPagination() {
    return useMutation<DetectPaginationResponse, unknown, { routeId: number; exampleArgs?: Record<string, unknown> }>({
        mutationFn: ({ routeId, exampleArgs }) => apiConnectorsApi.detectPagination(routeId, exampleArgs),
    });
}

/** Workbench "Paginazione" test — read-only diagnostic, no cache invalidation. */
export function useTestPagination() {
    return useMutation<
        TestPaginationResponse,
        unknown,
        { routeId: number; pagination: PaginationConfig; exampleArgs?: Record<string, unknown> }
    >({
        mutationFn: ({ routeId, pagination, exampleArgs }) =>
            apiConnectorsApi.testPagination(routeId, pagination, exampleArgs),
    });
}

/**
 * Ad-hoc free-endpoint probe (the playground modal). Read-only — persists
 * nothing and touches no list-visible state, so it does NOT invalidate the
 * connectors query. The caller renders the returned `ProbeResult`.
 */
export function useProbeEndpoint() {
    return useMutation<ProbeResult, unknown, ProbePayload>({
        mutationFn: (payload) => apiConnectorsApi.probe(payload),
    });
}

// --- relations (List → Detail) ---

/**
 * A connector's list→detail relations. Gated by `enabled` so it only fires while
 * the connector's Relations section is expanded / a relation editor is open.
 */
export function useRelations(connectorId: number | null, enabled: boolean) {
    return useQuery<ApiRouteRelation[]>({
        queryKey: [...API_CONNECTORS_KEY, 'relations', connectorId],
        queryFn: () => apiConnectorsApi.listRelations(connectorId as number),
        enabled: enabled && connectorId !== null,
        staleTime: 0,
    });
}

export function useCreateRelation() {
    const qc = useQueryClient();
    return useMutation<ApiRouteRelation, unknown, { connectorId: number; payload: RelationPayload }>({
        mutationFn: ({ connectorId, payload }) => apiConnectorsApi.createRelation(connectorId, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useUpdateRelation() {
    const qc = useQueryClient();
    return useMutation<ApiRouteRelation, unknown, { relationId: number; payload: Partial<RelationPayload> }>({
        mutationFn: ({ relationId, payload }) => apiConnectorsApi.updateRelation(relationId, payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

export function useDeleteRelation() {
    const qc = useQueryClient();
    return useMutation<void, unknown, number>({
        mutationFn: (relationId) => apiConnectorsApi.destroyRelation(relationId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: API_CONNECTORS_KEY });
        },
    });
}

/**
 * Drill-test a relation. Read-only w.r.t. the list — no invalidation; the caller
 * renders the returned mapped arguments + raw detail result.
 */
export function useDrillRelation() {
    return useMutation<
        DrillResult,
        unknown,
        { relationId: number; payload: { list_item?: Record<string, unknown>; item_index?: number } }
    >({
        mutationFn: ({ relationId, payload }) => apiConnectorsApi.drillRelation(relationId, payload),
    });
}
