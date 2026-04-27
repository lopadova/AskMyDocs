import { api } from '../../../lib/api';

/**
 * T2.10 — admin tags CRUD client. Wraps `/api/admin/kb/tags` with
 * typed methods. Each call honours the standard `{ data: ... }` envelope
 * used across v3.0 admin endpoints.
 */

export interface AdminTag {
    id: number;
    project_key: string;
    slug: string;
    label: string;
    color: string | null;
    created_at?: string;
    updated_at?: string;
}

export interface CreateTagPayload {
    project_key: string;
    slug: string;
    label: string;
    color?: string | null;
}

export interface UpdateTagPayload {
    slug?: string;
    label?: string;
    color?: string | null;
}

export const adminTagsApi = {
    async list(projectKeys?: string[]): Promise<AdminTag[]> {
        const params = new URLSearchParams();
        for (const k of projectKeys ?? []) {
            params.append('project_keys[]', k);
        }
        const url = '/api/admin/kb/tags' + (params.toString() ? `?${params.toString()}` : '');
        const { data } = await api.get<{ data: AdminTag[] }>(url);
        return data.data;
    },

    async create(payload: CreateTagPayload): Promise<AdminTag> {
        const { data } = await api.post<{ data: AdminTag }>('/api/admin/kb/tags', payload);
        return data.data;
    },

    async update(id: number, payload: UpdateTagPayload): Promise<AdminTag> {
        const { data } = await api.put<{ data: AdminTag }>(`/api/admin/kb/tags/${id}`, payload);
        return data.data;
    },

    async delete(id: number): Promise<void> {
        await api.delete(`/api/admin/kb/tags/${id}`);
    },
};
