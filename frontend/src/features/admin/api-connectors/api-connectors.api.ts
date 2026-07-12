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
/** Persisted response-shape taxonomy (Support\EndpointType). */
export type EndpointType = 'list' | 'detail' | 'unknown';
/** Operator wire choice on write: 'auto' unlocks server-side detection. */
export type EndpointTypeChoice = 'auto' | 'list' | 'detail';
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
    endpoint_type: EndpointType;
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
    /** Present once the `relations` relation is loaded (index + show). */
    relations?: ApiRouteRelation[];
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
    endpoint_type: EndpointType;
    endpoint_type_locked: boolean;
    items_path: string | null;
    timeout_ms: number | null;
    cache_ttl_s: number | null;
    rate_limit: number | null;
    input_schema: Record<string, unknown> | null;
    output_schema: Record<string, unknown> | null;
    param_mapping: Record<string, unknown> | null;
    tool_definition: ToolDefinition | null;
    output_transform: Record<string, unknown> | null;
    pagination: PaginationConfig | null;
    last_test_at: string | null;
    last_test_status: number | null;
    last_test_payload: Record<string, unknown> | null;
    parameters?: ApiRouteParameter[];
    /** The canonical config JSON — the FE modal's form model. Present when the
     *  parameters relation is loaded (show/store/update/test responses). */
    config?: RouteConfig;
    created_at: string | null;
    updated_at: string | null;
}

/**
 * The canonical config JSON — the single object the AI produces, the modal binds
 * to, and the codec persists. Grouped identity·request·response·options; mirrors
 * the BE `Padosoft\AskMyDocsConnectorApi\Support\RouteConfig` exactly (R9). The
 * JSON is internal — the user only ever sees the form built over it.
 */
export interface RouteConfigParam {
    name: string;
    location: ParamLocation;
    source: ParamSource;
    type: ParamType;
    required: boolean;
    description: string | null;
    sort_order: number;
    /** Present only when source === 'fixed'. */
    value?: string | null;
    /** A credential KEY name (never a secret) — present only when source === 'secret'. */
    secret_ref?: string | null;
}

export interface RouteConfig {
    identity: {
        name: string;
        slug: string | null;
        description: string | null;
        mode: RouteMode;
    };
    request: {
        http_method: HttpMethod;
        /** FULL canonical URL (the base/path split is display-only in the modal). */
        url: string;
        auth_profile_id: number | null;
        params: RouteConfigParam[];
    };
    response: {
        endpoint_type: EndpointTypeChoice;
        items_path: string | null;
        transform: { include: string[]; exclude: string[] } | null;
        pagination: PaginationConfig | null;
    };
    options: {
        timeout_ms: number | null;
        cache_ttl_s: number | null;
        rate_limit: number | null;
    };
}

/** "Testa" outcome (ApiRouteController::testConfig) — a dry-run of an unsaved config. */
export interface TestConfigResponse {
    test: TestResult;
    endpoint_type: EndpointType;
    items_path: string | null;
    detected_pagination: PaginationConfig | null;
    item_count: number | null;
}

/** "Configura con AI" outcome (ApiRouteController::produceConfig) — the filled config + its final dry-run. */
export interface ProduceConfigResponse {
    config: RouteConfig | null;
    final_test: TestResult;
    source: 'openapi' | 'response' | 'none';
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
    /** Auto-detected (or locked) taxonomy after this test call. */
    endpoint_type: EndpointType;
    /** Dot-path to the item array for a list ('' = top-level); null otherwise. */
    items_path: string | null;
    /** JSON schema of a single list item (output_schema walked to items_path). */
    item_schema: Record<string, unknown> | null;
}

/** One reduction the {@link StructureReducer} applied, biggest omitted group first. */
export interface ReductionNote {
    path: string;
    total: number;
    kept: number;
    omitted: number;
}

/**
 * Workbench "Analisi" outcome (ApiRouteController::analyze) — the classified test
 * result + a deterministically REDUCED body (arrays truncated so the shape reads
 * start-to-end) + reduction notes + an optional AI narration. Returned RAW; a
 * failed/non-JSON call is a valid 200 with `reduced: null`.
 */
export interface AnalyzeResponse {
    test: TestResult;
    reduced: unknown;
    notes: ReductionNote[];
    /** AI narration of the structure; null when AI is off / not yet wired (P2). */
    analysis: string | null;
}

/** How an endpoint paginates (spec items 4-5). Persisted on the route. */
export interface PaginationConfig {
    type: 'page' | 'cursor' | 'none';
    page_param?: string;
    size_param?: string;
    start_page?: number;
    cursor_param?: string;
    next_cursor_path?: string;
    next_url_path?: string;
    items_path?: string;
}

