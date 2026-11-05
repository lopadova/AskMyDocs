import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../../lib/api';
import { toAdminError } from '../shared/errors';

/**
 * Admin "Path contexts" client. A path context is an admin-authored
 * description bound to a `(project_key, path_prefix)` pair; the backend
 * injects the chain of matching descriptions into the RAG prompt (as
 * untrusted data) and returns it with citations.
 *
 * Wraps `/api/admin/kb/path-contexts` (list / upsert / delete). Every
 * call honours the `{ data: ... }` envelope used across the admin API.
 *
 * R43 — the whole feature sits behind `KB_PATH_CONTEXTS_ENABLED`. With
 * the flag OFF all three endpoints answer
 * `404 { code: 'path_contexts_disabled' }`; this module turns that exact
 * response into a typed {@link PathContextsDisabledError} so the UI can
 * render a clean "unavailable" panel instead of a raw error. Every other
 * failure is rethrown untouched (callers map it with `toAdminError`).
 */

export const PATH_CONTEXTS_URL = '/api/admin/kb/path-contexts';
export const PATH_CONTEXTS_QUERY_KEY = ['admin-kb-path-contexts'] as const;

export const PATH_PREFIX_MAX = 300;
export const PROJECT_KEY_MAX = 120;
export const DESCRIPTION_MAX = 500;

export interface PathContext {
    id: number;
    project_key: string;
    path_prefix: string;
    description: string;
    updated_at?: string | null;
}

export interface UpsertPathContextPayload {
    project_key: string;
    path_prefix: string;
    description: string;
}

export class PathContextsDisabledError extends Error {
    constructor(message = 'Path contexts are disabled on this deployment.') {
        super(message);
        this.name = 'PathContextsDisabledError';
    }
}

/** True when `err` is the backend's feature-flag-OFF answer. */
export function isPathContextsDisabled(err: unknown): boolean {
    if (err instanceof PathContextsDisabledError) return true;
    const response = (err as { response?: { status?: number; data?: { code?: string } } } | null)?.response;
    return response?.status === 404 && response.data?.code === 'path_contexts_disabled';
}

/** Human-readable message for a failed call (handles the typed disabled error). */
export function pathContextsErrorMessage(err: unknown): string {
    if (err instanceof PathContextsDisabledError) return err.message;
    return toAdminError(err).message;
}

function rethrow(err: unknown): never {
    if (isPathContextsDisabled(err) && !(err instanceof PathContextsDisabledError)) {
        throw new PathContextsDisabledError();
    }
    throw err;
}

export const adminPathContextsApi = {
    async list(projectKey?: string): Promise<PathContext[]> {
        try {
            const { data } = await api.get<{ data: PathContext[] }>(
                PATH_CONTEXTS_URL,
                projectKey ? { params: { project_key: projectKey } } : undefined,
            );
            return data.data;
        } catch (err) {
            return rethrow(err);
        }
    },

    async upsert(payload: UpsertPathContextPayload): Promise<PathContext> {
        try {
            const { data } = await api.put<{ data: PathContext }>(PATH_CONTEXTS_URL, payload);
            return data.data;
        } catch (err) {
            return rethrow(err);
        }
    },

    async remove(id: number): Promise<void> {
        try {
            await api.delete(`${PATH_CONTEXTS_URL}/${id}`);
        } catch (err) {
            return rethrow(err);
        }
    },
};

export type PathContextField = 'project_key' | 'path_prefix' | 'description';
export type PathContextFieldErrors = Partial<Record<PathContextField, string>>;

/**
 * Client-side mirror of the backend rules (project_key required ≤120;
 * path_prefix required ≤300, no leading slash, no `..`; description
 * required ≤500) so an obviously invalid form is rejected without a
 * round-trip. The server stays authoritative — its 422s are rendered in
 * the same slots.
 */
export function validatePathContext(input: UpsertPathContextPayload): PathContextFieldErrors {
    const errors: PathContextFieldErrors = {};
    const projectError = projectKeyError(input.project_key.trim());
    const prefixError = pathPrefixError(input.path_prefix.trim());
    const descriptionError = descriptionErrorFor(input.description.trim());
    if (projectError) errors.project_key = projectError;
    if (prefixError) errors.path_prefix = prefixError;
    if (descriptionError) errors.description = descriptionError;
    return errors;
}

function projectKeyError(project: string): string | null {
    if (project === '') return 'Select a project.';
    if (project.length > PROJECT_KEY_MAX) {
        return `The project key may not be longer than ${PROJECT_KEY_MAX} characters.`;
    }
    return null;
}

function pathPrefixError(prefix: string): string | null {
    if (prefix === '') return 'The path prefix is required.';
    if (prefix.length > PATH_PREFIX_MAX) {
        return `The path prefix may not be longer than ${PATH_PREFIX_MAX} characters.`;
    }
    if (prefix.startsWith('/')) return 'The path prefix must not start with a slash.';
    if (prefix.includes('..')) return 'The path prefix must not contain "..".';
    return null;
}

function descriptionErrorFor(description: string): string | null {
    if (description === '') return 'The description is required.';
    if (description.length > DESCRIPTION_MAX) {
        return `The description may not be longer than ${DESCRIPTION_MAX} characters.`;
    }
    return null;
}

export function usePathContexts(projectKey: string) {
    return useQuery<PathContext[]>({
        queryKey: [...PATH_CONTEXTS_QUERY_KEY, projectKey],
        queryFn: () => adminPathContextsApi.list(projectKey === '' ? undefined : projectKey),
        staleTime: 30_000,
        // No `retry` override: the app-wide default already skips 4xx, so the
        // flag-OFF 404 is never retried.
    });
}

export function useUpsertPathContext() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (payload: UpsertPathContextPayload) => adminPathContextsApi.upsert(payload),
        onSuccess: () => qc.invalidateQueries({ queryKey: PATH_CONTEXTS_QUERY_KEY }),
        // The flag can be flipped off mid-session: re-read the list so the
        // page lands on the "unavailable" panel instead of a stale table.
        onError: (err) => {
            if (isPathContextsDisabled(err)) void qc.invalidateQueries({ queryKey: PATH_CONTEXTS_QUERY_KEY });
        },
    });
}

export function useDeletePathContext() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => adminPathContextsApi.remove(id),
        onSuccess: () => qc.invalidateQueries({ queryKey: PATH_CONTEXTS_QUERY_KEY }),
        onError: (err) => {
            if (isPathContextsDisabled(err)) void qc.invalidateQueries({ queryKey: PATH_CONTEXTS_QUERY_KEY });
        },
    });
}
