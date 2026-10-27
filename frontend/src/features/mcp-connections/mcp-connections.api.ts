import { api } from '../../lib/api';

export type McpConnectionScope = 'shared' | 'personal';
export type McpAuthenticationMethod = 'oauth' | 'bearer' | 'none';
export type McpConnectionStatus = 'pending' | 'active' | 'disabled' | 'errored' | 'reauthorization_required';

export interface McpConnectionToolDto {
    id: number;
    remote_name: string;
    local_name: string;
    title: string | null;
    description: string | null;
    risk: 'read' | 'write' | 'destructive' | 'unknown';
    enabled: boolean;
    confirmation_required: boolean;
    removed_at: string | null;
}

export interface McpConnectionResourceDto {
    id: number;
    uri: string;
    name: string | null;
    title: string | null;
    description: string | null;
    mime_type: string | null;
    size: number | null;
    enabled: boolean;
    last_ingested_at: string | null;
    removed_at: string | null;
    ingest_error_json: Record<string, unknown> | null;
}

export interface McpServerDto {
    id: number;
    name: string;
    endpoint: string;
    transport: string;
    auth_mode: string;
    negotiated_era: 'modern' | 'legacy' | null;
    negotiated_version: string | null;
    status: McpConnectionStatus;
    legacy_reference?: string | null;
}

export interface McpConnectionDto {
    id: number;
    public_id: string;
    mode: McpConnectionScope;
    label: string;
    project_key: string | null;
    status: McpConnectionStatus;
    granted_scopes_json: string[] | null;
    error_json: Record<string, unknown> | null;
    connector_installation_id: number | null;
    server: McpServerDto;
    tools: McpConnectionToolDto[];
    resources: McpConnectionResourceDto[];
}

export interface CreateMcpConnectionPayload {
    name: string;
    label?: string;
    endpoint: string;
    transport: 'auto' | 'streamable_http' | 'legacy_sse';
    project_key?: string | null;
    auth_method: McpAuthenticationMethod;
    bearer?: string;
    ui_destination?: string;
}

export interface CreateMcpConnectionResult {
    connection: McpConnectionDto;
    authorization_required?: boolean;
    next_action?: {
        type: 'oauth_redirect';
        authorization_url: string;
        expires_at: string;
    };
}

function tenantOptions(tenant?: string | null): [] | [{ headers: { 'X-Tenant-Id': string } }] {
    return tenant ? [{ headers: { 'X-Tenant-Id': tenant } }] : [];
}
function post<T>(url: string, body: unknown, tenant?: string | null) {
    return tenant ? api.post<T>(url, body, ...tenantOptions(tenant))
        : body === undefined ? api.post<T>(url) : api.post<T>(url, body);
}

function base(scope: McpConnectionScope): string {
    return scope === 'shared' ? '/api/admin/connectors/mcp' : '/api/me/connected-apps/mcp';
}

export const mcpConnectionsApi = {
    async list(scope: McpConnectionScope, tenant?: string | null): Promise<McpConnectionDto[]> {
        const { data } = await api.get<McpConnectionDto[]>(base(scope), ...tenantOptions(tenant));
        return data;
    },

    async create(scope: McpConnectionScope, payload: CreateMcpConnectionPayload, tenant?: string | null): Promise<CreateMcpConnectionResult> {
        const { data } = await post<CreateMcpConnectionResult>(base(scope), payload, tenant);
        return data;
    },

    async discover(scope: McpConnectionScope, connectionId: string, tenant?: string | null): Promise<void> {
        await post(`${base(scope)}/${connectionId}/discover`, undefined, tenant);
    },

    async disconnect(scope: McpConnectionScope, connectionId: string, tenant?: string | null): Promise<void> {
        await post(`${base(scope)}/${connectionId}/disconnect`, undefined, tenant);
    },

    async remove(scope: McpConnectionScope, connectionId: string, tenant?: string | null): Promise<void> {
        await api.delete(`${base(scope)}/${connectionId}`, ...tenantOptions(tenant));
    },

    async setTool(scope: McpConnectionScope, connectionId: string, toolId: number, enabled: boolean, tenant?: string | null): Promise<void> {
        await api.put(`${base(scope)}/${connectionId}/tools/${toolId}`, { enabled }, ...tenantOptions(tenant));
    },

    async setResource(connectionId: string, resourceId: number, enabled: boolean, tenant?: string | null): Promise<void> {
        await api.put(`/api/admin/connectors/mcp/${connectionId}/resources/${resourceId}`, { enabled }, ...tenantOptions(tenant));
    },

    async syncResources(connectionId: string, tenant?: string | null): Promise<void> {
        await post(`/api/admin/connectors/mcp/${connectionId}/resources/sync`, undefined, tenant);
    },

    async beginOAuth(scope: McpConnectionScope, connectionId: string, tenant?: string | null): Promise<string> {
        const { data } = await post<{ authorization_url: string }>(`${base(scope)}/${connectionId}/oauth`, {
            ui_destination: window.location.pathname,
        }, tenant);
        return data.authorization_url;
    },
};

/** Browser navigation is kept separate so OAuth dispatch can be exercised without leaving a test page. */
export const mcpOAuthNavigation = {
    redirect(url: string): void {
        const destination = new URL(url);
        if (!['https:', 'http:'].includes(destination.protocol) || destination.username || destination.password) {
            throw new Error('Invalid OAuth authorization URL.');
        }
        window.location.assign(destination.href);
    },
};
