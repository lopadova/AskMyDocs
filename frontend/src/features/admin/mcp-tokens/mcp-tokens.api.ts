import { api } from '../../../lib/api';

export interface McpTenantTokenRow {
    id: number;
    label: string;
    token_last4: string;
    scopes: string[];
    expires_at: string | null;
    revoked_at: string | null;
    last_used_at: string | null;
    created_at: string;
}
export interface CreatedMcpToken { data: McpTenantTokenRow; plain_token: string }
const headers = (tenant: string | null) => tenant ? { headers: { 'X-Tenant-Id': tenant } } : undefined;
export const mcpTokensApi = {
    async list(tenant: string | null): Promise<McpTenantTokenRow[]> {
        const { data } = await api.get<{ data: McpTenantTokenRow[] }>('/api/admin/mcp/tokens', headers(tenant));
        return data.data;
    },
    async create(tenant: string | null, values: { label: string; expires_at: string | null }): Promise<CreatedMcpToken> {
        const { data } = await api.post<CreatedMcpToken>('/api/admin/mcp/tokens', values, headers(tenant));
        return data;
    },
    async revoke(tenant: string | null, id: number): Promise<void> {
        await api.post(`/api/admin/mcp/tokens/${id}/revoke`, undefined, headers(tenant));
    },
};
