import { describe, expect, it } from 'vitest';
import { NAV_ITEMS } from './nav-config';
import { areaForSection, canAccessNav, NAV_HUBS, navItem, searchNavigation, visibleHubItems, type NavAccess } from './navigation';
import { translateUi } from '../../lib/ui-locale';

const admin: NavAccess = { roles: ['admin'], permissions: [], features: {}, hasTenant: true };
describe('navigation admission and discovery', () => {
    it('gives every non-workspace destination exactly one hub', () => {
        const hubItems = NAV_HUBS.flatMap((hub) => hub.topics.flatMap((topic) => topic.items));
        expect(new Set(hubItems).size).toBe(hubItems.length);
        expect(NAV_ITEMS.filter((item) => areaForSection(item.id) !== 'workspace').map((item) => item.id).sort()).toEqual([...hubItems].sort());
    });
    it('preserves viewer read access without admitting user management', () => {
        const viewer = { ...admin, roles: ['viewer'] };
        expect(canAccessNav(navItem('workflows'), viewer)).toBe(true);
        expect(canAccessNav(navItem('tabular-reviews'), viewer)).toBe(true);
        expect(canAccessNav(navItem('users'), viewer)).toBe(false);
        expect(canAccessNav(navItem('mcp-tools'), viewer)).toBe(false);
    });
    it('preserves DPO and editor specialist admission', () => {
        expect(canAccessNav(navItem('pii-redactor'), { ...admin, roles: ['dpo'] })).toBe(true);
        expect(canAccessNav(navItem('eval-harness'), { ...admin, roles: ['editor'] })).toBe(true);
        expect(canAccessNav(navItem('users'), { ...admin, roles: ['dpo'] })).toBe(false);
    });
    it('does not equate a system-admin role with every tenant or super-admin role', () => {
        const system = { ...admin, roles: ['system-admin'], permissions: ['platform.admin'], features: { system_admin: true }, hasTenant: false };
        expect(canAccessNav(navItem('tenant-control'), system)).toBe(true);
        expect(canAccessNav(navItem('app-settings'), system)).toBe(false);
        expect(canAccessNav(navItem('users'), system)).toBe(false);
    });
    it('reserves global appearance for super-admins, including those without a tenant', () => {
        expect(canAccessNav(navItem('appearance'), { ...admin, roles: ['super-admin'], hasTenant: false })).toBe(true);
        expect(canAccessNav(navItem('appearance'), { ...admin, roles: ['system-admin'], permissions: ['platform.admin'], features: { system_admin: true } })).toBe(false);
        expect(canAccessNav(navItem('appearance'), admin)).toBe(false);
        expect(visibleHubItems(NAV_HUBS.find((hub) => hub.id === 'system')!, { ...admin, roles: ['super-admin'], hasTenant: false }).map((item) => item.id)).toContain('appearance');
    });
    it('keeps inactive features discoverable in hubs while omitting them from search', () => {
        const access = { ...admin, features: { modules: { flows: { enabled: false } } } };
        const automation = NAV_HUBS.find((hub) => hub.id === 'automation')!;
        expect(visibleHubItems(automation, access).map((item) => item.id)).not.toContain('flows');
        expect(visibleHubItems(automation, access, true).map((item) => item.id)).toContain('flows');
        expect(searchNavigation('Flows', access, (text) => text).map((item) => item.id)).not.toContain('flows');
    });
    it('searches actual destinations in both languages and filters permissions', () => {
        const t = (text: string) => translateUi(text, 'it');
        expect(searchNavigation('raccolte', admin, t).map((item) => item.id)).toEqual(['collections']);
        expect(searchNavigation('collections', admin, t).map((item) => item.id)).toEqual(['collections']);
        expect(searchNavigation('MCP Tokens', admin, t)).toHaveLength(0);
        expect(searchNavigation('MCP Tokens', { ...admin, roles: ['super-admin'] }, t).map((item) => item.id)).toContain('mcp-tokens');
    });
});
