import { describe, expect, it, beforeEach, vi } from 'vitest';
import { useTeamStore, type Team } from './team-store';
import { queryClient } from './query-client';

const TEAMS: Team[] = [
    { tenant_id: 'default', name: 'Default', projects: [] },
    {
        tenant_id: 'acme',
        name: 'Acme Corporation',
        projects: [{ project_key: 'acme-kb', role: 'admin', scope: [] }],
    },
];

beforeEach(() => {
    localStorage.clear();
    useTeamStore.setState({ teams: [], currentTeam: null, userId: null });
    vi.restoreAllMocks();
});

describe('useTeamStore.syncFromMe', () => {
    it('bootstraps to the first team when nothing is persisted', () => {
        useTeamStore.getState().syncFromMe(TEAMS, 1);
        const s = useTeamStore.getState();
        expect(s.currentTeam).toBe('default');
        expect(s.teams).toHaveLength(2);
        expect(s.userId).toBe(1);
    });

    it('keeps a persisted selection that is still valid for the same user', () => {
        useTeamStore.setState({ currentTeam: 'acme', userId: 1 });
        useTeamStore.getState().syncFromMe(TEAMS, 1);
        expect(useTeamStore.getState().currentTeam).toBe('acme');
    });

    it('falls back to the first team when the persisted selection is no longer offered', () => {
        useTeamStore.setState({ currentTeam: 'gone-tenant', userId: 1 });
        useTeamStore.getState().syncFromMe(TEAMS, 1);
        expect(useTeamStore.getState().currentTeam).toBe('default');
    });

    it('discards the persisted selection when a different user logs in', () => {
        useTeamStore.setState({ currentTeam: 'acme', userId: 1 });
        useTeamStore.getState().syncFromMe(TEAMS, 2);
        const s = useTeamStore.getState();
        expect(s.currentTeam).toBe('default');
        expect(s.userId).toBe(2);
    });

    it('discards a corrupted persisted value that fails the tenant-id format', () => {
        useTeamStore.setState({ currentTeam: 'NOT VALID!', userId: 1 });
        useTeamStore.getState().syncFromMe(TEAMS, 1);
        expect(useTeamStore.getState().currentTeam).toBe('default');
    });

    it('falls back to literal default when the BE sends no teams at all', () => {
        useTeamStore.getState().syncFromMe([], 1);
        expect(useTeamStore.getState().currentTeam).toBe('default');
    });
});

describe('useTeamStore.switchTeam', () => {
    it('switches and clears the query cache', () => {
        const cancel = vi.spyOn(queryClient, 'cancelQueries').mockResolvedValue();
        const clear = vi.spyOn(queryClient, 'clear');

        useTeamStore.getState().syncFromMe(TEAMS, 1);
        useTeamStore.getState().switchTeam('acme');

        expect(useTeamStore.getState().currentTeam).toBe('acme');
        expect(cancel).toHaveBeenCalled();
        expect(clear).toHaveBeenCalled();
    });

    it('is a no-op for the already-active team (no cache churn)', () => {
        const clear = vi.spyOn(queryClient, 'clear');
        useTeamStore.getState().syncFromMe(TEAMS, 1);
        useTeamStore.getState().switchTeam('default');
        expect(clear).not.toHaveBeenCalled();
    });

    it('refuses a team that is not in the offered list', () => {
        const clear = vi.spyOn(queryClient, 'clear');
        useTeamStore.getState().syncFromMe(TEAMS, 1);
        useTeamStore.getState().switchTeam('intruder-tenant');
        expect(useTeamStore.getState().currentTeam).toBe('default');
        expect(clear).not.toHaveBeenCalled();
    });
});

describe('useTeamStore.resetToFirstTeam', () => {
    it('snaps back to the first team and clears the cache', () => {
        const clear = vi.spyOn(queryClient, 'clear');
        vi.spyOn(queryClient, 'cancelQueries').mockResolvedValue();

        useTeamStore.getState().syncFromMe(TEAMS, 1);
        useTeamStore.getState().switchTeam('acme');
        clear.mockClear();

        useTeamStore.getState().resetToFirstTeam();
        expect(useTeamStore.getState().currentTeam).toBe('default');
        expect(clear).toHaveBeenCalled();
    });
});
