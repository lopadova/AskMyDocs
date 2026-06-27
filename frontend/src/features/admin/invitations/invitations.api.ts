import { api } from '../../../lib/api';

/*
 * Native Invitations admin — typed client over the core
 * `padosoft/laravel-invitations` API (`/api/admin/invitations/*`, the routes
 * PR #363 mounted). Thin wrappers around the shared SPA axios client (carries
 * the stateful-Sanctum contract + the X-Tenant-Id interceptor → tenant scoping
 * is automatic, R30). Every endpoint uses the standard `{ data: ... }`
 * envelope and is unwrapped here.
 *
 * This file ONLY consumes the existing tri-surface (R44) — no new backend
 * capability is introduced. The read surfaces are capped server-side at 500
 * rows with no pagination primitive; callers detect the cap by row count
 * (R3/R14) — see READ_ROW_CAP.
 */

/** Server-side hard cap on every read surface (no pagination yet). */
export const READ_ROW_CAP = 500;

// ── Enums (mirror the package's canonical lowercase identifiers) ────────────
export type CampaignType = 'single_use' | 'multi_use' | 'capacity' | 'referral' | 'waitlist_skip';
export type CampaignStatus = 'draft' | 'active' | 'paused' | 'ended';
export type CodeKind = 'random' | 'vanity' | 'signed';
export type CodeState = 'active' | 'redeemed' | 'exhausted' | 'expired' | 'revoked';
export type ReferralStatus = 'pending' | 'qualified' | 'rewarded' | 'reversed';
export type RewardState = 'pending' | 'granted' | 'reversed' | 'expired';
export type RewardParty = 'referrer' | 'referee';
export type WaitlistStatus = 'waiting' | 'invited' | 'converted' | 'removed';
export type AbuseSeverity = 'info' | 'warn' | 'block';
export type AbuseAction = 'none' | 'flag' | 'throttle' | 'block';
export type ProjectRole = 'member' | 'admin' | 'owner';
export type InviteChannel = 'email' | 'sms' | 'in_app' | 'link';

export const CAMPAIGN_TYPES: CampaignType[] = ['single_use', 'multi_use', 'capacity', 'referral', 'waitlist_skip'];
export const CAMPAIGN_STATUSES: CampaignStatus[] = ['draft', 'active', 'paused', 'ended'];

// ── DTOs (exact shape of the package's JsonResource payloads) ───────────────
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

/**
 * Provisioning grant applied to a redeemer on a fresh claim. `role` must be a
 * real Spatie role and never `super-admin` (the package rejects it). `projects`
 * are free-form KB project keys. `tenants` carries one OR MORE per-tenant
 * grants so a single code provisions across several tenants at once.
 */
export interface CampaignTenantGrant {
    tenant_id: string;
    role?: string | null;
    projects?: string[];
    project_role?: ProjectRole | null;
    scope_allowlist?: string[];
}

export interface CampaignGrant {
    role?: string | null;
    projects?: string[];
    project_role?: ProjectRole | null;
    scope_allowlist?: string[];
    tenants?: CampaignTenantGrant[];
}

export interface Campaign {
    id: number;
    key: string;
    name: string;
    description?: string | null;
    type: CampaignType;
    status: CampaignStatus;
    max_redemptions_total?: number | null;
    per_user_limit?: number | null;
    starts_at?: string | null;
    ends_at?: string | null;
    grant?: CampaignGrant | null;
    created_at?: string;
    updated_at?: string;
}

export interface Tenant {
    id: string;
    name: string;
}

export interface InviteCode {
    id: number;
    campaign_id: number | null;
    code: string;
    code_kind: CodeKind;
    state: CodeState;
    max_uses: number;
    current_uses: number;
    issuer_id?: number;
    expires_at: string | null;
    created_at?: string;
}

export interface Referral {
    id: number;
    referrer_id: number;
    referee_id: number;
    code_id: number;
    redemption_id?: number | null;
    campaign_id: number | null;
    status: ReferralStatus;
    depth: number;
    attributed_at: string | null;
    qualified_at: string | null;
    created_at?: string;
    updated_at?: string;
}

export interface Reward {
    id: number;
    referral_id: number;
    redemption_id: number | null;
    beneficiary_id: number;
    party: RewardParty;
    type: string;
    amount: number;
    unit: string;
    trigger: string;
    state: RewardState;
    granted_at: string | null;
    reversed_at: string | null;
    created_at?: string;
    updated_at?: string;
}

export interface WaitlistEntry {
    id: number;
    email: string;
    position: number;
    priority: number;
    referral_count: number;
    granted_code_id: number | null;
    status: WaitlistStatus;
    invited_at: string | null;
    converted_at: string | null;
    created_at?: string;
    updated_at?: string;
}

export interface AbuseSignal {
    id: number;
    subject_type: string;
    signal_type: string;
    severity: AbuseSeverity;
    score: number;
    action_taken: AbuseAction;
    created_at?: string;
}