export interface DetectPaginationResponse {
    config: PaginationConfig | null;
    /** Where the guess came from: heuristic, ai (fallback), or none. */
    source: 'heuristic' | 'ai' | 'none';
}

export interface TestPaginationResponse {
    pages: { ok: boolean; status: number | null; item_count: number }[];
    /** Did page 2 actually return different items than page 1? */
    distinct: boolean;
    note: string;
}

/** A full route-configuration suggestion (workbench "Configura con AI"). */
export interface AiConfigureSuggestion {
    endpoint_type: EndpointType;
    items_path: string | null;
    pagination: PaginationConfig | null;
    tool_name: string | null;
    tool_description: string | null;
    parameters: RouteParameterInput[];
}

export interface AiConfigureResponse {
    test: TestResult;
    /** Null when the call returned no JSON to analyze. */
    suggestion: AiConfigureSuggestion | null;
}

/** One-shot "Configura con AI": what was applied + the final verification. */
export interface ApplyAiConfigureResponse {
    applied: AiConfigureSuggestion | null;
    final_test: TestResult;
    pagination_test: TestPaginationResponse | null;
    /** 'openapi' when read from a spec URL, 'response' when inferred from the live call. */
    source: 'openapi' | 'response';
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
    /** 'auto' (server detects) | 'list' | 'detail' (explicit, locks the choice). */
    endpoint_type?: EndpointTypeChoice;
    /** Dot-path to the item array for a list route ('' = top-level array). */
    items_path?: string | null;
    timeout_ms?: number | null;
    cache_ttl_s?: number | null;
    rate_limit?: number | null;
    output_transform?: Record<string, unknown> | null;
    pagination?: PaginationConfig | null;
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

/** One field-map row of a relation: a list-item dot-path → a detail param. */
export interface RelationFieldMap {
    from: string;
    to_param: string;
    to_location?: ParamLocation;
}

/** Compact route stub embedded on a relation (ApiRouteRelationResource). */
export interface RelationRouteStub {
    id: number;
    name: string;
    slug: string;
    endpoint_type: EndpointType;
}

/** A List → Detail relation (ApiRouteRelationResource). */
export interface ApiRouteRelation {
    id: number;
    api_connector_id: number;
    list_route_id: number;
    detail_route_id: number;
    name: string | null;
    description: string | null;
    field_map: RelationFieldMap[];
    sort_order: number;
    /** Present when the routes are eager-loaded (index / show). */
    list_route?: RelationRouteStub;
    detail_route?: RelationRouteStub;
    created_at: string | null;
    updated_at: string | null;
}

export interface RelationPayload {
    list_route_id: number;
    detail_route_id: number;
    field_map: RelationFieldMap[];
    name?: string | null;
    description?: string | null;
    sort_order?: number;
}

/** Drill-test outcome — the mapped arguments + the raw detail response (like a test). */
export interface DrillResult {
    arguments: Record<string, unknown>;
    result: TestResult & { duration_ms: number | null };
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

    /** Create a route from the canonical config JSON (the BE codec un-groups it). */
    async createRoute(connectorId: number, config: RouteConfig): Promise<ApiRoute> {
        const { data } = await api.post<{ data: ApiRoute }>(`${BASE}/${connectorId}/routes`, { config });
        return data.data;
    },

    /** Update a route from the canonical config JSON (a full replace). */
    async updateRoute(routeId: number, config: RouteConfig): Promise<ApiRoute> {
        const { data } = await api.patch<{ data: ApiRoute }>(`${BASE}/routes/${routeId}`, { config });
        return data.data;
    },

    async destroyRoute(routeId: number): Promise<void> {
        await api.delete(`${BASE}/routes/${routeId}`);
    },

    /** "Testa": dry-run an UNSAVED config against its endpoint (works in create mode). Non-persisting; 200 even on failure. */
    async testConfig(connectorId: number, config: RouteConfig, exampleArgs?: Record<string, unknown>): Promise<TestConfigResponse> {
        const { data } = await api.post<TestConfigResponse>(`${BASE}/${connectorId}/routes/test-config`, {
            config,
            example_args: exampleArgs ?? {},
        });
        return data;
    },

    /** "Configura con AI": the single AI pass → the filled config + a final dry-run. Non-persisting. */
    async produceConfig(
        connectorId: number,
        config: RouteConfig,
        exampleArgs?: Record<string, unknown>,
        openApiUrl?: string,
    ): Promise<ProduceConfigResponse> {
        const { data } = await api.post<ProduceConfigResponse>(`${BASE}/${connectorId}/routes/produce-config`, {
            config,
            example_args: exampleArgs ?? {},
            openapi_url: openApiUrl || undefined,
        });
        return data;
    },

