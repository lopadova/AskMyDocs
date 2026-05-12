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
    async list(scope: 'mine' | 'shared' | 'system' = 'mine'): Promise<Workflow[]> {
        const { data } = await api.get<{ data: Workflow[] }>(`/api/admin/workflows?scope=${scope}`);
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
        const { data } = await api.post<{ data: Workflow }>(`/api/admin/workflows/from-proposal`, proposal);
        return data.data;
    },

    async hide(id: number): Promise<void> {
        await api.post(`/api/admin/workflows/${id}/hide`);
    },
};
