import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    adminPermissionsApi,
    adminRolesApi,
    type AdminPaginated,
    type AdminPermissionCatalogue,
    type AdminRole,
    type AdminRoleInput,
} from '../admin.api';

/*
 * TanStack Query hooks over /api/admin/roles + /api/admin/permissions.
 * Permission catalogue is cached aggressively (10 min) because the
 * permission set is static inside a single deploy.
 */

export const ROLES_KEY = ['admin', 'roles'] as const;
export const PERMISSIONS_KEY = ['admin', 'permissions'] as const;

export function useRoles() {
    return useQuery<AdminPaginated<AdminRole>>({
        queryKey: [...ROLES_KEY, 'list'],
        queryFn: () => adminRolesApi.list(100),
        staleTime: 30_000,
    });
}

export function useRole(id: number | null) {
    return useQuery<AdminRole>({
        queryKey: [...ROLES_KEY, 'show', id],
        queryFn: () => adminRolesApi.show(id as number),
        enabled: typeof id === 'number',
    });
}

export function useCreateRole() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: AdminRoleInput) => adminRolesApi.create(input),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ROLES_KEY });
        },
    });
}

export function useUpdateRole() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (v: { id: number; input: Partial<AdminRoleInput> }) =>
            adminRolesApi.update(v.id, v.input),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ROLES_KEY });
        },
    });
}

export function useDeleteRole() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => adminRolesApi.destroy(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ROLES_KEY });
        },
    });
}

export function usePermissionCatalogue() {
    return useQuery<AdminPermissionCatalogue>({
        queryKey: [...PERMISSIONS_KEY, 'catalogue'],
        queryFn: () => adminPermissionsApi.catalogue(),
        staleTime: 10 * 60_000,
    });
}
