/*
 * Cross-mount HTTP client for the
 * `padosoft/laravel-pii-redactor-admin` admin SPA.
 *
 * v4.4/W2 — adapted from
 * `vendor/padosoft/laravel-pii-redactor-admin/resources/js/api.ts`.
 *
 * Two material differences vs the vendor package's `api.ts`:
 *
 *   1. We delegate transport to the host's shared axios instance
 *      (`frontend/src/lib/api.ts`) instead of `fetch(...)`. The host
 *      axios already carries:
 *        - `withCredentials: true` (Sanctum session cookie)
 *        - automatic `XSRF-TOKEN` → `X-XSRF-TOKEN` forwarding (so we
 *          don't have to read the CSRF meta tag manually)
 *        - `X-Requested-With: XMLHttpRequest` (so Laravel's
 *          `EnsureFrontendRequestsAreStateful` recognises the call as
 *          an SPA request)
 *      Sharing the axios instance also means request/response
 *      interceptors registered host-side (e.g. 401 redirects, 419
 *      CSRF re-prime) apply to package calls too.
 *
 *   2. `getAdminConfig()` is removed. The cross-mount caller passes
 *      the config payload as a prop to <PiiRedactorAdminApp /> after
 *      deriving it from the host auth-store; we no longer read from a
 *      `window.PII_REDACTOR_ADMIN` global.
 *
 * `AdminApiError` and `buildAdminQuery` are kept identical so the
 * vendored UI components — which type-check against them — continue to
 * compile without further edits.
 */
import { api } from '../../../../lib/api';
import type { AxiosError, AxiosRequestConfig } from 'axios';

export class AdminApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly payload: unknown,
    ) {
        super(message);
        this.name = 'AdminApiError';
    }
}

export type AdminFetchOptions = {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: string;
};

/**
 * Resolve a package-relative path against the cross-mount API base.
 *
 * The host pins `apiBase` to `/admin/pii-redactor/api` (the package
 * default at `pii-redactor-admin.api_prefix`); operators who change
 * the env var also need to redeploy the host bundle, which is the
 * same operational coupling the iframe predecessor had.
 */
function resolveAdminUrl(apiBase: string, path: string): string {
    const base = apiBase.replace(/\/+$/, '');
    const tail = path.replace(/^\/+/, '');
    return `${base}/${tail}`;
}

/**
 * Cross-mount HTTP wrapper that mirrors the package's `adminFetch()`
 * contract: returns parsed JSON on success, throws `AdminApiError`
 * on a 4xx/5xx with the server's error payload preserved.
 *
 * Surfaces failures loudly (R7/R14) — never returns `null` on a
 * non-2xx; the caller's `.catch(...)` branch handles the alert UI.
 */
export async function adminFetch<T>(
    apiBase: string,
    path: string,
    options: AdminFetchOptions = {},
): Promise<T> {
    const config: AxiosRequestConfig = {
        url: resolveAdminUrl(apiBase, path),
        method: options.method ?? 'GET',
        headers: {
            Accept: 'application/json',
        },
    };

    if (options.body !== undefined) {
        const body = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);
        try {
            config.data = JSON.parse(body);
        } catch {
            // Body wasn't valid JSON (e.g. legacy form-encoded payload).
            // Forward as-is and let the BE 422 if the controller can't
            // parse it.
            config.data = body;
        }
        config.headers = { ...config.headers, 'Content-Type': 'application/json' };
    }

    try {
        const response = await api.request<T>(config);
        return response.data;
    } catch (error) {
        const axiosError = error as AxiosError<unknown>;
        const status = axiosError.response?.status ?? 0;
        const payload = axiosError.response?.data ?? null;
        const messageFromPayload =
            payload && typeof payload === 'object' && 'message' in payload
                ? String((payload as { message: unknown }).message)
                : null;
        const message =
            messageFromPayload ??
            axiosError.message ??
            `Request failed with status ${status}.`;
        throw new AdminApiError(message, status, payload);
    }
}

/**
 * URL-encode a filter map and return either an empty string or
 * `?key=value&...`. Empty string + whitespace-only values are
 * dropped so the package's controllers see a clean query string.
 */
export function buildAdminQuery(filters: Record<string, string>): string {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value.trim() !== '') {
            params.set(key, value.trim());
        }
    });

    const query = params.toString();
    return query === '' ? '' : `?${query}`;
}