    /** Run the route against the live target (no ingest). HTTP 200 even on a failed call (ok:false). */
    async testRoute(routeId: number, exampleArgs?: Record<string, unknown>): Promise<TestRouteResponse> {
        const { data } = await api.post<TestRouteResponse>(`${BASE}/routes/${routeId}/test`, {
            example_args: exampleArgs ?? {},
        });
        return data;
    },

    /** Workbench "Analisi": fire the route + return a reduced structure (+notes, +AI). Non-persisting; 200 even on failure. */
    async analyzeRoute(routeId: number, exampleArgs?: Record<string, unknown>): Promise<AnalyzeResponse> {
        const { data } = await api.post<AnalyzeResponse>(`${BASE}/routes/${routeId}/analyze`, {
            example_args: exampleArgs ?? {},
        });
        return data;
    },

    /** Workbench "Paginazione": guess the pagination scheme (heuristic + AI fallback). Non-persisting. */
    async detectPagination(routeId: number, exampleArgs?: Record<string, unknown>): Promise<DetectPaginationResponse> {
        const { data } = await api.post<DetectPaginationResponse>(`${BASE}/routes/${routeId}/detect-pagination`, {
            example_args: exampleArgs ?? {},
        });
        return data;
    },

    /** Workbench "Paginazione": fire two pages with the config and report whether page 2 advances. Non-persisting. */
    async testPagination(
        routeId: number,
        pagination: PaginationConfig,
        exampleArgs?: Record<string, unknown>,
    ): Promise<TestPaginationResponse> {
        const { data } = await api.post<TestPaginationResponse>(`${BASE}/routes/${routeId}/test-pagination`, {
            pagination,
            example_args: exampleArgs ?? {},
        });
        return data;
    },

    /** Workbench "Configura con AI": propose the full route config from a test call. Non-persisting. */
    async aiConfigure(routeId: number, exampleArgs?: Record<string, unknown>): Promise<AiConfigureResponse> {
        const { data } = await api.post<AiConfigureResponse>(`${BASE}/routes/${routeId}/ai-configure`, {
            example_args: exampleArgs ?? {},
        });
        return data;
    },

    /** One-shot "Configura con AI": detect + apply + final test. PERSISTS. Reads from `openApiUrl` when given. */
    async applyAiConfigure(
        routeId: number,
        exampleArgs?: Record<string, unknown>,
        openApiUrl?: string,
    ): Promise<ApplyAiConfigureResponse> {
        const { data } = await api.post<ApplyAiConfigureResponse>(`${BASE}/routes/${routeId}/ai-configure-apply`, {
            example_args: exampleArgs ?? {},
            openapi_url: openApiUrl || undefined,
        });
        return data;
    },

    /** Workbench "Cerca": fire the route with the given search parameters. Non-persisting. */
    async testSearch(routeId: number, searchArgs: Record<string, unknown>): Promise<{ test: TestResult }> {
        const { data } = await api.post<{ test: TestResult }>(`${BASE}/routes/${routeId}/test-search`, {
            example_args: searchArgs,
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

    // --- relations (List → Detail) ---

    async listRelations(connectorId: number): Promise<ApiRouteRelation[]> {
        const { data } = await api.get<{ data: ApiRouteRelation[] }>(`${BASE}/${connectorId}/relations`);
        return data.data;
    },

    async createRelation(connectorId: number, payload: RelationPayload): Promise<ApiRouteRelation> {
        const { data } = await api.post<{ data: ApiRouteRelation }>(
            `${BASE}/${connectorId}/relations`,
            payload,
        );
        return data.data;
    },

    async updateRelation(relationId: number, payload: Partial<RelationPayload>): Promise<ApiRouteRelation> {
        const { data } = await api.patch<{ data: ApiRouteRelation }>(
            `${BASE}/relations/${relationId}`,
            payload,
        );
        return data.data;
    },

    async destroyRelation(relationId: number): Promise<void> {
        await api.delete(`${BASE}/relations/${relationId}`);
    },

    /**
     * Drill-test a relation: apply its field_map to a chosen list item (explicit
     * `list_item` OR `item_index` into the list route's last test payload) and
     * fire the detail call. RAW (no `{data}` wrapper); a failed detail call is
     * HTTP 200 with `result.ok:false`, an unbuildable mapping is 422 (R14).
     */
    async drillRelation(
        relationId: number,
        payload: { list_item?: Record<string, unknown>; item_index?: number },
    ): Promise<DrillResult> {
        const { data } = await api.post<DrillResult>(`${BASE}/relations/${relationId}/drill`, payload);
        return data;
    },
};
