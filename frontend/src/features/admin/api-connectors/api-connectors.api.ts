import { api } from '../../../lib/api';

/*
 * v8.27 — API Connector (Connettore API) admin HTTP client. Mirrors the
 * `padosoft/askmydocs-connector-api` package controllers + resources:
 *   - ApiConnectorController       → /api/admin/api-connectors
 *   - ApiAuthProfileController     → .../{id}/auth-profiles, .../auth-profiles/{id}
 *   - ApiRouteController           → .../{id}/routes, .../routes/{id}, .../routes/{id}/{test,try,...}
 *
 * R9 — every field name + URL here MUST match the backend source of truth:
 *   - Resources:  ApiConnectorResource / ApiAuthProfileResource / ApiRouteResource /
 *     ApiRouteParameterResource (secret-hidden: an auth profile NEVER returns
 *     `credentials`, only `type` + `config` + `has_credentials`).
 *   - Enums:      Support\{HttpMethod,RouteMode,RouteStatus,AuthType,ParamLocation,
 *     ParamSource,ParamType}.
 *
 * Envelope discipline (matches Laravel JsonResource defaults):
 *   - index / store / show / update / activate / disable wrap the resource in
 *     `{ data: {...} }`.
 *   - test / try / regenerate-description / destroy return a RAW object (no
 *     `data` wrapper).
 */

// ---------------------------------------------------------------------------
// Enums (mirrored from the package Support\* enums)
// ---------------------------------------------------------------------------

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
export type RouteMode = 'tool' | 'ingest' | 'both';
export type RouteStatus = 'draft' | 'tested' | 'active' | 'disabled';
export type AuthType = 'none' | 'api_key' | 'bearer' | 'basic' | 'custom' | 'oauth2_cc';
export type ParamLocation = 'path' | 'query' | 'header' | 'body';
export type ParamSource = 'llm' | 'fixed' | 'secret';
export type ParamType = 'string' | 'integer' | 'number' | 'boolean' | 'array' | 'object';

// ---------------------------------------------------------------------------
// DTOs (mirror the Http\Resources shapes)
// ---------------------------------------------------------------------------

/**
 * The LLM tool descriptor a tested/active route exposes — name + description +
 * a JSON-schema `input_schema`. Generated server-side by ToolDefinitionGenerator;
 * the operator can override `name`/`description` (PATCH `tool_definition`).
 */
export interface ToolDefinition {
    name: string;
    description: string;
    input_schema: Record<string, unknown>;
}

/** Compact route row included in the connector list/detail (ApiConnectorResource::routeSummary). */
export interface ApiRouteSummary {
    id: number;
    name: string;
    slug: string;
    status: RouteStatus;
    mode: RouteMode;
    http_method: HttpMethod;
    last_test_status: number | null;
}

/** Secret-hidden auth profile (ApiAuthProfileResource) — credentials are write-only. */
export interface ApiAuthProfile {
    id: number;
    api_connector_id: number;
    type: AuthType;
    config: Record<string, unknown>;
    has_credentials: boolean;
}

export interface ApiConnector {
    id: number;
    project_key: string | null;
    name: string;
    description: string | null;
    base_url: string | null;
    default_auth_profile_id: number | null;
    headers: Record<string, string>;
    is_active: boolean;
    /** Present once the `routes` relation is loaded (always loaded on index). */
    routes?: ApiRouteSummary[];
    /** Present once the `authProfiles` relation is loaded (index + show). */
    auth_profiles?: ApiAuthProfile[];
    created_at: string | null;
    updated_at: string | null;
}

/** A single route parameter (ApiRouteParameterResource). `secret_ref` is a key NAME, never the value. */
export interface ApiRouteParameter {
    id: number;
    name: string;
    location: ParamLocation;
    source: ParamSource;
    type: ParamType;
    required: boolean;
    value: string | null;
    secret_ref: string | null;
    description: string | null;
    sort_order: number | null;
}

