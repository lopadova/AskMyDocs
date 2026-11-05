import type { AxiosError } from 'axios';

/*
 * Normalise axios errors into something the admin UI can render.
 * Laravel validation errors arrive as `{ message, errors: { field: [msg,...] } }`
 * — we flatten to `field -> first msg` for inline display. Non-422 errors
 * surface the top-level message or the HTTP status text.
 */
export interface AdminApiError {
    status: number;
    message: string;
    fieldErrors: Record<string, string>;
    details: AdminApiErrorDetails;
}

export interface AdminApiErrorDetails {
    client_message: string;
    method: string | null;
    url: string | null;
    status: number;
    status_text: string | null;
    backend_diagnostic: Record<string, unknown> | null;
    response_body: unknown;
}

export function toAdminError(err: unknown): AdminApiError {
    const e = err as AxiosError<{
        message?: string;
        error?: string;
        errors?: Record<string, string[]>;
        diagnostic?: Record<string, unknown>;
    }>;
    const status = e?.response?.status ?? 0;
    const body = e?.response?.data;
    const raw = body?.errors ?? {};
    const fieldErrors: Record<string, string> = {};
    for (const [key, list] of Object.entries(raw)) {
        if (Array.isArray(list) && list.length > 0) {
            fieldErrors[key] = String(list[0]);
        }
    }
    const message =
        body?.message ||
        body?.error ||
        (status === 0 ? 'Network error.' : `Request failed (${status}).`);
    return {
        status,
        message,
        fieldErrors,
        details: {
            client_message: e?.message || message,
            method: e?.config?.method?.toUpperCase() ?? null,
            url: e?.config?.url ?? null,
            status,
            status_text: e?.response?.statusText || null,
            backend_diagnostic: body?.diagnostic ?? null,
            response_body: body ?? null,
        },
    };
}
