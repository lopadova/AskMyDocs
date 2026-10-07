import { api, ensureCsrfCookie } from '../../lib/api';

export type OAuthConsent = {
    client: { name: string; redirect_uri: string };
    scopes: { id: string; description: string }[];
    teams: { tenant_id: string; name: string }[];
    user: { name: string; email: string };
    token_ttl_days: number;
};

export type OAuthConnection = {
    id: number; name: string; tenant_id: string; scopes: string[];
    created_at: string; expires_at: string | null; last_used_at: string | null;
};

export async function getConsent(request: string): Promise<OAuthConsent> {
    return (await api.get<OAuthConsent>(`/api/oauth/authorization/${encodeURIComponent(request)}`)).data;
}

export async function decideConsent(request: string, decision: 'approve' | 'deny', tenantId: string) {
    await ensureCsrfCookie();
    return (await api.post<{ redirect_to: string }>(`/api/oauth/authorization/${encodeURIComponent(request)}`, {
        decision, ...(decision === 'approve' ? { tenant_id: tenantId } : {}),
    })).data;
}

export async function getConnections(): Promise<OAuthConnection[]> {
    return (await api.get<{ connections: OAuthConnection[] }>('/api/oauth/connections')).data.connections;
}

export async function disconnectConnection(id: number): Promise<void> {
    await ensureCsrfCookie();
    await api.delete(`/api/oauth/connections/${id}`);
}
