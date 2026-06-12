import { describe, it, expect } from 'vitest';
import { sanitizeText, clamp, clean } from './sanitize';

describe('widget sanitize', () => {
    it('strips angle brackets so DOM text cannot inject markup', () => {
        expect(sanitizeText('<script>alert(1)</script>')).toBe('script alert(1) /script');
    });

    it('neutralises code fences so DOM text cannot impersonate a code block', () => {
        expect(sanitizeText('```js\nevil\n```')).toContain('js evil');
        expect(sanitizeText('```')).not.toContain('```');
    });

    it('removes zero-width characters', () => {
        expect(sanitizeText('a​b⁠c')).toBe('abc');
    });

    it('collapses whitespace and trims', () => {
        expect(sanitizeText('  a   b\n\tc  ')).toBe('a b c');
    });

    it('coerces null/undefined to an empty string', () => {
        expect(sanitizeText(null)).toBe('');
        expect(sanitizeText(undefined)).toBe('');
    });

    it('clamp truncates to the cap', () => {
        expect(clamp('abcdef', 3)).toBe('abc');
        expect(clamp('ab', 3)).toBe('ab');
    });

    it('clean sanitizes then clamps', () => {
        expect(clean('  <b>hello</b>  world  ', 5)).toBe('b hel');
    });
});
