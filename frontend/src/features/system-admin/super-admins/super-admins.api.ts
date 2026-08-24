import { api } from '../../../lib/api';

export type SuperAdminStatus = 'active' | 'inactive' | 'deleted';

export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface GlobalSuperAdmin {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    deleted_at: string | null;
    is_system_admin: boolean;
    tenant_count: number;
}

export interface SuperAdminTenant {
    slug: string;
    hash: string;
    name: string;
    status: string;
    project_count: number;
}

export interface SuperAdminTenantsPage {
    user: Omit<GlobalSuperAdmin, 'tenant_count'>;
    data: SuperAdminTenant[];
    meta: PageMeta;
}

export const superAdminsApi = {
    async list(params: {
        search?: string;
        status?: SuperAdminStatus | '';
        page?: number;
        per_page?: number;
    }): Promise<{ data: GlobalSuperAdmin[]; meta: PageMeta }> {
        const { data } = await api.get<{ data: GlobalSuperAdmin[]; meta: PageMeta }>(
            '/api/system-admin/super-admins',
            { params },
        );
        return data;
    },

    async tenants(userId: number, page = 1): Promise<SuperAdminTenantsPage> {
        const { data } = await api.get<SuperAdminTenantsPage>(
            `/api/system-admin/super-admins/${userId}/tenants`,
            { params: { page, per_page: 25 } },
        );
        return data;
    },
};
