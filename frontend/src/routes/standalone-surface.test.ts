import { describe, expect, it } from 'vitest';
import { isStandaloneSurface } from './index';

/**
 * The former standalone policy stays import-compatible while every
 * authenticated surface retains the shared application navigation.
 */
describe('isStandaloneSurface', () => {
    it('keeps the sessions workspace and conversation URLs in the common frame', () => {
        expect(isStandaloneSurface('/app/h-acme/sessions')).toBe(false);
        expect(isStandaloneSurface('/app/h-acme/sessions/12')).toBe(false);
    });

    it('keeps the reader library in the common frame', () => {
        expect(isStandaloneSurface('/app/h-acme/knowledge')).toBe(false);
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
