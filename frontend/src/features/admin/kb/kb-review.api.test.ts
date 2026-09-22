import { describe, expect, it } from 'vitest';
import { AxiosError, AxiosHeaders } from 'axios';
import { isReviewDisabledError } from './kb-review.api';

/*
 * Copilot review PR #497 (pullrequestreview-5257061609) — proves the fix
 * for `isReviewDisabledError` misclassifying ANY 404 from this API
 * surface as "feature disabled". Per R16 the negative case (a real 404
 * that is NOT the disabled-feature response) must actually be exercised,
 * not just the positive case.
 */

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

describe('isReviewDisabledError', () => {
    it('is true for the real KbReviewDisabledException 404 payload', () => {
        const err = makeAxiosError(404, {
            message: 'Digitization Review is disabled (set KB_DIGITIZATION_REVIEW_ENABLED=true).',
        });

        expect(isReviewDisabledError(err)).toBe(true);
    });

    it('is false for an unrelated 404 (e.g. document not found) — the regression this fix closes', () => {
        const err = makeAxiosError(404, { message: 'Document not found.' });

        // Before the fix, isReviewDisabledError checked status===404 only
        // and this would have returned true, hiding a real "not found"
        // behind the "Digitization Review is disabled" UI state.
        expect(isReviewDisabledError(err)).toBe(false);
    });

    it('is false for a 404 with no message body', () => {
        const err = makeAxiosError(404, {});

        expect(isReviewDisabledError(err)).toBe(false);
    });

    it('is false for a non-404 AxiosError even with the disabled message', () => {
        const err = makeAxiosError(500, {
            message: 'Digitization Review is disabled (set KB_DIGITIZATION_REVIEW_ENABLED=true).',
        });

        expect(isReviewDisabledError(err)).toBe(false);
    });

    it('is false for a non-AxiosError value', () => {
        expect(isReviewDisabledError(new Error('boom'))).toBe(false);
        expect(isReviewDisabledError(null)).toBe(false);
        expect(isReviewDisabledError(undefined)).toBe(false);
    });
});
