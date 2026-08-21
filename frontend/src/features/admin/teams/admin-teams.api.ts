import { api } from '../../../lib/api';

/**
 * v8.28 — admin teams (= tenants) client. Wraps `/api/admin/teams` with
 * typed methods over the standard `{ data: ... }` envelope. A "team" is a
 * tenant; its editable display name lives on the vendor `tenants` row that
 * the topbar switcher reads. The list returns the teams the current user
 * may administer from their real memberships. The legacy `default` slug is
 * returned only when it has an explicit membership.
 */

export interface AdminTeam {
    /** The tenant_id / slug — the immutable identity of the team. */
    slug: string;
    name: string;
    /** Routing segment used by the SPA (/app/{hash}/…). Server-computed. */
    hash: string;
    /** active | suspended | archived | system (default). */
    status: string;
    /** The reserved bootstrap team — read-only, never renamable. */
    is_default: boolean;
    /** Whether the current user may rename this team. */
    can_manage: boolean;
    /** Authenticated URL; null until a logo has been uploaded. */
    logo_url?: string | null;
    project_count: number;
    member_count: number;
}

export interface CreateTeamPayload {
    name: string;
    /** Optional — the BE slugs it from `name` when omitted. */
    slug?: string;
}

export interface UpdateTeamPayload {
    name: string;
}

export const adminTeamsApi = {
    async list(): Promise<AdminTeam[]> {
        const { data } = await api.get<{ data: AdminTeam[] }>('/api/admin/teams');
        return data.data;
    },

    async create(payload: CreateTeamPayload): Promise<AdminTeam> {
        const { data } = await api.post<{ data: AdminTeam }>('/api/admin/teams', payload);
        return data.data;
    },

    async update(slug: string, payload: UpdateTeamPayload): Promise<AdminTeam> {
        const { data } = await api.patch<{ data: AdminTeam }>(
            `/api/admin/teams/${encodeURIComponent(slug)}`,
            payload,
        );
        return data.data;
    },

    async uploadLogo(slug: string, logo: File): Promise<{ logo_url: string }> {
        const form = new FormData();
        form.append('logo', logo);
        const { data } = await api.post<{ data: { logo_url: string } }>(
            `/api/admin/teams/${encodeURIComponent(slug)}/logo`,
            form,
        );
        return data.data;
    },

    async deleteLogo(slug: string): Promise<void> {
        await api.delete(`/api/admin/teams/${encodeURIComponent(slug)}/logo`);
    },
};
