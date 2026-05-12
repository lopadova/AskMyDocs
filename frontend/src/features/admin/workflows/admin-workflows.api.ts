import { api } from '../../../lib/api';

/**
 * v4.7/W3 — Admin Workflows API client.
 *
 * Wraps `/api/admin/workflows/*` (W2 backend). Standard `{ data: ... }`
 * envelope.
 */

export interface Workflow {
    id: number;
    user_id: number | null;
    title: string;
    type: 'assistant' | 'tabular';
    prompt_md?: string | null;
    columns_config?: unknown[];
    practice?: string | null;
    project_key?: string | null;
    created_at?: string;
    updated_at?: string;
    is_system?: boolean;
}

export interface CreateWorkflowPayload {
    title: string;
    type: 'assistant' | 'tabular';
    prompt_md?: string;
    columns_config?: unknown[];
    practice?: string;
    project_key?: string;
}

export interface WorkflowProposal {
    title: string;
    type: 'assistant' | 'tabular';
    rationale?: string;
    prompt_md?: string;
    columns_config?: unknown[];
    practice?: string;
}

export const adminWorkflowsApi = {
    /**
     * The BE accepts `include_shared` and `include_hidden` flags
     * (NOT a free-form `scope` param). Tabs map to those flags:
     *   - `mine`   → include_shared=false, include_hidden=false
     *   - `shared` → include_shared=true,  include_hidden=false (caller filters)
     *   - `system` → include_shared=true,  include_hidden=false (caller filters)
     * Mine / Shared / System split is done client-side on the
     * returned rows (`is_system` + `user_id === me`).
     */
    async list(scope: 'mine' | 'shared' | 'system' = 'mine'): Promise<Workflow[]> {
        const params = new URLSearchParams();
        if (scope === 'mine') {
            params.set('include_shared', '0');
        } else {
            params.set('include_shared', '1');
        }
        // Copilot iter 6 — the list endpoint is paginated (default
        // per_page=25, max 100). v4.7 GA ships with per_page=100 so the
        // 15 built-in templates plus a reasonable amount of tenant-
        // created workflows fit on a single page; full pagination
        // controls land in v4.7.x polish.
        params.set('per_page', '100');
        const { data } = await api.get<{ data: Workflow[] }>(
            `/api/admin/workflows?${params.toString()}`
        );
        return data.data;
    },

    async show(id: number): Promise<Workflow> {
        const { data } = await api.get<{ data: Workflow }>(`/api/admin/workflows/${id}`);
        return data.data;
    },

    async create(payload: CreateWorkflowPayload): Promise<Workflow> {
        const { data } = await api.post<{ data: Workflow }>(`/api/admin/workflows`, payload);
        return data.data;
    },

    async destroy(id: number): Promise<void> {
        await api.delete(`/api/admin/workflows/${id}`);
    },

    async suggest(): Promise<WorkflowProposal[]> {
        const { data } = await api.post<{ data: WorkflowProposal[] }>(`/api/admin/workflows/suggest`, {});
        return data.data;
    },

    async fromProposal(proposal: WorkflowProposal): Promise<Workflow> {
        // Copilot iter 6 — `FromProposalRequest` validates `proposal.*`
        // (nested under a `proposal` key). Posting the proposal at the
        // root of the body 422s in production. Wrap the payload.
        const { data } = await api.post<{ data: Workflow }>(
            `/api/admin/workflows/from-proposal`,
            { proposal },
        );
        return data.data;
    },

    async hide(id: number): Promise<void> {
        await api.post(`/api/admin/workflows/${id}/hide`);
    },
};
