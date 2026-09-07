import { describe, expect, it } from 'vitest';
import { toAdminError } from './errors';

describe('toAdminError', () => {
    it('surfaces the error field returned by connector diagnostics', () => {
        const parsed = toAdminError({
            response: {
                status: 503,
                data: { error: 'Mailbox busy: another connection is already in progress.' },
            },
        });

        expect(parsed.status).toBe(503);
        expect(parsed.message).toBe('Mailbox busy: another connection is already in progress.');
    });

    it('keeps the standard Laravel message field as the first choice', () => {
        const parsed = toAdminError({
            response: {
                status: 422,
                data: {
                    message: 'Validation failed.',
                    error: 'Lower-priority error.',
                    errors: { label: ['The label is required.'] },
                },
            },
        });

        expect(parsed.message).toBe('Validation failed.');
        expect(parsed.fieldErrors).toEqual({ label: 'The label is required.' });
    });

    it('preserves correlated backend diagnostics for the error details dialog', () => {
        const diagnostic = {
            id: '01DIAGNOSTIC',
            detail: 'OAuth protected resource metadata discovery failed.',
            outbound_attempts: [{ method: 'GET', status: 404 }],
        };
        const responseBody = {
            message: 'MCP connection request failed.',
            diagnostic,
        };

        const parsed = toAdminError({
            message: 'Request failed with status code 500',
            config: { method: 'post', url: '/api/admin/connectors/mcp' },
            response: {
                status: 500,
                statusText: 'Internal Server Error',
                data: responseBody,
            },
        });

        expect(parsed.details).toEqual({
            client_message: 'Request failed with status code 500',
            method: 'POST',
            url: '/api/admin/connectors/mcp',
            status: 500,
            status_text: 'Internal Server Error',
            backend_diagnostic: diagnostic,
            response_body: responseBody,
        });
    });
});
