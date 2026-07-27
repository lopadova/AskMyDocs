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
    };
    can_provision: boolean;
}

export interface ProvisionTenantPayload {
    tenant_name: string;
    tenant_slug?: string;
    user_email: string;
    user_name?: string;
    password?: string;
    role: 'admin' | 'editor' | 'viewer';
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
            '/api/super-admin/tenants',
            { params },
        );
        return data;
    },

    async availability(params: {
        tenant_name: string;
        tenant_slug?: string;
        user_email: string;
    }): Promise<TenantAvailability> {
        const { data } = await api.get<{ data: TenantAvailability }>(
            '/api/super-admin/tenants/availability',
            { params },
        );
        return data.data;
    },

    async provision(payload: ProvisionTenantPayload): Promise<ProvisionTenantResult> {
        const { data } = await api.post<{ data: ProvisionTenantResult }>(
            '/api/super-admin/tenants',
            payload,
        );
        return data.data;
    },

    async detail(slug: string, page = 1): Promise<TenantDetail> {
        const { data } = await api.get<{ data: TenantDetail }>(
            `/api/super-admin/tenants/${encodeURIComponent(slug)}`,
            { params: { page, per_page: 25 } },
        );
        return data.data;
    },

    async update(
        slug: string,
        payload: { name?: string; status?: TenantStatus },
    ): Promise<PlatformTenant> {
        const { data } = await api.patch<{ data: PlatformTenant }>(
            `/api/super-admin/tenants/${encodeURIComponent(slug)}`,
            payload,
        );
        return data.data;
    },
};
