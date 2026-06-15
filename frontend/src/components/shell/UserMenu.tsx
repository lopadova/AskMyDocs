import { useEffect, useId, useRef, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Icon } from '../Icons';
import { Avatar } from './Avatar';
import { useAuthStore } from '../../lib/auth-store';
import { logout } from '../../features/auth/auth.api';
import { queryClient } from '../../lib/query-client';

type Status = 'idle' | 'loading' | 'error';

/*
 * Topbar account menu. The ONE place a signed-in user can sign out.
 *
 * The transport already existed end-to-end (POST /api/auth/logout →
 * `auth.api.logout()` → `useAuthStore.clear()`), but no control was ever
 * wired to it — every authenticated session was a one-way door. This
 * closes it.
 *
 * A11y pattern inherited from TeamSwitcher: ARIA `menu` + `menuitem`,
 * Escape closes AND returns focus to the trigger, `aria-controls` wires
 * trigger → menu, outside-click dismisses.
 *
 * R14 — sign-out is a destructive security action, so a failed server
 * round-trip is surfaced loudly (error banner + retry) and the local
 * session state is left UNTOUCHED. Clearing the client while the server
 * cookie is still valid would tell the user they are logged out when
 * they are not. We clear + redirect ONLY after the 204 confirms the
 * server session is gone.
 */
export function UserMenu() {
    const user = useAuthStore((s) => s.user);
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const [status, setStatus] = useState<Status>('idle');
    const ref = useRef<HTMLDivElement | null>(null);
    const triggerRef = useRef<HTMLButtonElement | null>(null);
    const reactId = useId();
    const menuId = `user-menu-${reactId}`;

const close = (returnFocus = false) => {
    setOpen(false);
    if (status !== 'loading') {
        setStatus('idle');
    }
    if (returnFocus) {
        triggerRef.current?.focus();
    }
};

    useEffect(() => {
        if (!open) {
            return;
        }
        const onMouseDown = (e: MouseEvent) => {
            if (!ref.current?.contains(e.target as Node)) {
                close();
            }
        };
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                close(true);
            }
        };
        document.addEventListener('mousedown', onMouseDown);
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('mousedown', onMouseDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    // Inside AppShell the route tree only mounts behind RequireAuth, so
    // `user` is always present — but guard anyway so a transient null
    // during store.clear() can't crash the Topbar mid-sign-out.
    if (!user) {
        return null;
    }

    const handleSignOut = async () => {
        if (status === 'loading') {
            return;
        }
        setStatus('loading');
        try {
            // 204 confirms the server session + CSRF token are gone.
            await logout();
            // Only NOW is it safe to drop client state: clear the auth
            // store, then the query cache (it holds tenant-scoped data that
            // must not survive into the next session).
            useAuthStore.getState().clear();
            queryClient.clear();
            // Client-side nav to the SPA's React login — the SAME surface
            // the RequireAuth guard bounces an unauthenticated user to. A
            // full reload would instead hit Laravel's legacy Blade /login,
            // which is served only outside the SPA's /app/* routes.
            navigate({ to: '/login' });
        } catch {
            // Session is still valid server-side — keep the menu open and
            // let the user retry. Do NOT clear local state (R14).
            setStatus('error');
        }
    };

    return (
        <div ref={ref} style={{ position: 'relative' }}>
            <button
                ref={triggerRef}
                type="button"
                className="focus-ring"
                data-testid="user-menu-trigger"
                onClick={() => setOpen((o) => !o)}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                aria-label={`Account menu for ${user.name}`}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    padding: '4px 8px 4px 4px',
                    background: 'var(--bg-2)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 999,
                    cursor: 'pointer',
                    color: 'var(--fg-0)',
                    fontSize: 12.5,
                    fontWeight: 500,
                }}
            >
                <Avatar user={{ name: user.name }} size={24} />
                <span
                    style={{
                        maxWidth: 140,
                        whiteSpace: 'nowrap',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                    }}
                >
                    {user.name}
                </span>
                <Icon.ChevronDown size={13} style={{ color: 'var(--fg-3)' }} />
            </button>
            {open && (
                <div
                    id={menuId}
                    className="panel popin"
                    role="menu"
                    aria-label="Account"
                    data-testid="user-menu"
                    data-state={status}
                    style={{
                        position: 'absolute',
                        top: 'calc(100% + 6px)',
                        right: 0,
                        minWidth: 240,
                        padding: 6,
                        zIndex: 100,
                        boxShadow: 'var(--shadow-lg)',
                    }}
                >
                    <div style={{ padding: '8px 10px 10px', display: 'flex', alignItems: 'center', gap: 10 }}>
                        <Avatar user={{ name: user.name }} size={32} />
                        <div style={{ minWidth: 0 }}>
                            <div
                                data-testid="user-menu-name"
                                style={{
                                    fontSize: 13,
                                    fontWeight: 600,
                                    color: 'var(--fg-0)',
                                    whiteSpace: 'nowrap',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                }}
                            >
                                {user.name}
                            </div>
                            <div
                                data-testid="user-menu-email"
                                style={{
                                    fontSize: 11,
                                    color: 'var(--fg-3)',
                                    fontFamily: 'var(--font-mono)',
                                    whiteSpace: 'nowrap',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                }}
                            >
                                {user.email}
                            </div>
                        </div>
                    </div>

                    <div style={{ height: 1, background: 'var(--hairline)', margin: '2px 0 6px' }} />

                    {status === 'error' && (
                        <div
                            data-testid="user-menu-error"
                            role="alert"
                            style={{
                                margin: '0 4px 6px',
                                padding: '7px 9px',
                                borderRadius: 7,
                                background: 'var(--danger-bg, rgba(244,63,94,.12))',
                                color: 'var(--danger-fg, #f43f5e)',
                                fontSize: 11.5,
                                lineHeight: 1.4,
                            }}
                        >
                            Couldn’t sign you out — your session is still active. Please try again.
                        </div>
                    )}

                    <button
                        type="button"
                        role="menuitem"
                        data-testid="user-menu-logout"
                        data-state={status}
                        aria-busy={status === 'loading'}
                        disabled={status === 'loading'}
                        onClick={handleSignOut}
                        style={{
                            width: '100%',
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            padding: '8px 10px',
                            background: 'transparent',
                            border: 0,
                            borderRadius: 7,
                            cursor: status === 'loading' ? 'default' : 'pointer',
                            color: 'var(--fg-0)',
                            fontSize: 13,
                            textAlign: 'left',
                            opacity: status === 'loading' ? 0.6 : 1,
                        }}
                    >
                        <Icon.Logout size={15} style={{ color: 'var(--fg-2)' }} />
                        <span style={{ flex: 1 }}>
                            {status === 'loading' ? 'Signing out…' : status === 'error' ? 'Retry sign out' : 'Sign out'}
                        </span>
                    </button>
                </div>
            )}
        </div>
    );
}
