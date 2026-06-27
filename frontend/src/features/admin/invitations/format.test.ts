import { describe, it, expect } from 'vitest';
import {
    deriveListState,
    formatCompact,
    formatDuration,
    formatFactor,
    formatNumber,
    formatPercent,
    humanize,
    isCapped,
    maskEmail,
} from './format';
import { READ_ROW_CAP } from './invitations.api';

describe('invitations/format', () => {
    describe('deriveListState — precedence error > loading > empty > ready', () => {
        it('error wins even while loading', () => {
            expect(deriveListState({ isError: true, isLoading: true }, 5)).toBe('error');
        });
        it('loading when not errored and still fetching', () => {
            expect(deriveListState({ isError: false, isLoading: true }, 0)).toBe('loading');
        });
        it('empty when settled with zero rows', () => {
            expect(deriveListState({ isError: false, isLoading: false }, 0)).toBe('empty');
        });
        it('ready when settled with rows', () => {
            expect(deriveListState({ isError: false, isLoading: false }, 3)).toBe('ready');
        });
    });

    describe('isCapped', () => {
        it('false below the cap', () => {
            expect(isCapped(READ_ROW_CAP - 1)).toBe(false);
        });
        it('true at the cap (likely truncated)', () => {
            expect(isCapped(READ_ROW_CAP)).toBe(true);
        });
    });

    describe('formatPercent — R14 non-finite guard', () => {
        it('renders a ratio as one-decimal percent', () => {
            expect(formatPercent(0.75)).toBe('75.0%');
        });
        it('returns dash for null / NaN / Infinity, never NaN%', () => {
            expect(formatPercent(null)).toBe('—');
            expect(formatPercent(Number.NaN)).toBe('—');
            expect(formatPercent(Number.POSITIVE_INFINITY)).toBe('—');
        });
    });

    describe('formatFactor', () => {
        it('two decimals with a multiplier sign', () => {
            expect(formatFactor(3.76470588)).toBe('3.76×');
        });
        it('dash for non-finite', () => {
            expect(formatFactor(null)).toBe('—');
        });
    });

    describe('formatNumber / formatCompact', () => {
        it('formatNumber groups thousands', () => {
            expect(formatNumber(1234)).toBe('1,234');
        });
        it('formatCompact abbreviates', () => {
            expect(formatCompact(1500)).toBe('1.5K');
        });
        it('both guard non-finite', () => {
            expect(formatNumber(Number.NaN)).toBe('—');
            expect(formatCompact(undefined)).toBe('—');
        });
    });

    describe('formatDuration — seconds → coarse units, null sentinel safe', () => {
        it('sub-minute as seconds', () => {
            expect(formatDuration(45)).toBe('45s');
        });
        it('hours and minutes (two largest units)', () => {
            expect(formatDuration(3600 + 30 * 60)).toBe('1h 30m');
        });
        it('days and hours', () => {
            expect(formatDuration(2 * 86400 + 3 * 3600)).toBe('2d 3h');
        });
        it('null (no redemptions yet) → dash, never NaN', () => {
            expect(formatDuration(null)).toBe('—');
            expect(formatDuration(undefined)).toBe('—');
        });
    });

    describe('maskEmail — privacy-preserving (R30 PII discipline)', () => {
        it('keeps only the first local char + the domain', () => {
            expect(maskEmail('john.doe@example.com')).toBe('j•••@example.com');
        });
        it('never returns the full local part', () => {
            expect(maskEmail('alice@corp.io')).not.toContain('alice');
        });
        it('handles empty / malformed input', () => {
            expect(maskEmail(null)).toBe('—');
            expect(maskEmail('not-an-email')).toBe('•••');
        });
    });

    describe('humanize', () => {
        it('replaces underscores with spaces', () => {
            expect(humanize('self_referral')).toBe('self referral');
        });
        it('dash for empty', () => {
            expect(humanize(null)).toBe('—');
        });
    });
});
