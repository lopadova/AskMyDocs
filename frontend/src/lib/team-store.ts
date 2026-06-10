import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { queryClient } from './query-client';

export type TeamProject = {
    project_key: string;
    role: string;
    scope: unknown;
};

export type Team = {
    tenant_id: string;
    /**
     * Unique URL routing segment (BE-computed, App\Support\TeamHash):
     * every SPA route lives under /app/{hash}/…. The FE never computes
     * hashes — it only matches the ones /api/auth/me delivered.
     */
    hash: string;
    name: string;
    projects: TeamProject[];
};

/*
 * Mirror of App\Support\TenantContext's tenant-id format. A persisted
 * value that fails this check is discarded instead of being sent as an
 * X-Tenant-Id header that ResolveTenant would 400 on.
 */
const TENANT_ID_RE = /^[a-z0-9_-]{1,50}$/;

type TeamState = {
    /** Teams the user may operate in — from `GET /api/auth/me` `teams`. */
    teams: Team[];
    /** tenant_id of the active team. Null until the first syncFromMe. */
    currentTeam: string | null;
    /** Owner of the persisted selection — guards user switches. */
    userId: number | null;
    syncFromMe: (teams: Team[], userId: number) => void;
    switchTeam: (tenantId: string) => void;
    resetToFirstTeam: () => void;
    clear: () => void;
};

function firstTeamId(teams: Team[]): string {
    return teams[0]?.tenant_id ?? 'default';
}

function isSelectable(tenantId: string | null, teams: Team[]): tenantId is string {
    return (
        tenantId !== null &&
        TENANT_ID_RE.test(tenantId) &&
        teams.some((t) => t.tenant_id === tenantId)
    );
}

/*
 * Global team (= tenant) selection for the SPA.
 *
 * - Populated by `useAuthStore.setMe` (the single sync point for both
 *   the bootstrap `/api/auth/me` call and post-login refresh).
 * - Consumed by the axios request interceptor in `lib/api.ts`, which
 *   stamps `X-Tenant-Id` on every non-auth request.
 * - `switchTeam` clears the whole TanStack Query cache: the header is
 *   implicit in every request, so the only safe invalidation is a full
 *   refetch under the new tenant. AppShell additionally keys the route
 *   outlet on `currentTeam`, remounting page-local state.
 *
 * Only `{ userId, currentTeam }` persists (localStorage): the teams list
 * is server truth and re-syncs on every bootstrap. A persisted selection
 * is honoured only if it still belongs to the same user AND still exists
 * in the fresh team list — otherwise it falls back to the first team.
 */
/** Active Team record, or null before the first sync. */
export function selectCurrentTeam(state: Pick<TeamState, 'teams' | 'currentTeam'>): Team | null {
    return state.teams.find((t) => t.tenant_id === state.currentTeam) ?? null;
}

/** Routing hash of the active team (`/app/{hash}/…`), or null pre-sync. */
export function selectCurrentHash(state: Pick<TeamState, 'teams' | 'currentTeam'>): string | null {
    return selectCurrentTeam(state)?.hash ?? null;
}

export const useTeamStore = create<TeamState>()(
    persist(
        (set, get) => ({
            teams: [],
            currentTeam: null,
            userId: null,

            syncFromMe: (teams, userId) => {
                const { currentTeam, userId: storedUserId } = get();
                const sameUser = storedUserId === null || storedUserId === userId;
                const candidate = sameUser ? currentTeam : null;
                set({
                    teams,
                    userId,
                    currentTeam: isSelectable(candidate, teams) ? candidate : firstTeamId(teams),
                });
            },

            switchTeam: (tenantId) => {
                const { teams, currentTeam } = get();
                if (tenantId === currentTeam || !isSelectable(tenantId, teams)) {
                    return;
                }
                set({ currentTeam: tenantId });
                // The tenant header changed under every query: cancel
                // in-flight requests, then drop the cache so every mounted
                // query refetches under the new team.
                void queryClient.cancelQueries();
                queryClient.clear();
            },

            resetToFirstTeam: () => {
                const { teams, currentTeam } = get();
                const fallback = firstTeamId(teams);
                if (currentTeam === fallback) {
                    return;
                }
                set({ currentTeam: fallback });
                void queryClient.cancelQueries();
                queryClient.clear();
            },

            clear: () => set({ teams: [], currentTeam: null, userId: null }),
        }),
        {
            name: 'askmydocs.team',
            partialize: (state) => ({
                userId: state.userId,
                currentTeam: state.currentTeam,
            }),
        },
    ),
);
