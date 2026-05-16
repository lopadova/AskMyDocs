import { api } from '../../../lib/api';

/*
 * v5.0/W2 — MCP admin HTTP client. Mirrors the BE controllers in
 *   app/Http/Controllers/Api/Admin/McpServersAdminController.php
 *   app/Http/Controllers/Api/Admin/McpToolCallAuditController.php
 * (R9 — every field name + URL here MUST match the backend source of
 * truth).
 */

export type McpTransport = 'stdio' | 'sse' | 'http';
export type McpServerStatus = 'pending' | 'active' | 'disabled' | 'errored';

export interface McpHandshakeTool {
    name: string;
    description?: string | null;
    inputSchema?: Record<string, unknown> | null;
}

export interface McpHandshakeResource {
    uri: string;
    name?: string | null;
    description?: string | null;
}

export interface McpHandshakeResponse {
    ok?: boolean;
    status?: 'ok' | 'error';
    message?: string;
    protocol_version?: string;
    server_info?: { name?: string; version?: string };
    capabilities?: Record<string, unknown>;
    tools?: McpHandshakeTool[];
    resources?: McpHandshakeResource[];
    duration_ms?: number;
}

export interface McpServerEntry {
    id: number;
    name: string;
    transport: McpTransport;
    endpoint: string;
    enabled_tools: string[];
    status: McpServerStatus;
    last_handshake_at: string | null;
    handshake_response: McpHandshakeResponse | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface McpServerListResponse {
    data: McpServerEntry[];
}

export interface McpServerSingleResponse {
    data: McpServerEntry;
}

export interface McpRegisterPayload {
    name: string;
    transport: McpTransport;
    endpoint: string;
    auth_config?: Record<string, unknown>;
    enabled_tools?: string[];
}

export async function listMcpServers(): Promise<McpServerEntry[]> {
    const response = await api.get<McpServerListResponse>('/api/admin/mcp-servers');
    return response.data.data ?? [];
}

export async function registerMcpServer(payload: McpRegisterPayload): Promise<McpServerEntry> {
    const response = await api.post<McpServerSingleResponse>('/api/admin/mcp-servers', payload);
    return response.data.data;
}

export async function handshakeMcpServer(id: number): Promise<McpServerEntry> {
    const response = await api.post<McpServerSingleResponse>(
        `/api/admin/mcp-servers/${id}/handshake`,
    );
    return response.data.data;
}

export async function updateMcpServerTools(id: number, enabledTools: string[]): Promise<McpServerEntry> {
    const response = await api.patch<McpServerSingleResponse>(
        `/api/admin/mcp-servers/${id}/tools`,
        { enabled_tools: enabledTools },
    );
    return response.data.data;
}

export async function disableMcpServer(id: number): Promise<McpServerEntry> {
    const response = await api.post<McpServerSingleResponse>(
        `/api/admin/mcp-servers/${id}/disable`,
    );
    return response.data.data;
}

export async function deleteMcpServer(id: number): Promise<void> {
    await api.delete(`/api/admin/mcp-servers/${id}`);
}

/* --------------------------------------------------------------------- */
/* Audit log                                                              */
/* --------------------------------------------------------------------- */

/**
 * v7.0/W6.3 — the `mcp_tool_call_audit.status` column was widened
 * from a strict ENUM `('ok','error','timeout','denied')` to
 * `varchar(32)` so the package (and future host code) can emit new
 * values without a migration: `transport_error` is the first one
 * the package ships. The known set is captured below for
 * autocomplete + the StatusPill palette, but `McpAuditStatus`
 * intentionally accepts arbitrary strings via the `(string & {})`
 * trick so the type doesn't lie about what the API can return.
 *
 * Mirror change: `result_hash` is nullable on the column — the
 * package writes `null` on a transport failure (no result to
 * hash). The host's own writers still populate a SHA-256 hex
 * string on success, so the union remains backwards-compatible.
 */
export type McpAuditKnownStatus = 'ok' | 'error' | 'timeout' | 'denied' | 'transport_error';
export type McpAuditStatus = McpAuditKnownStatus | (string & Record<never, never>);

export interface McpAuditEntry {
    id: number;
    user_id: number | null;
    user_name?: string | null;
    mcp_server_id: number;
    mcp_server_name?: string | null;
    conversation_id: number | null;
    message_id: number | null;
    tool_name: string;
    input_json_redacted: Record<string, unknown> | null;
    result_hash: string | null;
    duration_ms: number;
    status: McpAuditStatus;
    error_json: Record<string, unknown> | null;
    created_at: string;
}

export interface McpAuditListResponse {
    data: McpAuditEntry[];
    meta?: {
        total: number;
        per_page: number;
        current_page: number;
        last_page: number;
    };
}

export interface McpAuditFilters {
    server_id?: number;
    user_id?: number;
    tool_name?: string;
    status?: McpAuditStatus;
    from?: string;
    to?: string;
    page?: number;
    per_page?: number;
}

export async function listMcpAudit(filters: McpAuditFilters = {}): Promise<McpAuditListResponse> {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== null && value !== '') {
            params.set(key, String(value));
        }
    }
    const response = await api.get<McpAuditListResponse>(
        `/api/admin/mcp-tool-call-audit${params.toString() ? `?${params.toString()}` : ''}`,
    );
    return response.data;
}
