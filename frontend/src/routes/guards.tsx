import { useEffect, useState, type ReactNode } from 'react';
import { useNavigate } from '@tanstack/react-router';
import axios from 'axios';
import { useAuthStore } from '../lib/auth-store';
import { me } from '../features/auth/auth.api';
import { ensureCsrfCookie } from '../lib/api';

/**
 * Runs once at app mount. Two jobs:
 *   1. Prime the XSRF-TOKEN cookie so every subsequent state-changing
 *      request (including logout from an already-authed session) has a
 *      valid CSRF header and never 419s on a cold page load.
 *   2. Kick `/api/auth/me`. When 401, the store is cleared so guarded
 *      routes bounce to /login. When 200, the store is populated with
 *      the PR3 shape (user + roles + permissions + projects).
 *
 * The CSRF prime is deliberately best-effort and non-blocking for the
 * `me()` call — a transient network failure on csrf-cookie shouldn't
 * stop auth bootstrap (the user just retries once they try to act).
 */
export function useAuthBootstrap(): void {
    const setMe = useAuthStore((s) => s.setMe);
    const clear = useAuthStore((s) => s.clear);
    const setLoading = useAuthStore((s) => s.setLoading);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);

        // Fire the CSRF prime in parallel with the me() call. We don't
        // await it so a slow csrf-cookie doesn't block auth resolution,
        // but the promise must be referenced to avoid an unhandled
        // rejection if it errors.
        ensureCsrfCookie().catch(() => {
            /* best-effort: re-primed lazily on next state-changing call */
        });

        me()
            .then((payload) => {
                if (cancelled) {
                    return;
                }
                setMe(payload);
            })
            .catch((err: unknown) => {
                if (cancelled) {
                    return;
                }
                if (axios.isAxiosError(err) && err.response?.status === 401) {
                    clear();
                    return;
                }
                clear();
            });
        return () => {
            cancelled = true;
        };
    }, [setMe, clear, setLoading]);
}

function AuthGate({ children, waitFor }: { children: ReactNode; waitFor: 'authed' | 'guest' }) {
    const user = useAuthStore((s) => s.user);
    const loading = useAuthStore((s) => s.loading);
    const navigate = useNavigate();
    const [redirecting, setRedirecting] = useState(false);

    useEffect(() => {
        if (loading) {
            return;
        }
        if (waitFor === 'authed' && !user) {
            setRedirecting(true);
            navigate({ to: '/login' });
            return;
        }
        if (waitFor === 'guest' && user) {
            setRedirecting(true);
            navigate({ to: '/app' });
        }
    }, [loading, user, waitFor, navigate]);

    if (loading || redirecting) {
        return (
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    height: '100vh',
                    background: 'var(--bg-0)',
                    color: 'var(--fg-2)',
                    fontFamily: 'var(--font-sans)',
                    fontSize: 13,
                }}
            >
                <span className="shimmer" style={{ padding: '6px 18px', borderRadius: 8 }}>
                    Loading…
                </span>
            </div>
        );
    }

    return <>{children}</>;
}

export function RequireAuth({ children }: { children: ReactNode }) {
    return <AuthGate waitFor="authed">{children}</AuthGate>;
}

export function RedirectIfAuth({ children }: { children: ReactNode }) {
    return <AuthGate waitFor="guest">{children}</AuthGate>;
}
