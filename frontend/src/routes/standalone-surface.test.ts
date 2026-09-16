import { describe, expect, it } from 'vitest';
import { isStandaloneSurface } from './index';

/**
 * Which surfaces render without the app frame. Getting this wrong is
 * visible but silent — either the Sessions view grows a second sidebar,
 * or an admin page loses its navigation entirely — so the boundary is
 * pinned here rather than discovered in a screenshot.
 */
describe('isStandaloneSurface', () => {
    it('covers the sessions workspace and its conversation URLs', () => {
        expect(isStandaloneSurface('/app/h-acme/sessions')).toBe(true);
        expect(isStandaloneSurface('/app/h-acme/sessions/12')).toBe(true);
    });

    it('covers the reader KB browser', () => {
        expect(isStandaloneSurface('/app/h-acme/knowledge')).toBe(true);
    });

    it('leaves the classic chat inside the app frame', () => {
        // /chat keeps the full shell: it is the page reached from the nav,
        // and the entry point INTO the standalone view lives there.
        expect(isStandaloneSurface('/app/h-acme/chat')).toBe(false);
        expect(isStandaloneSurface('/app/h-acme/chat/12')).toBe(false);
        expect(isStandaloneSurface('/app/h-acme/chat/anonymous')).toBe(false);
    });

    it('leaves every admin page inside the app frame', () => {
        expect(isStandaloneSurface('/app/h-acme/admin')).toBe(false);
        expect(isStandaloneSurface('/app/h-acme/admin/kb')).toBe(false);
        // The legacy alias that redirects into the admin explorer.
        expect(isStandaloneSurface('/app/h-acme/kb')).toBe(false);
    });

    it('does not match a segment that merely starts with a standalone name', () => {
        expect(isStandaloneSurface('/app/h-acme/sessions-archive')).toBe(false);
        expect(isStandaloneSurface('/app/h-acme/knowledge-base')).toBe(false);
    });

    it('does not treat the team root or a hash-less URL as standalone', () => {
        expect(isStandaloneSurface('/app/h-acme')).toBe(false);
        expect(isStandaloneSurface('/app/h-acme/')).toBe(false);
        expect(isStandaloneSurface('/app')).toBe(false);
    });
});
