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
}

export function toAdminError(err: unknown): AdminApiError {
    const e = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
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
        (status === 0 ? 'Network error.' : `Request failed (${status}).`);
    return { status, message, fieldErrors };
}
