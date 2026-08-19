import { api } from '../../lib/api';

export type McpConnectionScope = 'shared' | 'personal';
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
    bearer?: string;
}

function base(scope: McpConnectionScope): string {
    return scope === 'shared' ? '/api/admin/connectors/mcp' : '/api/me/connected-apps/mcp';
}

export const mcpConnectionsApi = {
    async list(scope: McpConnectionScope): Promise<McpConnectionDto[]> {
        const { data } = await api.get<McpConnectionDto[]>(base(scope));
        return data;
    },

    async create(scope: McpConnectionScope, payload: CreateMcpConnectionPayload): Promise<void> {
        await api.post(base(scope), payload);
    },

    async discover(scope: McpConnectionScope, connectionId: string): Promise<void> {
        await api.post(`${base(scope)}/${connectionId}/discover`);
    },

    async disconnect(scope: McpConnectionScope, connectionId: string): Promise<void> {
        await api.post(`${base(scope)}/${connectionId}/disconnect`);
    },

    async remove(scope: McpConnectionScope, connectionId: string): Promise<void> {
        await api.delete(`${base(scope)}/${connectionId}`);
    },

    async setTool(scope: McpConnectionScope, connectionId: string, toolId: number, enabled: boolean): Promise<void> {
        await api.put(`${base(scope)}/${connectionId}/tools/${toolId}`, { enabled });
    },

    async setResource(connectionId: string, resourceId: number, enabled: boolean): Promise<void> {
        await api.put(`/api/admin/connectors/mcp/${connectionId}/resources/${resourceId}`, { enabled });
    },

    async syncResources(connectionId: string): Promise<void> {
        await api.post(`/api/admin/connectors/mcp/${connectionId}/resources/sync`);
    },

    async beginOAuth(scope: McpConnectionScope, connectionId: string): Promise<string> {
        const { data } = await api.post<{ authorization_url: string }>(`${base(scope)}/${connectionId}/oauth`, {
            ui_destination: window.location.pathname,
        });
        return data.authorization_url;
    },
};
