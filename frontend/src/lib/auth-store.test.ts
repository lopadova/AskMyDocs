import { afterEach, describe, expect, it, beforeEach } from 'vitest';
import { useAuthStore, hasRole, hasPermission } from './auth-store';

let originalIdentity: string | null;
let originalMode: string | null;

beforeEach(() => {
    originalIdentity = document.documentElement.getAttribute('data-design-theme');
    originalMode = document.documentElement.getAttribute('data-theme');
    useAuthStore.getState().clear();
    useAuthStore.setState({ loading: true });
});

afterEach(() => {
    if (originalIdentity === null) document.documentElement.removeAttribute('data-design-theme');
    else document.documentElement.setAttribute('data-design-theme', originalIdentity);
    if (originalMode === null) document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', originalMode);
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
            projects: [{ project_key: 'hr-portal', role: 'admin', scope: null }],
            onboarding: { required: true, can_create_company: true },
        });
        const s = useAuthStore.getState();
        expect(s.user?.name).toBe('Elena');
        expect(s.roles).toEqual(['super-admin']);
        expect(s.permissions).toEqual(['kb.read.any']);
        expect(s.projects).toHaveLength(1);
        expect(s.onboarding.required).toBe(true);
        expect(s.loading).toBe(false);
    });

    it('setMe keeps the server-delivered KB upload extensions and clear drops them (R18)', () => {
        useAuthStore.getState().setMe({
            user: { id: 1, name: 'x', email: 'x@x' },
            roles: [],
            permissions: [],
            projects: [],
            kb_upload: { accepted_extensions: ['md', 'pdf', 'png'] },
        });
        expect(useAuthStore.getState().kbUploadAcceptedExtensions).toEqual(['md', 'pdf', 'png']);
        useAuthStore.getState().setMe({ user: { id: 1, name: 'x', email: 'x@x' }, roles: [], permissions: [], projects: [] });
        expect(useAuthStore.getState().kbUploadAcceptedExtensions).toEqual([]);
        useAuthStore.getState().clear();
        expect(useAuthStore.getState().kbUploadAcceptedExtensions).toEqual([]);
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
        expect(s.onboarding.required).toBe(false);
        expect(s.loading).toBe(false);
    });

    it('hasRole/hasPermission helpers', () => {
        const state = { roles: ['admin'], permissions: ['kb.read.any'] };
        expect(hasRole(state, 'admin')).toBe(true);
        expect(hasRole(state, 'super-admin')).toBe(false);
        expect(hasPermission(state, 'kb.read.any')).toBe(true);
        expect(hasPermission(state, 'nope')).toBe(false);
    });

    it('applies the installation identity from me while preserving the personal light/dark choice', () => {
        document.documentElement.dataset.theme = 'light';
        document.documentElement.dataset.designTheme = 'humanistic';
        useAuthStore.getState().setMe({
            user: { id: 1, name: 'Elena', email: 'elena@acme.io' }, roles: [], permissions: [], projects: [],
            preferences: { theme: 'dark' }, appearance: { theme: 'tech-pro' },
        });
        expect(document.documentElement).toHaveAttribute('data-design-theme', 'tech-pro');
        expect(document.documentElement).toHaveAttribute('data-theme', 'light');
        expect(useAuthStore.getState().loading).toBe(false);
    });

    it('preserves the server-rendered identity for old or invalid me payloads', () => {
        document.documentElement.dataset.designTheme = 'luxury';
        const identity = { user: { id: 1, name: 'Elena', email: 'elena@acme.io' }, roles: [], permissions: [], projects: [] };
        useAuthStore.getState().setMe(identity);
        expect(document.documentElement).toHaveAttribute('data-design-theme', 'luxury');
        useAuthStore.getState().setMe({ ...identity, appearance: { theme: 'unknown-theme' } });
        expect(document.documentElement).toHaveAttribute('data-design-theme', 'luxury');
    });

    it('keeps the installation identity on logout for guest authentication screens', () => {
        document.documentElement.dataset.theme = 'light';
        useAuthStore.getState().setMe({
            user: { id: 1, name: 'Elena', email: 'elena@acme.io' }, roles: ['super-admin'], permissions: [], projects: [],
            appearance: { theme: 'retro-future' },
        });
        useAuthStore.getState().clear();
        expect(useAuthStore.getState().user).toBeNull();
        expect(document.documentElement).toHaveAttribute('data-design-theme', 'retro-future');
        expect(document.documentElement).toHaveAttribute('data-theme', 'light');
    });
});
