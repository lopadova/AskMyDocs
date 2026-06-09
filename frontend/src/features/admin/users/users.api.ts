import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    adminUsersApi,
    type AdminMembership,
    type AdminMembershipInput,
    type AdminPaginated,
    type AdminUser,
    type AdminUserInput,
    type AdminUsersQuery,
} from '../admin.api';

/*
 * TanStack Query hooks over the /api/admin/users/* + /api/admin/memberships
 * endpoints. Mirrors dashboard/use-admin-metrics.ts — every mutation
 * invalidates the `['admin','users']` partition so filters + drawer
 * contents refresh together.
 *
 * No optimistic updates: admin ops are low frequency, consistency is
 * more important than latency.
 */

export const USERS_KEY = ['admin', 'users'] as const;

export function useUsers(q: AdminUsersQuery = {}) {
    return useQuery<AdminPaginated<AdminUser>>({
        queryKey: [...USERS_KEY, 'list', q],
        queryFn: () => adminUsersApi.list(q),
        staleTime: 15_000,
    });
}

export function useUser(id: number | null) {
    return useQuery<AdminUser>({
        queryKey: [...USERS_KEY, 'show', id],
        queryFn: () => adminUsersApi.show(id as number),
        enabled: typeof id === 'number',
        staleTime: 15_000,
    });
}

export function useCreateUser() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: AdminUserInput) => adminUsersApi.create(input),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: USERS_KEY });
        },
    });
}

export function useUpdateUser() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (v: { id: number; input: Partial<AdminUserInput> }) =>
            adminUsersApi.update(v.id, v.input),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: USERS_KEY });
        },
    });
}

export function useDeleteUser() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => adminUsersApi.destroy(id, false),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: USERS_KEY });
        },
    });
}

export function useForceDeleteUser() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => adminUsersApi.destroy(id, true),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: USERS_KEY });
        },
    });
}

export function useRestoreUser() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => adminUsersApi.restore(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: USERS_KEY });
        },
    });
}

export function useToggleActive() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (v: { id: number; nextActive?: boolean }) =>
            adminUsersApi.toggleActive(v.id, v.nextActive),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: USERS_KEY });
        },
    });
}

export function useResendInvite() {
    return useMutation({
        mutationFn: (id: number) => adminUsersApi.resendInvite(id),
    });
}

export function useUserMemberships(userId: number | null) {
    return useQuery<AdminPaginated<AdminMembership>>({
        queryKey: [...USERS_KEY, 'memberships', userId],
        queryFn: () => adminUsersApi.listMemberships(userId as number),
        enabled: typeof userId === 'number',
        staleTime: 15_000,
    });
}

export function useUpsertMembership(userId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (input: AdminMembershipInput) =>
            adminUsersApi.upsertMembership(userId, input),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: [...USERS_KEY, 'memberships', userId] });
        },
    });
}

export function useUpdateMembership(userId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (v: {
            id: number;
            input: Partial<Omit<AdminMembershipInput, 'project_key'>>;
        }) => adminUsersApi.updateMembership(v.id, v.input),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: [...USERS_KEY, 'memberships', userId] });
        },
    });
}

export function useDeleteMembership(userId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => adminUsersApi.deleteMembership(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: [...USERS_KEY, 'memberships', userId] });
        },
    });
}
