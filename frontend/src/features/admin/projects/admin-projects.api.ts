import { api } from '../../../lib/api';

/**
 * v8.9 — admin projects CRUD client. Wraps `/api/admin/projects` with
 * typed methods. Each call honours the standard `{ data: ... }` envelope
 * used across the admin endpoints. The list is scoped to the active team
 * server-side (X-Tenant-Id header from the team switcher).
 */

export interface AdminProject {
    id: number;
    project_key: string;
    name: string;
    description: string | null;
    document_count: number;
    member_count: number;
    created_at?: string;
    updated_at?: string;
}

export interface CreateProjectPayload {
    name: string;
    /** Optional — the BE slugs it from `name` when omitted. */
    project_key?: string;
    description?: string | null;
}

export interface UpdateProjectPayload {
    name?: string;
    description?: string | null;
}

export const adminProjectsApi = {
    async list(): Promise<AdminProject[]> {
        const { data } = await api.get<{ data: AdminProject[] }>('/api/admin/projects');
        return data.data;
    },

    async create(payload: CreateProjectPayload): Promise<AdminProject> {
        const { data } = await api.post<{ data: AdminProject }>('/api/admin/projects', payload);
        return data.data;
    },

    async update(id: number, payload: UpdateProjectPayload): Promise<AdminProject> {
        const { data } = await api.patch<{ data: AdminProject }>(`/api/admin/projects/${id}`, payload);
        return data.data;
    },

    async delete(id: number): Promise<void> {
        await api.delete(`/api/admin/projects/${id}`);
    },
};
