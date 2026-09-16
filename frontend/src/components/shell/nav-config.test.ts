import { describe, expect, it } from 'vitest';
import { deriveSection, NAV_ITEMS, SECTION_ROUTES } from './nav-config';

/**
 * The Sessions workspace and the reader Browse KB page sit next to two
 * pre-existing entries whose paths look similar (`chat`, and the legacy
 * `kb` alias that redirects into the admin explorer). deriveSection
 * picks the LONGEST matching route, so these need pinning: a collision
 * would silently highlight the wrong sidebar entry.
 */

/** Stand-in for the router's fuzzy matcher: route or any child of it. */
function matcherFor(path: string) {
    return (route: string): boolean => path === route || path.startsWith(`${route}/`);
}

describe('nav-config route identity', () => {
    it('keeps every nav id unique', () => {
        const ids = NAV_ITEMS.map((i) => i.id);
        expect(new Set(ids).size).toBe(ids.length);
    });

    it('registers the sessions and browse-KB entries with distinct routes', () => {
        expect(SECTION_ROUTES.sessions).toBe('/app/$teamHash/sessions');
        expect(SECTION_ROUTES['kb-browse']).toBe('/app/$teamHash/knowledge');
        // The admin explorer keeps its own id and route.
        expect(SECTION_ROUTES.kb).toBe('/app/$teamHash/admin/kb');
    });

    it('leaves both new entries ungated, like Chat', () => {
        const sessions = NAV_ITEMS.find((i) => i.id === 'sessions');
        const browse = NAV_ITEMS.find((i) => i.id === 'kb-browse');

        // The boundary is per-user ownership and the BE access scope, not
        // a role — a viewer must reach both.
        expect(sessions?.roles).toBeUndefined();
        expect(browse?.roles).toBeUndefined();
        expect(sessions?.feature).toBeUndefined();
        expect(browse?.feature).toBeUndefined();
    });
});

describe('deriveSection', () => {
    it('resolves the sessions list and a single session to Sessions', () => {
        expect(deriveSection(matcherFor('/app/$teamHash/sessions'))).toBe('sessions');
        expect(deriveSection(matcherFor('/app/$teamHash/sessions/12'))).toBe('sessions');
    });

    it('does not let Sessions shadow Chat', () => {
        expect(deriveSection(matcherFor('/app/$teamHash/chat'))).toBe('chat');
        expect(deriveSection(matcherFor('/app/$teamHash/chat/12'))).toBe('chat');
        expect(deriveSection(matcherFor('/app/$teamHash/chat/anonymous'))).toBe('chat');
    });

    it('resolves the reader KB page to Browse KB', () => {
        expect(deriveSection(matcherFor('/app/$teamHash/knowledge'))).toBe('kb-browse');
    });

    it('never lets the reader page claim the legacy kb alias', () => {
        // `/app/$teamHash/kb` is a redirect into /admin/kb that
        // admin-sidebar-nav.spec.ts asserts. No nav entry owns the alias
        // path itself, so it highlights nothing — what matters is that it
        // does NOT resolve to the new reader page.
        expect(deriveSection(matcherFor('/app/$teamHash/kb'))).not.toBe('kb-browse');
        // The admin explorer's own route still resolves to it.
        expect(deriveSection(matcherFor('/app/$teamHash/admin/kb'))).toBe('kb');
    });

    it('still returns null for a route no nav entry owns', () => {
        expect(deriveSection(matcherFor('/app/$teamHash/unclaimed'))).toBeNull();
    });
});
