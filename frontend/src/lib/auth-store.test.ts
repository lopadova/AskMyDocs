import { describe, expect, it, beforeEach } from 'vitest';
import { useAuthStore, hasRole, hasPermission } from './auth-store';

beforeEach(() => {
    useAuthStore.getState().clear();
    useAuthStore.setState({ loading: true });
});

describe('useAuthStore', () => {
    it('starts empty with loading=true', () => {
        const s = useAuthStore.getState();
        expect(s.user).toBeNull();
        expect(s.roles).toHaveLength(0);
        expect(s.loading).toBe(true);
    });

    it('setMe populates user/roles/permissions/projects and clears loading', () => {
        useAuthStore.getState().setMe({
            user: { id: 1, name: 'Elena', email: 'elena@acme.io' },
            roles: ['super-admin'],
            permissions: ['kb.read.any'],
            projects: [{ project_key: 'hr-portal', label: 'Hr Portal', role: 'admin', scope: null, doc_count: 12 }],
        });
        const s = useAuthStore.getState();
        expect(s.user?.name).toBe('Elena');
        expect(s.roles).toEqual(['super-admin']);
        expect(s.permissions).toEqual(['kb.read.any']);
        expect(s.projects).toHaveLength(1);
        expect(s.projects[0].label).toBe('Hr Portal');
        expect(s.projects[0].doc_count).toBe(12);
        expect(s.loading).toBe(false);
    });

    it('clear resets to empty and marks loading=false', () => {
        useAuthStore.getState().setMe({
            user: { id: 1, name: 'x', email: 'x@x' },
            roles: ['admin'],
            permissions: [],
            projects: [],
        });
        useAuthStore.getState().clear();
        const s = useAuthStore.getState();
        expect(s.user).toBeNull();
        expect(s.roles).toHaveLength(0);
        expect(s.loading).toBe(false);
    });

    it('hasRole/hasPermission helpers', () => {
        const state = { roles: ['admin'], permissions: ['kb.read.any'] };
        expect(hasRole(state, 'admin')).toBe(true);
        expect(hasRole(state, 'super-admin')).toBe(false);
        expect(hasPermission(state, 'kb.read.any')).toBe(true);
        expect(hasPermission(state, 'nope')).toBe(false);
    });
});
