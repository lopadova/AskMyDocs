import { describe, it, expect } from 'vitest';
import { AxiosError, AxiosHeaders } from 'axios';
import { parseLaravelError, flattenLaravelError } from './laravel-errors';

function makeAxiosError(status: number, data: unknown): AxiosError {
    const err = new AxiosError('Request failed with status code ' + status);
    err.response = {
        status,
        statusText: '',
        data,
        headers: new AxiosHeaders(),
        config: { headers: new AxiosHeaders() } as never,
    };
    err.isAxiosError = true;
    return err;
}

describe('parseLaravelError', () => {
    it('extracts message + field errors from a Laravel 422 payload', () => {
        const err = makeAxiosError(422, {
            message: 'The given data was invalid.',
            errors: {
                title: ['The title field is required.'],
                'columns_config.0.name': ['The name field is required.'],
            },
        });
        const parsed = parseLaravelError(err, 'fallback');
        expect(parsed.status).toBe(422);
        expect(parsed.message).toBe('The given data was invalid.');
        expect(parsed.fields).toEqual({
            title: ['The title field is required.'],
            'columns_config.0.name': ['The name field is required.'],
        });
    });

    it('uses the response.message even when there are no field errors', () => {
        const err = makeAxiosError(403, { message: 'This action is unauthorized.' });
        const parsed = parseLaravelError(err);
        expect(parsed.status).toBe(403);
        expect(parsed.message).toBe('This action is unauthorized.');
        expect(parsed.fields).toEqual({});
    });

    it('falls back to the supplied default when the response has no message', () => {
        const err = makeAxiosError(500, {});
        const parsed = parseLaravelError(err, 'Could not create review.');
        expect(parsed.message).toBe('Could not create review.');
        expect(parsed.fields).toEqual({});
    });

    it('prefers fallback over generic Axios message when data is an HTML body', () => {
        const err = makeAxiosError(500, '<!DOCTYPE html><html><body>Server Error</body></html>');
        const parsed = parseLaravelError(err, 'Could not save.');
        expect(parsed.message).toBe('Could not save.');
        expect(parsed.fields).toEqual({});
    });

    it('uses a short non-HTML string data as the message', () => {
        const err = makeAxiosError(503, 'Service temporarily unavailable');
        const parsed = parseLaravelError(err, 'Fallback message.');
        expect(parsed.message).toBe('Service temporarily unavailable');
        expect(parsed.fields).toEqual({});
    });

    it('handles a plain Error', () => {
        const parsed = parseLaravelError(new Error('Network down'), 'fallback');
        expect(parsed.status).toBe(0);
        expect(parsed.message).toBe('Network down');
        expect(parsed.fields).toEqual({});
    });

    it('handles unknown thrown values with the fallback', () => {
        const parsed = parseLaravelError('something weird', 'Generic failure.');
        expect(parsed.message).toBe('Generic failure.');
        expect(parsed.status).toBe(0);
    });
});

describe('flattenLaravelError', () => {
    it('returns just the message when there are no field errors', () => {
        const out = flattenLaravelError({ message: 'Boom', fields: {}, status: 500 });
        expect(out).toBe('Boom');
    });

    it('appends per-field details when present', () => {
        const out = flattenLaravelError({
            message: 'Validation failed',
            fields: { title: ['Required'], type: ['Invalid', 'Must be assistant or tabular'] },
            status: 422,
        });
        expect(out).toBe('Validation failed (title: Required • type: Invalid; Must be assistant or tabular)');
    });
});
