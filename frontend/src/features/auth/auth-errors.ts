import axios from 'axios';
import type { FieldErrors } from './AuthLayout';

/**
 * Maps an Axios error from an auth API call to field-level and form-level
 * error state. Shared by LoginPage and RegisterPage to keep status-code
 * handling in one place.
 *
 * @param err           The caught error value.
 * @param fallbackMessage  Human-readable message used when the server does not
 *                      return a specific `message` and the error is not a 422
 *                      validation failure.
 */
export function extractAxiosErrors(
    err: unknown,
    fallbackMessage = 'Something went wrong. Please try again.',
): { fieldErrors?: FieldErrors; message?: string } {
    if (!axios.isAxiosError(err)) {
        return { message: fallbackMessage };
    }
    const status = err.response?.status;
    const data = err.response?.data as { message?: string; errors?: FieldErrors } | undefined;
    if (status === 422 && data?.errors) {
        return { fieldErrors: data.errors };
    }
    if (status === 429) {
        return { message: data?.message ?? 'Too many attempts — please try again in a moment.' };
    }
    return { message: data?.message ?? fallbackMessage };
}