/** Full route detail (ApiRouteResource) — includes generated artifacts + last-test outcome. */
export interface ApiRoute {
    id: number;
    api_connector_id: number;
    project_key: string | null;
    name: string;
    slug: string;
    description: string | null;
    http_method: HttpMethod;
    url: string;
    auth_profile_id: number | null;
    mode: RouteMode;
    status: RouteStatus;
    timeout_ms: number | null;
    cache_ttl_s: number | null;
    rate_limit: number | null;
    input_schema: Record<string, unknown> | null;
    output_schema: Record<string, unknown> | null;
    param_mapping: Record<string, unknown> | null;
    tool_definition: ToolDefinition | null;
    output_transform: Record<string, unknown> | null;
    last_test_at: string | null;
    last_test_status: number | null;
    last_test_payload: Record<string, unknown> | null;
    parameters?: ApiRouteParameter[];
    created_at: string | null;
    updated_at: string | null;
}

/** Test outcome (ApiRouteController::testPayload). A failed test is HTTP 200 with `ok:false`. */
export interface TestResult {
    ok: boolean;
    status: number | null;
    status_label: string | null;
    is_json: boolean;
    error: string | null;
    headers: Record<string, string> | null;
    body: unknown;
}

export interface TestRouteResponse {
    test: TestResult;
    tool_definition: ToolDefinition | null;
    input_schema: Record<string, unknown> | null;
    output_schema: Record<string, unknown> | null;
}

/**
 * Ad-hoc probe outcome (ApiRouteController::probePayload) — the same classified
 * TestResult shape + wall-clock timing. Returned RAW (no `{data}` wrapper); a
 * failed/non-JSON upstream call is a valid HTTP 200 outcome with `ok:false`.
 */
export interface ProbeResult extends TestResult {
    duration_ms: number | null;
}

// ---------------------------------------------------------------------------
// Write payloads (mirror the FormRequest rules)
// ---------------------------------------------------------------------------

export interface ConnectorPayload {
    name: string;
    description?: string | null;
    project_key?: string | null;
    base_url?: string | null;
    headers?: Record<string, string>;
    is_active?: boolean;
}

export interface AuthProfilePayload {
    type: AuthType;
    /** Write-only — never echoed back. Omit / blank = keep existing on update. */
    credentials?: Record<string, string>;
    config?: Record<string, unknown>;
}

/** One row of the route's parameters editor (StoreRouteRequest `parameters.*`). */
export interface RouteParameterInput {
    name: string;
    location: ParamLocation;
    source: ParamSource;
    type?: ParamType;
    required?: boolean;
    value?: string | null;
    secret_ref?: string | null;
    description?: string | null;
    sort_order?: number;
}

export interface RoutePayload {
    name: string;
    slug?: string | null;
    description?: string | null;
    http_method: HttpMethod;
    url: string;
    auth_profile_id?: number | null;
    mode?: RouteMode;
    timeout_ms?: number | null;
    cache_ttl_s?: number | null;
    rate_limit?: number | null;
    output_transform?: Record<string, unknown> | null;
    parameters?: RouteParameterInput[];
}

/**
 * Ad-hoc free-endpoint probe (ProbeRequest): a raw, unauthenticated live call.
 * No auth / connector / route — the modal fires it and reads the response.
 */
export interface ProbePayload {
    http_method: HttpMethod;
    url: string;
    headers?: Record<string, string> | null;
    query?: Record<string, string> | null;
    body?: Record<string, unknown> | null;
}

const BASE = '/api/admin/api-connectors';

