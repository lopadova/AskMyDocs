import axios, { type AxiosInstance } from 'axios';

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

let csrfPrimed = false;

/**
 * Prime the `XSRF-TOKEN` cookie before any state-changing request.
 *
 * Call sites:
 *   - `useAuthBootstrap()` in routes/guards.tsx — primed once at app
 *     mount so even the first logout cannot 419.
 *   - `login()` / `forgot()` / `reset()` — defensively re-primed.
 *   - After a 419 response, trigger `resetCsrf()` and retry once.
 *
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
