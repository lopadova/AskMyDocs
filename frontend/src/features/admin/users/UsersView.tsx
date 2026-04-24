import { useMemo, useState } from 'react';
import { Icon } from '../../../components/Icons';
import { AdminShell } from '../shell/AdminShell';
import { ToastHost, useToast } from '../shared/Toast';
import { toAdminError } from '../shared/errors';
import type { AdminUser } from '../admin.api';
import { useRoles } from '../roles/roles.api';
import { UsersTable } from './UsersTable';
import { UserDrawer, type DrawerMode } from './UserDrawer';
import {
    useDeleteUser,
    useRestoreUser,
    useToggleActive,
    useUsers,
} from './users.api';

/*
 * Admin Users page. Filter bar (search + role + active + with_trashed)
 * feeds the /api/admin/users query; the table below renders results
 * with inline actions + a drawer for edit / create.
 *
 * The `DemoSeeder` ships with `hr-portal` + `engineering` projects, so
 * membership editor options are statically derived for now — a project
 * picker backed by KB metadata can land in a follow-up once the
 * project-list endpoint exists.
 */

const DEFAULT_PROJECT_KEYS = ['hr-portal', 'engineering'];

export function UsersView() {
    const [q, setQ] = useState('');
    const [role, setRole] = useState<string>('');
    const [active, setActive] = useState<'all' | 'active' | 'inactive'>('all');
    const [withTrashed, setWithTrashed] = useState(false);

    const [drawerOpen, setDrawerOpen] = useState(false);
    const [drawerMode, setDrawerMode] = useState<DrawerMode>('create');
    const [drawerUser, setDrawerUser] = useState<AdminUser | null>(null);

    const toast = useToast();

    const usersQuery = useUsers({
        q: q || undefined,
        role: role || undefined,
        active: active === 'all' ? null : active === 'active',
        with_trashed: withTrashed || undefined,
        per_page: 50,
    });
    const rolesQuery = useRoles();

    const deleteUser = useDeleteUser();
    const restoreUser = useRestoreUser();
    const toggleActive = useToggleActive();

    const state: 'loading' | 'ready' | 'error' | 'empty' = usersQuery.isLoading
        ? 'loading'
        : usersQuery.isError
          ? 'error'
          : (usersQuery.data?.data.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    const users = usersQuery.data?.data ?? [];
    const roles = rolesQuery.data?.data ?? [];

    const uniqueRoleNames = useMemo(
        () => ['viewer', 'editor', 'admin', 'super-admin'],
        [],
    );

    function openCreate() {
        setDrawerMode('create');
        setDrawerUser(null);
        setDrawerOpen(true);
    }

    function openEdit(user: AdminUser) {
        setDrawerMode('edit');
        setDrawerUser(user);
        setDrawerOpen(true);
    }

    async function handleDelete(user: AdminUser) {
        try {
            await deleteUser.mutateAsync(user.id);
            toast.success(`Deleted ${user.email}`, 'toast-user-deleted');
        } catch (e) {
            const err = toAdminError(e);
            toast.error(err.message, 'toast-user-error');
        }
    }

    async function handleRestore(user: AdminUser) {
        try {
            await restoreUser.mutateAsync(user.id);
            toast.success(`Restored ${user.email}`, 'toast-user-restored');
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-user-error');
        }
    }

    async function handleToggleActive(user: AdminUser) {
        try {
            await toggleActive.mutateAsync({ id: user.id, nextActive: !user.is_active });
            toast.success(
                `${user.email} ${!user.is_active ? 'activated' : 'deactivated'}`,
                'toast-user-toggled',
            );
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-user-error');
        }
    }

    return (
        <AdminShell section="users">
            <ToastHost />
            <div
                data-testid="admin-users"
                data-state={state}
                style={{ display: 'flex', flexDirection: 'column', gap: 14 }}
            >
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        gap: 10,
                    }}
                >
                    <div>
                        <h1
                            style={{
                                fontSize: 20,
                                fontWeight: 600,
                                margin: '0 0 2px',
                                letterSpacing: '-0.02em',
                                color: 'var(--fg-0)',
                            }}
                        >
                            Users
                        </h1>
                        <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                            Manage accounts, roles and project memberships.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid="users-new"
                        onClick={openCreate}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6,
                            padding: '7px 12px',
                            fontSize: 13,
                            background: 'var(--grad-accent)',
                            color: '#fff',
                            border: '1px solid transparent',
                            borderRadius: 8,
                            cursor: 'pointer',
                        }}
                    >
                        <Icon.Plus size={14} /> New user
                    </button>
                </div>

                <div
                    data-testid="users-filter-bar"
                    style={{
                        display: 'flex',
                        gap: 10,
                        flexWrap: 'wrap',
                        alignItems: 'center',
                        padding: 10,
                        border: '1px solid var(--hairline)',
                        borderRadius: 10,
                        background: 'var(--bg-1)',
                    }}
                >
                    <div style={{ position: 'relative', flex: 1, minWidth: 220 }}>
                        <Icon.Search
                            size={14}
                            style={{ position: 'absolute', left: 10, top: 9, color: 'var(--fg-3)' }}
                        />
                        <input
                            data-testid="users-filter-q"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search name or email…"
                            style={{
                                width: '100%',
                                padding: '7px 10px 7px 30px',
                                fontSize: 13,
                                background: 'var(--bg-0)',
                                border: '1px solid var(--hairline)',
                                borderRadius: 8,
                                color: 'var(--fg-0)',
                            }}
                        />
                    </div>
                    <select
                        data-testid="users-filter-role"
                        value={role}
                        onChange={(e) => setRole(e.target.value)}
                        style={selectStyle}
                    >
                        <option value="">All roles</option>
                        {uniqueRoleNames.map((r) => (
                            <option key={r} value={r}>
                                {r}
                            </option>
                        ))}
                    </select>
                    <select
                        data-testid="users-filter-active"
                        value={active}
                        onChange={(e) => setActive(e.target.value as typeof active)}
                        style={selectStyle}
                    >
                        <option value="all">Active + inactive</option>
                        <option value="active">Active only</option>
                        <option value="inactive">Inactive only</option>
                    </select>
                    <label
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6,
                            fontSize: 12,
                            color: 'var(--fg-2)',
                        }}
                    >
                        <input
                            type="checkbox"
                            data-testid="users-filter-with-trashed"
                            checked={withTrashed}
                            onChange={(e) => setWithTrashed(e.target.checked)}
                        />
                        Include deleted
                    </label>
                </div>

                <UsersTable
                    users={users}
                    state={state}
                    onOpen={openEdit}
                    onToggleActive={handleToggleActive}
                    onRestore={handleRestore}
                    onDelete={handleDelete}
                />

                <UserDrawer
                    mode={drawerMode}
                    open={drawerOpen}
                    user={drawerUser}
                    roles={roles}
                    projectKeys={DEFAULT_PROJECT_KEYS}
                    onClose={() => setDrawerOpen(false)}
                />
            </div>
        </AdminShell>
    );
}

const selectStyle = {
    padding: '7px 10px',
    fontSize: 13,
    background: 'var(--bg-0)',
    border: '1px solid var(--hairline)',
    borderRadius: 8,
    color: 'var(--fg-1)',
};
