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
});
