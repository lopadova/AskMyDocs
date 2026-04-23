import { create } from 'zustand';

export type AuthUser = {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string | null;
};

export type AuthProject = {
    project_key: string;
    role: string;
    scope: unknown;
};

export type AuthMePayload = {
    user: AuthUser;
    roles: string[];
    permissions: string[];
    projects: AuthProject[];
    preferences?: Record<string, string>;
};

type AuthState = {
    user: AuthUser | null;
    roles: string[];
    permissions: string[];
    projects: AuthProject[];
    loading: boolean;
    setMe: (me: AuthMePayload) => void;
    clear: () => void;
    setLoading: (loading: boolean) => void;
};

/*
 * Authoritative client-side auth state. Populated from
 * `GET /api/auth/me` after a successful login or on app mount. PR3's
 * `AuthController@me` returns roles/permissions/projects — treat these
 * as the source of truth for UI gating; the middleware enforces access
 * server-side regardless.
 */
export const useAuthStore = create<AuthState>((set) => ({
    user: null,
    roles: [],
    permissions: [],
    projects: [],
    loading: true,
    setMe: (me) =>
        set({
            user: me.user,
            roles: me.roles,
            permissions: me.permissions,
            projects: me.projects,
            loading: false,
        }),
    clear: () => set({ user: null, roles: [], permissions: [], projects: [], loading: false }),
    setLoading: (loading) => set({ loading }),
}));

export function hasRole(state: Pick<AuthState, 'roles'>, role: string): boolean {
    return state.roles.includes(role);
}

export function hasPermission(state: Pick<AuthState, 'permissions'>, permission: string): boolean {
    return state.permissions.includes(permission);
}
