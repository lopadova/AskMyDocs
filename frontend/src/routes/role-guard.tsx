import type { ReactNode } from 'react';
import { useAuthStore } from '../lib/auth-store';

export interface RequireRoleProps {
    roles: string[];
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Route-level RBAC gate. Renders `children` only when the authenticated
 * user's roles overlap the provided allowlist. Otherwise renders the
 * forbidden placeholder (or a custom fallback).
 *
 * The server-side Sanctum route group enforces the same check via
 * Spatie's `role:` middleware — this component is purely a UX convenience
 * to avoid flashing 403-fetching states inside the admin shell. Security
 * is not maintained here: the API will reject unauthorised calls.
 */
export function RequireRole({ roles, children, fallback }: RequireRoleProps) {
    const userRoles = useAuthStore((s) => s.roles);
    const loading = useAuthStore((s) => s.loading);

    if (loading) {
        return (
            <div
                data-testid="admin-loading"
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    flex: 1,
                    color: 'var(--fg-2)',
                    fontFamily: 'var(--font-sans)',
                    fontSize: 13,
                }}
            >
                <span className="shimmer" style={{ padding: '6px 18px', borderRadius: 8 }}>
                    Checking access…
                </span>
            </div>
        );
    }

    const allowed = userRoles.some((r) => roles.includes(r));
    if (!allowed) {
        return <>{fallback ?? <AdminForbidden />}</>;
    }

    return <>{children}</>;
}

export function AdminForbidden() {
    return (
        <div
            data-testid="admin-forbidden"
            style={{
                flex: 1,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: 40,
                color: 'var(--fg-1)',
                fontFamily: 'var(--font-sans)',
            }}
        >
            <div
                className="panel popin"
                style={{
                    maxWidth: 420,
                    padding: '28px 28px 24px',
                    textAlign: 'center',
                }}
            >
                <div
                    style={{
                        width: 48,
                        height: 48,
                        borderRadius: 12,
                        background: 'rgba(239, 68, 68, 0.16)',
                        border: '1px solid rgba(239, 68, 68, 0.35)',
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        margin: '0 auto 14px',
                        fontSize: 20,
                        color: '#fca5a5',
                    }}
                >
                    !
                </div>
                <h2
                    style={{
                        fontSize: 18,
                        fontWeight: 600,
                        margin: '0 0 6px',
                        letterSpacing: '-0.01em',
                    }}
                >
                    Admin access required
                </h2>
                <p
                    style={{
                        fontSize: 13,
                        color: 'var(--fg-2)',
                        margin: 0,
                        lineHeight: 1.55,
                    }}
                >
                    Your account does not have the admin or super-admin role. Ask a
                    workspace owner to grant you access if this looks wrong.
                </p>
            </div>
        </div>
    );
}
