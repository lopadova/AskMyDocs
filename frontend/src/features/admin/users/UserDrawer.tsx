import { useEffect, useState } from 'react';
import { Icon } from '../../../components/Icons';
import type { AdminRole, AdminUser } from '../admin.api';
import { toAdminError } from '../shared/errors';
import { useToast } from '../shared/Toast';
import { MembershipEditor } from './MembershipEditor';
import { UserForm, type UserFormValues } from './UserForm';
import {
    useCreateUser,
    useResendInvite,
    useUpdateUser,
} from './users.api';

export type DrawerMode = 'create' | 'edit';

export interface UserDrawerProps {
    mode: DrawerMode;
    open: boolean;
    user?: AdminUser | null;
    roles: AdminRole[];
    projectKeys: string[];
    onClose: () => void;
}

type Tab = 'profile' | 'roles' | 'memberships';

/*
 * Slide-in drawer — three tabs: Profile / Roles / Project memberships.
 * Create-mode only shows Profile (no membership until the user exists).
 * Profile tab hosts the UserForm; the Roles tab is collapsed into the
 * form role chips to avoid a duplicate UI, but a dedicated preview
 * makes the tab non-trivial so the three-tab shape matches the spec.
 */
export function UserDrawer({
    mode,
    open,
    user,
    roles,
    projectKeys,
    onClose,
}: UserDrawerProps) {
    const [tab, setTab] = useState<Tab>('profile');
    const [serverErrors, setServerErrors] = useState<Record<string, string> | undefined>(
        undefined,
    );
    const createM = useCreateUser();
    const updateM = useUpdateUser();
    const invite = useResendInvite();
    const toast = useToast();

    useEffect(() => {
        if (open) {
            setTab('profile');
            setServerErrors(undefined);
        }
    }, [open, user?.id, mode]);

    if (!open) return null;

    async function handleSubmit(values: UserFormValues) {
        setServerErrors(undefined);
        try {
            if (mode === 'create') {
                const created = await createM.mutateAsync({
                    name: values.name,
                    email: values.email,
                    password: values.password,
                    is_active: values.is_active,
                    roles: values.roles,
                });
                toast.success(`User ${created.email} created`, 'toast-user-created');
                onClose();
                return;
            }
            if (!user) return;
            const patch: Partial<UserFormValues> = {
                name: values.name,
                email: values.email,
                is_active: values.is_active,
                roles: values.roles,
            };
            if (values.password && values.password !== '') {
                patch.password = values.password;
            }
            await updateM.mutateAsync({ id: user.id, input: patch });
            toast.success(`User ${values.email} updated`, 'toast-user-updated');
            onClose();
        } catch (e) {
            const err = toAdminError(e);
            if (Object.keys(err.fieldErrors).length > 0) {
                setServerErrors(err.fieldErrors);
            }
            toast.error(err.message, 'toast-user-error');
        }
    }

    async function handleResendInvite() {
        if (!user) return;
        try {
            await invite.mutateAsync(user.id);
            toast.success('Invite queued for resend', 'toast-invite-sent');
        } catch (e) {
            toast.error(toAdminError(e).message, 'toast-invite-error');
        }
    }

    const title = mode === 'create' ? 'New user' : user?.name ?? 'User';
    const subtitle = mode === 'create' ? 'Create a new account' : user?.email ?? '';

    const tabs: { id: Tab; label: string; enabled: boolean }[] = [
        { id: 'profile', label: 'Profile', enabled: true },
        { id: 'roles', label: 'Roles', enabled: mode === 'edit' },
        { id: 'memberships', label: 'Project memberships', enabled: mode === 'edit' },
    ];

    return (
        <div
            data-testid="user-drawer"
            data-mode={mode}
            style={{
                position: 'fixed',
                inset: 0,
                zIndex: 50,
                display: 'flex',
                justifyContent: 'flex-end',
                pointerEvents: 'auto',
            }}
        >
            <button
                type="button"
                aria-label="Close drawer"
                data-testid="user-drawer-backdrop"
                onClick={onClose}
                style={{
                    position: 'absolute',
                    inset: 0,
                    background: 'rgba(0,0,0,0.45)',
                    border: 'none',
                    padding: 0,
                    cursor: 'pointer',
                }}
            />
            <aside
                style={{
                    position: 'relative',
                    width: 'min(520px, 100%)',
                    height: '100%',
                    background: 'var(--bg-1)',
                    borderLeft: '1px solid var(--hairline)',
                    padding: '20px 22px',
                    overflowY: 'auto',
                    color: 'var(--fg-1)',
                    fontFamily: 'var(--font-sans)',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 14,
                }}
            >
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <div style={{ flex: 1 }}>
                        <h2
                            data-testid="user-drawer-title"
                            style={{ fontSize: 17, margin: 0, color: 'var(--fg-0)' }}
                        >
                            {title}
                        </h2>
                        <p
                            style={{
                                fontSize: 12,
                                margin: '2px 0 0',
                                color: 'var(--fg-3)',
                            }}
                        >
                            {subtitle}
                        </p>
                    </div>
                    <button
                        type="button"
                        className="focus-ring"
                        data-testid="user-drawer-close"
                        onClick={onClose}
                        style={iconCloseStyle}
                        aria-label="Close"
                    >
                        <Icon.Close size={14} />
                    </button>
                </div>

                <div
                    role="tablist"
                    aria-label="User detail tabs"
                    style={{
                        display: 'flex',
                        gap: 4,
                        borderBottom: '1px solid var(--hairline)',
                    }}
                >
                    {tabs.map((t) => (
                        <button
                            key={t.id}
                            type="button"
                            role="tab"
                            aria-selected={tab === t.id}
                            className="focus-ring"
                            data-testid={`user-drawer-tab-${t.id}`}
                            data-active={tab === t.id ? 'true' : 'false'}
                            disabled={!t.enabled}
                            onClick={() => setTab(t.id)}
                            style={{
                                padding: '7px 11px',
                                fontSize: 13,
                                background: 'transparent',
                                border: 'none',
                                borderBottom: '2px solid ' + (tab === t.id ? 'var(--accent-1)' : 'transparent'),
                                color: t.enabled ? (tab === t.id ? 'var(--fg-0)' : 'var(--fg-2)') : 'var(--fg-3)',
                                cursor: t.enabled ? 'pointer' : 'not-allowed',
                                opacity: t.enabled ? 1 : 0.5,
                            }}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                <div
                    role="tabpanel"
                    data-testid={`user-drawer-panel-${tab}`}
                    style={{ display: 'flex', flexDirection: 'column', gap: 12 }}
                >
                    {tab === 'profile' ? (
                        <>
                            <UserForm
                                mode={mode}
                                initial={user}
                                roles={roles}
                                onSubmit={handleSubmit}
                                submitting={createM.isPending || updateM.isPending}
                                serverErrors={serverErrors}
                                onCancel={onClose}
                            />
                            {mode === 'edit' && user ? (
                                <button
                                    type="button"
                                    className="focus-ring"
                                    data-testid="user-drawer-resend-invite"
                                    onClick={handleResendInvite}
                                    disabled={invite.isPending}
                                    style={{
                                        alignSelf: 'flex-start',
                                        padding: '6px 11px',
                                        fontSize: 12,
                                        background: 'transparent',
                                        color: 'var(--fg-2)',
                                        border: '1px dashed var(--hairline)',
                                        borderRadius: 6,
                                        cursor: 'pointer',
                                    }}
                                >
                                    Resend invite
                                </button>
                            ) : null}
                        </>
                    ) : null}

                    {tab === 'roles' && user ? (
                        <div
                            data-testid="user-drawer-roles"
                            style={{ display: 'flex', flexDirection: 'column', gap: 10 }}
                        >
                            <p style={{ fontSize: 12, color: 'var(--fg-3)', margin: 0 }}>
                                Role assignments update on save from the Profile tab.
                            </p>
                            <div
                                data-testid="user-drawer-roles-list"
                                style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}
                            >
                                {(user.roles.length === 0) ? (
                                    <span style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                                        No roles assigned.
                                    </span>
                                ) : (
                                    user.roles.map((r) => (
                                        <span
                                            key={r}
                                            data-testid={`user-drawer-role-${r}`}
                                            style={{
                                                padding: '4px 10px',
                                                borderRadius: 999,
                                                background: 'var(--grad-accent-soft)',
                                                fontSize: 12,
                                                color: 'var(--fg-1)',
                                            }}
                                        >
                                            {r}
                                        </span>
                                    ))
                                )}
                            </div>
                            <div>
                                <div
                                    style={{
                                        fontSize: 11,
                                        color: 'var(--fg-3)',
                                        fontFamily: 'var(--font-mono)',
                                        textTransform: 'uppercase',
                                        letterSpacing: '0.05em',
                                        marginBottom: 4,
                                    }}
                                >
                                    Effective permissions
                                </div>
                                <div
                                    data-testid="user-drawer-permissions"
                                    style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}
                                >
                                    {user.permissions.length === 0 ? (
                                        <span style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                                            (none)
                                        </span>
                                    ) : (
                                        user.permissions.map((p) => (
                                            <span
                                                key={p}
                                                style={{
                                                    padding: '2px 7px',
                                                    fontSize: 11,
                                                    background: 'rgba(148,163,184,0.14)',
                                                    borderRadius: 6,
                                                    color: 'var(--fg-2)',
                                                    fontFamily: 'var(--font-mono)',
                                                }}
                                            >
                                                {p}
                                            </span>
                                        ))
                                    )}
                                </div>
                            </div>
                        </div>
                    ) : null}

                    {tab === 'memberships' && user ? (
                        <MembershipEditor userId={user.id} projectKeys={projectKeys} />
                    ) : null}
                </div>
            </aside>
        </div>
    );
}

const iconCloseStyle = {
    width: 30,
    height: 30,
    padding: 0,
    borderRadius: 7,
    background: 'transparent',
    border: '1px solid var(--hairline)',
    color: 'var(--fg-2)',
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    cursor: 'pointer',
};