export const apiConnectorsApi = {
    async list(): Promise<ApiConnector[]> {
        const { data } = await api.get<{ data: ApiConnector[] }>(BASE);
        return data.data;
    },

    async show(id: number): Promise<ApiConnector> {
        const { data } = await api.get<{ data: ApiConnector }>(`${BASE}/${id}`);
        return data.data;
    },

    async create(payload: ConnectorPayload): Promise<ApiConnector> {
        const { data } = await api.post<{ data: ApiConnector }>(BASE, payload);
        return data.data;
    },

    async update(id: number, payload: Partial<ConnectorPayload>): Promise<ApiConnector> {
        const { data } = await api.patch<{ data: ApiConnector }>(`${BASE}/${id}`, payload);
        return data.data;
    },

    async destroy(id: number): Promise<void> {
        await api.delete(`${BASE}/${id}`);
    },

    // --- auth profiles ---

    async createAuthProfile(connectorId: number, payload: AuthProfilePayload): Promise<ApiAuthProfile> {
        const { data } = await api.post<{ data: ApiAuthProfile }>(
            `${BASE}/${connectorId}/auth-profiles`,
            payload,
        );
        return data.data;
    },

    async updateAuthProfile(profileId: number, payload: AuthProfilePayload): Promise<ApiAuthProfile> {
        const { data } = await api.patch<{ data: ApiAuthProfile }>(
            `${BASE}/auth-profiles/${profileId}`,
            payload,
        );
        return data.data;
    },

    async destroyAuthProfile(profileId: number): Promise<void> {
        await api.delete(`${BASE}/auth-profiles/${profileId}`);
    },

    // --- routes ---

    async showRoute(routeId: number): Promise<ApiRoute> {
        const { data } = await api.get<{ data: ApiRoute }>(`${BASE}/routes/${routeId}`);
        return data.data;
    },

    async createRoute(connectorId: number, payload: RoutePayload): Promise<ApiRoute> {
        const { data } = await api.post<{ data: ApiRoute }>(`${BASE}/${connectorId}/routes`, payload);
        return data.data;
    },

    async updateRoute(routeId: number, payload: Partial<RoutePayload>): Promise<ApiRoute> {
        const { data } = await api.patch<{ data: ApiRoute }>(`${BASE}/routes/${routeId}`, payload);
        return data.data;
    },

    async destroyRoute(routeId: number): Promise<void> {
        await api.delete(`${BASE}/routes/${routeId}`);
    },

    /** Run the route against the live target (no ingest). HTTP 200 even on a failed call (ok:false). */
    async testRoute(routeId: number, exampleArgs?: Record<string, unknown>): Promise<TestRouteResponse> {
        const { data } = await api.post<TestRouteResponse>(`${BASE}/routes/${routeId}/test`, {
            example_args: exampleArgs ?? {},
        });
        return data;
    },

    /** Re-derive the tool name/description from the inferred schema. */
    async regenerateDescription(routeId: number): Promise<ToolDefinition | null> {
        const { data } = await api.post<{ tool_definition: ToolDefinition | null }>(
            `${BASE}/routes/${routeId}/regenerate-description`,
        );
        return data.tool_definition;
    },

    /** Promote a TESTED route to ACTIVE. 422 if not yet tested. */
    async activateRoute(routeId: number): Promise<ApiRoute> {
        const { data } = await api.post<{ data: ApiRoute }>(`${BASE}/routes/${routeId}/activate`);
        return data.data;
    },

    async disableRoute(routeId: number): Promise<ApiRoute> {
        const { data } = await api.post<{ data: ApiRoute }>(`${BASE}/routes/${routeId}/disable`);
        return data.data;
    },

    /** Execute the route end-to-end via the tool executor — returns whatever the executor produced. */
    async tryRoute(routeId: number, args?: Record<string, unknown>): Promise<unknown> {
        const { data } = await api.post<{ result: unknown }>(`${BASE}/routes/${routeId}/try`, {
            arguments: args ?? {},
        });
        return data.result;
    },

    /**
     * Ad-hoc "playground" probe of a FREE (no-auth) endpoint — persists nothing.
     * Returned RAW (no `{data}` wrapper); a failed/non-JSON call is HTTP 200 with
     * `ok:false` so the modal can read the outcome (R14).
     */
    async probe(payload: ProbePayload): Promise<ProbeResult> {
        const { data } = await api.post<ProbeResult>(`${BASE}/probe`, payload);
        return data;
    },
};
