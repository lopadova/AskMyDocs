import { api } from '../../../lib/api';

/**
 * v8.7/W1 — admin synonyms CRUD client. Wraps `/api/admin/kb/synonyms`
 * with typed methods. Each call honours the standard `{ data: ... }`
 * envelope used across the admin endpoints.
 */

export interface AdminSynonym {
    id: number;
    project_key: string;
    term: string;
    synonyms: string[];
    enabled: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface CreateSynonymPayload {
    project_key: string;
    term: string;
    synonyms: string[];
    enabled?: boolean;
}

export interface UpdateSynonymPayload {
    term?: string;
    synonyms?: string[];
    enabled?: boolean;
}

export const adminSynonymsApi = {
    async list(projectKeys?: string[]): Promise<AdminSynonym[]> {
        const params = new URLSearchParams();
        for (const k of projectKeys ?? []) {
            params.append('project_keys[]', k);
        }
        const url = '/api/admin/kb/synonyms' + (params.toString() ? `?${params.toString()}` : '');
        const { data } = await api.get<{ data: AdminSynonym[] }>(url);
        return data.data;
    },

    async create(payload: CreateSynonymPayload): Promise<AdminSynonym> {
        const { data } = await api.post<{ data: AdminSynonym }>('/api/admin/kb/synonyms', payload);
        return data.data;
    },

    async update(id: number, payload: UpdateSynonymPayload): Promise<AdminSynonym> {
        const { data } = await api.put<{ data: AdminSynonym }>(`/api/admin/kb/synonyms/${id}`, payload);
        return data.data;
    },

    async delete(id: number): Promise<void> {
        await api.delete(`/api/admin/kb/synonyms/${id}`);
    },
};

/**
 * Normalize a single term/synonym token the SAME way the backend does
 * (lowercase + trim + collapse internal whitespace). Shared so the FE's
 * distinct-check and the persisted `term` agree with the server and the
 * client never thinks a value is "distinct"/"unchanged" only to be 422'd
 * after the backend collapses whitespace.
 */
export function normalizeToken(raw: string): string {
    return raw.trim().toLowerCase().replace(/\s+/g, ' ');
}

/**
 * Parse a free-text synonyms field (comma- or newline-separated) into a
 * de-duplicated, normalized list. Shared by the form dialog and its unit
 * test so the two never drift.
 */
export function parseSynonyms(raw: string): string[] {
    const seen = new Set<string>();
    const out: string[] = [];
    for (const piece of raw.split(/[\n,]/)) {
        const value = normalizeToken(piece);
        if (value !== '' && !seen.has(value)) {
            seen.add(value);
            out.push(value);
        }
    }
    return out;
}
