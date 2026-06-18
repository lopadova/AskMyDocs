import { api } from '../../../lib/api';

/**
 * API client for the invite admin surface. Thin wrapper over the same
 * /api/admin/invite/* endpoints the PHP + MCP surfaces use (R44). The session
 * cookie carries the tenant (server-side TenantContext); no X-Tenant-Id here.
 */

export type CampaignType = 'single_use' | 'multi_use' | 'capacity' | 'referral' | 'waitlist_skip';
export type CampaignStatus = 'draft' | 'active' | 'paused' | 'ended';
export type CodeState = 'active' | 'redeemed' | 'exhausted' | 'expired' | 'revoked';

export interface InviteCampaign {
    id: number;
    key: string;
    name: string;
    description: string | null;
    type: CampaignType;
    status: CampaignStatus;
    max_redemptions_total: number | null;
    per_user_limit: number;
    starts_at: string | null;
    ends_at: string | null;
    reward_policy: Record<string, unknown> | null;
    created_by: number;
    created_at?: string;
    updated_at?: string;
}

export interface InviteCode {
    id: number;
    campaign_id: number | null;
    code: string;
    code_kind: 'random' | 'vanity' | 'signed';
    state: CodeState;
    max_uses: number;
    current_uses: number;
    issuer_id: number | null;
    expires_at: string | null;
    created_at?: string;
}

export interface InviteMetrics {
    codes_issued: number;
    redemptions: number;
    invites_sent: number;
    invites_accepted: number;
    referrals_qualified: number;
    distinct_referrers: number;
    k_factor: number;
    acceptance_rate: number;
    conversion_rate: number;
    ttr_p50_seconds: number | null;
    ttr_p90_seconds: number | null;
}

export interface CreateCampaignPayload {
    key: string;
    name: string;
    description?: string | null;
    type: CampaignType;
    status?: CampaignStatus;
    max_redemptions_total?: number | null;
    per_user_limit?: number;
}

export interface UpdateCampaignPayload {
    name?: string;
    description?: string | null;
    status?: CampaignStatus;
    max_redemptions_total?: number | null;
    per_user_limit?: number;
}

export interface GenerateCodesPayload {
    campaign_id?: number | null;
    count: number;
    max_uses?: number | null;
    length?: number | null;
    expires_at?: string | null;
}

export const adminInviteApi = {
    // ─── Campaigns ───────────────────────────────────────────────
    async listCampaigns(): Promise<InviteCampaign[]> {
        const { data } = await api.get<{ data: InviteCampaign[] }>('/api/admin/invite/campaigns');
        return data.data;
    },

    async createCampaign(payload: CreateCampaignPayload): Promise<InviteCampaign> {
        const { data } = await api.post<{ data: InviteCampaign }>('/api/admin/invite/campaigns', payload);
        return data.data;
    },

    async updateCampaign(id: number, payload: UpdateCampaignPayload): Promise<InviteCampaign> {
        const { data } = await api.patch<{ data: InviteCampaign }>(`/api/admin/invite/campaigns/${id}`, payload);
        return data.data;
    },

    // ─── Codes ───────────────────────────────────────────────────
    async listCodes(filter?: { campaign_id?: number | null; state?: CodeState | '' }): Promise<InviteCode[]> {
        const params = new URLSearchParams();
        if (filter?.campaign_id != null) params.set('campaign_id', String(filter.campaign_id));
        if (filter?.state) params.set('state', filter.state);
        const url = '/api/admin/invite/codes' + (params.toString() ? `?${params.toString()}` : '');
        const { data } = await api.get<{ data: InviteCode[] }>(url);
        return data.data;
    },

    async generateCodes(payload: GenerateCodesPayload): Promise<InviteCode[]> {
        const { data } = await api.post<{ data: InviteCode[] }>('/api/admin/invite/codes', payload);
        return data.data;
    },

    async revokeCode(id: number): Promise<InviteCode> {
        const { data } = await api.post<{ data: InviteCode }>(`/api/admin/invite/codes/${id}/revoke`);
        return data.data;
    },

    // ─── Metrics ─────────────────────────────────────────────────
    async metrics(filter?: { campaign_id?: number | null; since_days?: number | null }): Promise<InviteMetrics> {
        const params = new URLSearchParams();
        if (filter?.campaign_id != null) params.set('campaign_id', String(filter.campaign_id));
        if (filter?.since_days != null) params.set('since_days', String(filter.since_days));
        const url = '/api/admin/invite/metrics' + (params.toString() ? `?${params.toString()}` : '');
        const { data } = await api.get<{ data: InviteMetrics }>(url);
        return data.data;
    },

    // ─── Invitations ─────────────────────────────────────────────
    async sendInvitation(payload: { recipient: string; channel?: string; context_ref?: string | null; role?: string | null }): Promise<{
        id: number;
        recipient: string;
        status: string;
        channel: string;
        expires_at: string | null;
    }> {
        const { data } = await api.post<{
            data: { id: number; recipient: string; status: string; channel: string; expires_at: string | null };
        }>('/api/admin/invite/invitations', payload);
        return data.data;
    },
};
