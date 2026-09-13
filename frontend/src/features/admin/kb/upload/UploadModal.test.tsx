import { describe, expect, it } from 'vitest';
import { acceptAttribute } from './UploadModal';

/*
 * R18 — the picker's `accept` is DERIVED from the extensions the server
 * delivers on /api/auth/me, never a literal list kept in the SPA.
 */
describe('acceptAttribute', () => {
    it('maps the server extensions to a native accept list', () => {
        expect(acceptAttribute(['md', 'markdown', 'pdf'])).toBe('.md,.markdown,.pdf');
    });

    it('tolerates dotted, padded and upper-case entries', () => {
        expect(acceptAttribute([' .PNG ', 'jpg', ''])).toBe('.png,.jpg');
    });

    it('leaves the picker unfiltered until the server has delivered the list', () => {
        expect(acceptAttribute([])).toBeUndefined();
    });
});
