import { afterEach, describe, expect, it } from 'vitest';
import { useTeamStore } from '../../lib/team-store';
import { workbenchHeaders } from './workbench-transport';

describe('workbenchHeaders', () => {
    afterEach(() => {
        useTeamStore.setState({ currentTeam: null });
        document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/';
    });

    it('uses the current tenant and the latest Sanctum XSRF cookie', () => {
        useTeamStore.setState({ currentTeam: 'acme' });
        document.cookie = 'XSRF-TOKEN=fresh%3Dtoken; Path=/';

        expect(workbenchHeaders()).toEqual({
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': 'fresh=token',
            'X-Tenant-Id': 'acme',
        });
    });

    it('does not invent a tenant or CSRF token before they exist', () => {
        expect(workbenchHeaders()).toEqual({
            'X-Requested-With': 'XMLHttpRequest',
        });
    });
});
