import axios from 'axios';

/*
 * Laravel 422 validation error parser.
 *
 * Laravel returns 422 errors as:
 *   { message: "The given data was invalid.", errors: { field: [msg, ...], ... } }
 *
 * Axios sees this as an AxiosError where `error.response.data` carries
 * the payload. The default `error.message` is "Request failed with
 * status code 422" — useless to the user. This helper unpacks the
 * field-level errors and falls back to the `message` or a default.
 *
 * Copilot iter 10 flagged that the admin SPA's create dialogs flattened
 * 422s to a single generic message, dropping field-level feedback.
 * This module is the shared parser for admin SPA mutations; currently
 * consumed by the Tabular Reviews and Workflows surfaces.
 */

export interface ParsedLaravelError {
    /** Top-level human message — fallback or banner. */
    message: string;
    /** Field → list of error messages. Empty object if no field errors. */
    fields: Record<string, string[]>;
    /** Best-effort HTTP status code (422, 500, 403, ...). 0 if unknown. */
    status: number;
}

/**
 * Parse any thrown value (axios error, plain Error, unknown) into a
 * stable shape the UI can render. Always returns a valid object.
 */
export function parseLaravelError(err: unknown, fallback = 'Request failed.'): ParsedLaravelError {
    if (axios.isAxiosError(err) && err.response) {
        const data = err.response.data as unknown;
        const status = err.response.status || 0;
        if (data && typeof data === 'object') {
            const obj = data as Record<string, unknown>;
            const message = typeof obj.message === 'string' ? obj.message : fallback;
            const errors = obj.errors;
            const fields: Record<string, string[]> = {};
            if (errors && typeof errors === 'object') {
                for (const [key, val] of Object.entries(errors as Record<string, unknown>)) {
                    if (Array.isArray(val)) {
                        fields[key] = val.filter((v): v is string => typeof v === 'string');
                    } else if (typeof val === 'string') {
                        fields[key] = [val];
                    }
                }
            }
            return { message, fields, status };
        }
        // data is not a JSON object (e.g. HTML/text body) — prefer the
        // caller-supplied fallback over the generic Axios "Request failed
        // with status code N" message; use data as-is when it is a short
        // non-HTML string that may carry a useful server message.
        const raw = typeof data === 'string' && data.length < 200 && !data.trimStart().startsWith('<')
            ? data
            : fallback || err.message;
        return { message: raw, fields: {}, status };
    }
    if (err instanceof Error) {
        return { message: err.message || fallback, fields: {}, status: 0 };
    }
    return { message: fallback, fields: {}, status: 0 };
}

/**
 * Flatten a ParsedLaravelError back into a single string — useful for
 * a single-line toast / banner where per-field rendering isn't worth
 * the layout cost.
 */
export function flattenLaravelError(parsed: ParsedLaravelError): string {
    const fieldEntries = Object.entries(parsed.fields);
    if (fieldEntries.length === 0) {
        return parsed.message;
    }
    const fieldLines = fieldEntries
        .map(([field, msgs]) => `${field}: ${msgs.join('; ')}`)
        .join(' • ');
    return `${parsed.message} (${fieldLines})`;
}
