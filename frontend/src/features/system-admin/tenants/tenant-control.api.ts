import { api } from '../../../lib/api';

export type TenantStatus = 'active' | 'suspended' | 'archived';

export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface PlatformTenant {
    slug: string;
    name: string;
    hash: string;
    status: TenantStatus;
    project_count: number;
    member_count: number;
    created_at?: string | null;
    updated_at?: string | null;
}

export interface TenantMembershipAccess {
    id: number;
    project_key: string;
    role: string;
    scope: Record<string, unknown> | unknown[];
}

export interface TenantUserAccess {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    deleted_at: string | null;
    roles: string[];
    permissions: string[];
    all_projects: boolean;
    memberships: TenantMembershipAccess[];
}

export interface TenantDetail {
    tenant: PlatformTenant;
    users: {
        data: TenantUserAccess[];
        meta: PageMeta;
    };
}

export interface TenantAvailability {
    tenant: {
        slug: string;
        available: boolean;
    };
    user: {
        status: 'new' | 'existing' | 'inactive' | 'deleted';
        email: string;
        id: number | null;
        name: string | null;
        roles: string[];
        effective_role: 'super-admin' | 'admin' | 'editor' | 'viewer' | null;
        role_compatible: boolean;
    };
    can_provision: boolean;
}

export interface ProvisionTenantPayload {
    tenant_name: string;
    tenant_slug?: string;
    user_email: string;
    user_name?: string;
    password?: string;
    role: 'super-admin' | 'admin' | 'editor' | 'viewer';
    attach_existing: boolean;
    project_key?: string;
}

export interface ProvisionTenantResult {
    tenant: PlatformTenant;
    project: {
        project_key: string;
        name: string;
        membership_role: string;
    };
    user: {
        id: number;
        name: string;
        email: string;
        is_active: boolean;
        roles: string[];
    };
    attached_existing: boolean;
    registry_created: boolean;
}

export const tenantControlApi = {
    async list(params: {
        search?: string;
        status?: TenantStatus | '';
        page?: number;
        per_page?: number;
    }): Promise<{ data: PlatformTenant[]; meta: PageMeta }> {
        const { data } = await api.get<{ data: PlatformTenant[]; meta: PageMeta }>(
            '/api/system-admin/tenants',
            { params },
        );
        return data;
    },

    async availability(params: {
        tenant_name: string;
        tenant_slug?: string;
        user_email: string;
        role: ProvisionTenantPayload['role'];
    }): Promise<TenantAvailability> {
        const { data } = await api.post<{ data: TenantAvailability }>(
            '/api/system-admin/tenants/availability',
            params,
        );
        return data.data;
    },

    async provision(payload: ProvisionTenantPayload): Promise<ProvisionTenantResult> {
        const { data } = await api.post<{ data: ProvisionTenantResult }>(
            '/api/system-admin/tenants',
            payload,
        );
        return data.data;
    },

    async detail(slug: string, page = 1): Promise<TenantDetail> {
        const { data } = await api.get<{ data: TenantDetail }>(
            `/api/system-admin/tenants/${encodeURIComponent(slug)}`,
            { params: { page, per_page: 25 } },
        );
        return data.data;
    },

    async update(
        slug: string,
        payload: { name?: string; status?: TenantStatus; confirm_token?: string },
    ): Promise<PlatformTenant> {
        const { data } = await api.patch<{ data: PlatformTenant }>(
            `/api/system-admin/tenants/${encodeURIComponent(slug)}`,
            payload,
        );
        return data.data;
    },

    async lifecyclePreview(
        slug: string,
        status: TenantStatus,
    ): Promise<{
        tenant: { slug: string; name: string; project_count: number; member_count: number };
        transition: { from: TenantStatus; to: TenantStatus };
        confirm_token: string;
        confirm_token_expires_at: string;
    }> {
        const { data } = await api.post<{
            data: {
                tenant: { slug: string; name: string; project_count: number; member_count: number };
                transition: { from: TenantStatus; to: TenantStatus };
                confirm_token: string;
                confirm_token_expires_at: string;
            };
        }>(
            `/api/system-admin/tenants/${encodeURIComponent(slug)}/lifecycle-preview`,
            { status },
        );
        return data.data;
    },
};
