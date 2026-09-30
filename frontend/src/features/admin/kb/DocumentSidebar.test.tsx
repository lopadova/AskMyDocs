import { describe, expect, it } from 'vitest';
import { formatDocumentDate } from './DocumentSidebar';

describe('document dates', () => {
    it('formats YAML Unix seconds, milliseconds, and ISO dates consistently', () => {
        for (const value of [1770681600, '1770681600', 1770681600000, '2026-02-10', '2026-02-10T00:00:00Z']) {
            expect(formatDocumentDate(value)).toBe('10 Feb 2026');
        }
    });
    it('preserves unrecognized values and handles missing dates', () => {
        expect(formatDocumentDate(null)).toBe('—');
        expect(formatDocumentDate('unknown')).toBe('unknown');
        expect(formatDocumentDate('not-a-date')).toBe('not-a-date');
    });
});
