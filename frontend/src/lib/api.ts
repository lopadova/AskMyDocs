import axios, { AxiosHeaders, type AxiosInstance } from 'axios';
import { useTeamStore } from './team-store';

/*
 * Single axios instance shared by the SPA.
 *
 * - baseURL is `/` so dev + production use the same path prefix. The
 *   dev server proxies `/api`, `/sanctum`, etc. to the Laravel backend;
 *   in production Laravel serves both the SPA shell and the API from
 *   the same origin, so cookies work without CORS.
 * - `withCredentials: true` lets the browser attach the session cookie
 *   Sanctum issued. Axios also automatically forwards `XSRF-TOKEN` →
 *   `X-XSRF-TOKEN` when this flag is on.
 * - `X-Requested-With: XMLHttpRequest` makes Laravel's
 *   `EnsureFrontendRequestsAreStateful` recognise the call as an SPA
 *   request rather than a raw API call.
 */
export const api: AxiosInstance = axios.create({
    baseURL: '/',
    withCredentials: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/*
 * Routes that must NEVER carry the tenant header:
 * - `/api/auth/*` + `/sanctum/*`: a stale persisted team would otherwise
 *   403 the bootstrap `me()` call and lock the user out before the team
 *   list can re-sync.
 * - `/testing/*`: E2E reset/seed endpoints operate deployment-wide.
 */
const TENANT_EXEMPT_PREFIXES = ['/api/auth/', '/sanctum/', '/testing/'];

/*
 * The `default` tenant is the host's "no multi-tenancy" sentinel
 * (App\Support\TenantContext::isDefault()): ResolveTenant resolves the
 * SAME context whether the header is `default` or absent, so omitting it
 * for `default` keeps first-party R30 scoping identical. Crucially, it
 * also keeps the SPA compatible with sister-package mounts whose own
 * tenant-context middleware 404s on an unknown tenant: the AI Act package
 * (`ai-act.tenant-context`) deliberately never promotes `default` into a
 * `tenants` row (App\Compliance\TenantContextBridge), so stamping
 * `X-Tenant-Id: default` on `/api/admin/ai-act-compliance/*` 404'd that
 * admin screen on every single-tenant deployment. With no header those
 * package middlewares pass through to the host config fallback (their
 * documented "no header" branch). Real tenants (e.g. `acme`, which DO get
 * a package `tenants` row) still send the header and stay scoped.
 */
const DEFAULT_TENANT = 'default';

api.interceptors.request.use((config) => {
    const team = useTeamStore.getState().currentTeam;
    const url = config.url ?? '';
    if (
        team !== null &&
        team !== DEFAULT_TENANT &&
        !TENANT_EXEMPT_PREFIXES.some((p) => url.startsWith(p))
    ) {
        // config.headers can be undefined for ad-hoc request configs; initialise
        // it (without clobbering existing defaults) before stamping the header.
        config.headers ??= new AxiosHeaders();
        config.headers['X-Tenant-Id'] = team;
    }
    return config;
});

api.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        // The backend refused the active team (membership revoked, tenant
        // archived, stale persisted value): snap back to the first valid
        // team, then force a FULL re-bootstrap from /app — the URL still
        // carries the refused team's hash and the cached team list is by
        // definition stale, so an in-SPA fix would loop (TeamGate keeps
        // honouring the URL). Still REJECT so the failing caller surfaces
        // its error state (R14) before the reload lands.
        //
        // Scope note (M2): this live recovery triggers only on the host's
        // first-party `tenant_forbidden` 403. Sister-package mounts reject a
        // revoked/stale tenant with their own statuses (the AI Act package:
        // 404 unknown / 410 archived / 423 suspended) and bodies, which we do
        // NOT blanket-recover from here — a 404 is too ambiguous to safely
        // reset the active team on. Those cases self-heal on the next full
        // bootstrap: `syncFromMe` re-validates the persisted team against the
        // fresh `/api/auth/me` `teams` list and falls back to the first valid
        // team. Live recovery is therefore best-effort for package routes.
        if (
            axios.isAxiosError(error) &&
            error.response?.status === 403 &&
            (error.response.data as { error?: string } | undefined)?.error === 'tenant_forbidden'
        ) {
            useTeamStore.getState().resetToFirstTeam();
            if (typeof window !== 'undefined' && typeof window.location?.assign === 'function') {
                window.location.assign('/app');
            }
        }
        return Promise.reject(error);
    },
);

let csrfPrimed = false;

/**
 * Prime the `XSRF-TOKEN` cookie before any state-changing request. Must
 * be called at app bootstrap AND after a 419 (CSRF mismatch) response.
 * Idempotent within a single app mount — resets only via `resetCsrf()`.
 */
export async function ensureCsrfCookie(): Promise<void> {
    if (csrfPrimed) {
        return;
    }
    await api.get('/sanctum/csrf-cookie');
    csrfPrimed = true;
}

/**
 * Force the next `ensureCsrfCookie()` to re-prime. Use after logout or
 * a 419 error so the new session gets a fresh token.
 */
export function resetCsrf(): void {
    csrfPrimed = false;
}
