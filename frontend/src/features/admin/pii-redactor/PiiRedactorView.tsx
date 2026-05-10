/*
 * PiiRedactorView — cross-mount of the
 * `padosoft/laravel-pii-redactor-admin` v1.0.2 SPA.
 *
 * v4.4/W2 — replaces the v4.2/W4 iframe mount (see ADR 0005). The
 * previous iframe rationale (React 18 + handcrafted CSS host vs React
 * 19 + Tailwind v4 package) is RESOLVED:
 *
 *   - v4.4/W1 migrated the host to Tailwind v4 (commit 860d0aa).
 *   - The host already runs React 19.2.6 (matches the package).
 *
 * Mount strategy: cross-mount. The package's React tree renders
 * directly inside the host's TanStack Router, sharing one React
 * runtime, one Sanctum cookie, one axios instance. No iframe means
 * no double React, no double layout reflow, and one fewer HTTP
 * round-trip on first paint.
 *
 * Config resolution: the package's blade controller injects a
 * `window.PII_REDACTOR_ADMIN` global with `apiBase`, `routePrefix`,
 * `userDisplay`, `abilities`, and `csrfToken`. The cross-mount
 * derives the same shape host-side from:
 *
 *   - `apiBase` / `routePrefix` → known constants matching the
 *     `pii-redactor-admin.api_prefix` / `route_prefix` env defaults
 *     (operators who change those env vars also redeploy the host
 *     bundle, same operational coupling the iframe predecessor had).
 *   - `userDisplay` → `name || email || 'Operator'` from the host
 *     auth-store (same fallback chain as the package's
 *     AdminShellController).
 *   - `abilities` → derived from the host's Spatie roles to mirror
 *     the BE Gates registered in
 *     AppServiceProvider::registerPiiRedactorAdminGates(). The BE
 *     still gates the actual API calls (`can:viewPiiRedactorAdmin`
 *     middleware on the package routes); the FE-derived abilities
 *     are purely UX affordances — same security posture as
 *     RequireRole vs Spatie middleware elsewhere in the host.
 *   - `csrfToken` → DROPPED. The host axios instance auto-forwards
 *     `XSRF-TOKEN` cookie → `X-XSRF-TOKEN` header; we don't need
 *     the meta-tag value the package's blade injects.
 */
import { useMemo } from 'react';
import { useAuthStore } from '../../../lib/auth-store';
import PiiRedactorAdminApp from './cross-mount/App';
import type { PiiRedactorAdminConfig } from './cross-mount/types';
import './cross-mount/cross-mount.css';

const PII_REDACTOR_API_BASE = '/admin/pii-redactor/api';
const PII_REDACTOR_ROUTE_PREFIX = '/admin/pii-redactor';

export function PiiRedactorView() {
    const user = useAuthStore((state) => state.user);
    const roles = useAuthStore((state) => state.roles);

    const config = useMemo<PiiRedactorAdminConfig>(() => {
        const userDisplay = user?.name?.trim() || user?.email?.trim() || 'Operator';
        return {
            apiBase: PII_REDACTOR_API_BASE,
            routePrefix: PII_REDACTOR_ROUTE_PREFIX,
            userDisplay,
            abilities: {
                view: hasAnyRole(roles, ['super-admin', 'dpo', 'admin']),
                detokenise: hasAnyRole(roles, ['super-admin', 'dpo']),
                rawSamples: hasAnyRole(roles, ['super-admin']),
            },
        };
    }, [user, roles]);

    return (
        <div
            data-testid="admin-pii-redactor-host"
            data-state="ready"
            data-mount="cross-mount"
            style={{
                flex: 1,
                display: 'flex',
                flexDirection: 'column',
                background: 'var(--bg-0)',
                color: 'var(--fg-1)',
                position: 'relative',
                minHeight: 0,
            }}
        >
            <PiiRedactorAdminApp config={config} />
        </div>
    );
}

function hasAnyRole(roles: string[], allowed: string[]): boolean {
    return roles.some((role) => allowed.includes(role));
}
