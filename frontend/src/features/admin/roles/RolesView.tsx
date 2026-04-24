import { useState } from 'react';
import { Icon } from '../../../components/Icons';
import { AdminShell } from '../shell/AdminShell';
import { ToastHost, useToast } from '../shared/Toast';
import { toAdminError } from '../shared/errors';
import type { AdminRole } from '../admin.api';
import {
    useDeleteRole,
    usePermissionCatalogue,
    useRoles,
} from './roles.api';
import { RoleDialog } from './RoleDialog';

/*
 * Admin Roles page. Table of roles with user counts; clicking a row
 * opens the RoleDialog in edit mode, the "New role" button opens it
 * in create mode. Protected roles show a shield marker and a guarded
 * delete (backend also returns 409 — the UI surfaces that toast).
 */

const PROTECTED_ROLE_NAMES = new Set(['super-admin', 'admin']);

export function RolesView() {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [dialogMode, setDialogMode] = useState<'create' | 'edit'>('create');
    const [dialogRole, setDialogRole] = useState<AdminRole | null>(null);

    const rolesQuery = useRoles();
    const catalogue = usePermissionCatalogue();
    const deleteRole = useDeleteRole();
    const toast = useToast();

    const state = rolesQuery.isLoading
        ? 'loading'
        : rolesQuery.isError
          ? 'error'
          : (rolesQuery.data?.data.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    const roles = rolesQuery.data?.data ?? [];

    function openCreate() {
        setDialogMode('create');
        setDialogRole(null);
        setDialogOpen(true);
    }

    function openEdit(role: AdminRole) {
        setDialogMode('edit');
        setDialogRole(role);
        setDialogOpen(true);
    }

    async function handleDelete(role: AdminRole) {
        try {
            await deleteRole.mutateAsync(role.id);
            toast.success(`Deleted role ${role.name}`, 'toast-role-deleted');
        } catch (e) {
            const err = toAdminError(e);
            toast.error(err.message, 'toast-role-error');
        }
    }

    return (
        <AdminShell section="roles">
            <ToastHost />
            <div
                data-testid="admin-roles"
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
                            Roles
                        </h1>
                        <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                            Spatie-backed roles and permission matrix.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid="roles-new"
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
                        <Icon.Plus size={14} /> New role
                    </button>
                </div>

                <div
                    data-testid="roles-table"
                    data-state={state}
                    style={{
                        border: '1px solid var(--hairline)',
                        borderRadius: 10,
                        overflow: 'hidden',
                        background: 'var(--bg-1)',
                    }}
                >
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontFamily: 'var(--font-sans)' }}>
                        <thead>
                            <tr style={{ background: 'var(--bg-0)' }}>
                                <th style={thStyle}>Role</th>
                                <th style={thStyle}>Permissions</th>
                                <th style={thStyle}>Users</th>
                                <th style={{ ...thStyle, textAlign: 'right' }}>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {state === 'loading' ? (
                                <tr>
                                    <td colSpan={4} data-testid="roles-loading" style={emptyCellStyle}>
                                        Loading roles…
                                    </td>
                                </tr>
                            ) : state === 'error' ? (
                                <tr>
                                    <td colSpan={4} data-testid="roles-error" style={{ ...emptyCellStyle, color: '#fca5a5' }}>
                                        Failed to load roles.
                                    </td>
                                </tr>
                            ) : state === 'empty' ? (
                                <tr>
                                    <td colSpan={4} data-testid="roles-empty" style={emptyCellStyle}>
                                        No roles found.
                                    </td>
                                </tr>
                            ) : (
                                roles.map((r) => {
                                    const protectedRole = PROTECTED_ROLE_NAMES.has(r.name);
                                    return (
                                        <tr
                                            key={r.id}
                                            data-testid={`roles-row-${r.name}`}
                                            data-protected={protectedRole ? 'true' : 'false'}
                                            style={{ borderBottom: '1px solid var(--hairline)' }}
                                        >
                                            <td style={{ padding: '10px 12px', fontSize: 13 }}>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                                    {protectedRole ? (
                                                        <Icon.Shield size={13} style={{ color: 'var(--fg-3)' }} />
                                                    ) : null}
                                                    <button
                                                        type="button"
                                                        className="focus-ring"
                                                        data-testid={`roles-row-${r.name}-open`}
                                                        onClick={() => openEdit(r)}
                                                        style={{
                                                            background: 'transparent',
                                                            border: 'none',
                                                            color: 'var(--fg-0)',
                                                            padding: 0,
                                                            fontSize: 13,
                                                            cursor: 'pointer',
                                                            fontWeight: 500,
                                                        }}
                                                    >
                                                        {r.name}
                                                    </button>
                                                </div>
                                            </td>
                                            <td style={{ padding: '10px 12px', fontSize: 12, color: 'var(--fg-2)' }}>
                                                <span data-testid={`roles-row-${r.name}-perm-count`}>
                                                    {r.permissions.length}
                                                </span>{' '}
                                                / {catalogue.data?.data.length ?? '—'}
                                            </td>
                                            <td
                                                data-testid={`roles-row-${r.name}-user-count`}
                                                style={{ padding: '10px 12px', fontSize: 12, color: 'var(--fg-2)' }}
                                            >
                                                {r.users_count}
                                            </td>
                                            <td style={{ padding: '10px 12px', textAlign: 'right' }}>
                                                <div style={{ display: 'inline-flex', gap: 6 }}>
                                                    <button
                                                        type="button"
                                                        className="focus-ring"
                                                        data-testid={`roles-row-${r.name}-edit`}
                                                        onClick={() => openEdit(r)}
                                                        title="Edit"
                                                        style={iconButtonStyle}
                                                    >
                                                        <Icon.Edit size={13} />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="focus-ring"
                                                        data-testid={`roles-row-${r.name}-delete`}
                                                        onClick={() => handleDelete(r)}
                                                        disabled={protectedRole}
                                                        title={protectedRole ? 'System role — cannot delete' : 'Delete'}
                                                        style={{
                                                            ...iconButtonStyle,
                                                            color: protectedRole ? 'var(--fg-3)' : '#fca5a5',
                                                            cursor: protectedRole ? 'not-allowed' : 'pointer',
                                                        }}
                                                    >
                                                        <Icon.Trash size={13} />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                <RoleDialog
                    mode={dialogMode}
                    open={dialogOpen}
                    role={dialogRole}
                    catalogue={catalogue.data}
                    onClose={() => setDialogOpen(false)}
                />
            </div>
        </AdminShell>
    );
}

const thStyle = {
    padding: '10px 12px',
    textAlign: 'left' as const,
    fontSize: 11,
    color: 'var(--fg-3)',
    fontFamily: 'var(--font-mono)',
    textTransform: 'uppercase' as const,
    letterSpacing: '0.05em',
    fontWeight: 500,
    borderBottom: '1px solid var(--hairline)',
};

const emptyCellStyle = {
    padding: '32px 16px',
    textAlign: 'center' as const,
    color: 'var(--fg-3)',
    fontSize: 13,
};

const iconButtonStyle = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 4,
    padding: '5px 8px',
    borderRadius: 6,
    background: 'transparent',
    border: '1px solid var(--hairline)',
    color: 'var(--fg-2)',
    cursor: 'pointer',
    fontSize: 12,
} as const;