// ── Filter / payload shapes ─────────────────────────────────────────────────
export interface MetricsFilter {
    campaign_id?: number | null;
    since_days?: number | null;
}
export interface CodesFilter {
    campaign_id?: number | null;
    state?: CodeState | null;
}
export interface GenerateCodesPayload {
    campaign_id?: number | null;
    count: number;
    max_uses?: number | null;
    length?: number | null;
    expires_at?: string | null;
}
export interface ReferralsFilter {
    campaign_id?: number | null;
    status?: ReferralStatus | null;
}
export interface RewardsFilter {
    state?: RewardState | null;
    party?: RewardParty | null;
}
export interface WaitlistFilter {
    status?: WaitlistStatus | null;
}
export interface AbuseFilter {
    severity?: AbuseSeverity | null;
    action?: AbuseAction | null;
}

export interface CreateCampaignPayload {
    key: string;
    name: string;
    description?: string | null;
    type: CampaignType;
    status?: CampaignStatus | null;
    max_redemptions_total?: number | null;
    per_user_limit?: number | null;
    starts_at?: string | null;
    ends_at?: string | null;
    grant?: CampaignGrant | null;
}

/** Update omits the immutable `key` + `type`. */
export interface UpdateCampaignPayload {
    name?: string;
    description?: string | null;
    status?: CampaignStatus;
    max_redemptions_total?: number | null;
    per_user_limit?: number | null;
    starts_at?: string | null;
    ends_at?: string | null;
    grant?: CampaignGrant | null;
}

export interface SendInvitationPayload {
    recipient: string;
    channel?: InviteChannel | null;
    context_ref?: string | null;
    role?: string | null;
    code_id?: number | null;
}

export interface SentInvitation {
    id: number;
    recipient: string;
    status: string;
    channel: string;
    expires_at: string | null;
}

/** Build a `?a=b&c=d` query string, dropping null/undefined/empty values. */
function qs(params: Record<string, string | number | null | undefined>): string {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === undefined || value === '') continue;
        search.append(key, String(value));
    }
    const out = search.toString();
    return out ? `?${out}` : '';
}

const BASE = '/api/admin/invitations';

export const invitationsApi = {
    async getMetrics(filter: MetricsFilter = {}): Promise<InviteMetrics> {
        const { data } = await api.get<{ data: InviteMetrics }>(
            `${BASE}/metrics${qs({ campaign_id: filter.campaign_id, since_days: filter.since_days })}`,
        );
        return data.data;
    },

    async listCampaigns(): Promise<Campaign[]> {
        const { data } = await api.get<{ data: Campaign[] }>(`${BASE}/campaigns`);
        return data.data;
    },

    async listTenants(): Promise<Tenant[]> {
        const { data } = await api.get<{ data: Tenant[] }>(`${BASE}/tenants`);
        return data.data;
    },

    async createCampaign(payload: CreateCampaignPayload): Promise<Campaign> {
        const { data } = await api.post<{ data: Campaign }>(`${BASE}/campaigns`, payload);
        return data.data;
    },

    async updateCampaign(id: number, payload: UpdateCampaignPayload): Promise<Campaign> {
        const { data } = await api.patch<{ data: Campaign }>(`${BASE}/campaigns/${id}`, payload);
        return data.data;
    },

    async sendInvitation(payload: SendInvitationPayload): Promise<SentInvitation> {
        const { data } = await api.post<{ data: SentInvitation }>(`${BASE}/invitations`, payload);
        return data.data;
    },

    async listCodes(filter: CodesFilter = {}): Promise<InviteCode[]> {
        const { data } = await api.get<{ data: InviteCode[] }>(
            `${BASE}/codes${qs({ campaign_id: filter.campaign_id, state: filter.state })}`,
        );
        return data.data;
    },

    async generateCodes(payload: GenerateCodesPayload): Promise<InviteCode[]> {
        const { data } = await api.post<{ data: InviteCode[] }>(`${BASE}/codes`, payload);
        return data.data;
    },

    async revokeCode(id: number): Promise<InviteCode> {
        const { data } = await api.post<{ data: InviteCode }>(`${BASE}/codes/${id}/revoke`, {});
        return data.data;
    },

    async listReferrals(filter: ReferralsFilter = {}): Promise<Referral[]> {
        const { data } = await api.get<{ data: Referral[] }>(
            `${BASE}/referrals${qs({ campaign_id: filter.campaign_id, status: filter.status })}`,
        );
        return data.data;
    },

    async listRewards(filter: RewardsFilter = {}): Promise<Reward[]> {
        const { data } = await api.get<{ data: Reward[] }>(
            `${BASE}/rewards${qs({ state: filter.state, party: filter.party })}`,
        );
        return data.data;
    },

    async listWaitlist(filter: WaitlistFilter = {}): Promise<WaitlistEntry[]> {
        const { data } = await api.get<{ data: WaitlistEntry[] }>(
            `${BASE}/waitlist${qs({ status: filter.status })}`,
        );
        return data.data;
    },

    async listAbuseSignals(filter: AbuseFilter = {}): Promise<AbuseSignal[]> {
        const { data } = await api.get<{ data: AbuseSignal[] }>(
            `${BASE}/abuse-signals${qs({ severity: filter.severity, action: filter.action })}`,
        );
        return data.data;
    },
};
