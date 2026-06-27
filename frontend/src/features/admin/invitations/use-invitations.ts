import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    invitationsApi,
    type AbuseFilter,
    type CodesFilter,
    type GenerateCodesPayload,
    type MetricsFilter,
    type ReferralsFilter,
    type RewardsFilter,
    type WaitlistFilter,
} from './invitations.api';

/*
 * TanStack Query hooks for the native Invitations admin. Query keys are
 * tuple-shaped so the cache partitions on the active filters (changing a
 * filter is a new cache entry, never a stale read). Mutations invalidate the
 * whole `['admin', 'invitations']` partition so the inventory list AND the
 * Overview metrics both refetch after a generate/revoke (codes_issued and
 * conversion_rate move together). No optimistic updates — admin correctness
 * over latency, same posture as users.api.ts.
 */

export const INVITATIONS_KEY = ['admin', 'invitations'] as const;

const STALE = 30_000;

export function useInviteMetrics(filter: MetricsFilter = {}) {
    return useQuery({
        queryKey: [...INVITATIONS_KEY, 'metrics', filter.campaign_id ?? null, filter.since_days ?? null],
        queryFn: () => invitationsApi.getMetrics(filter),
        staleTime: STALE,
    });
}

export function useCampaigns() {
    return useQuery({
        queryKey: [...INVITATIONS_KEY, 'campaigns'],
        queryFn: () => invitationsApi.listCampaigns(),
        staleTime: STALE,
    });
}

export function useCodes(filter: CodesFilter = {}) {
    return useQuery({
        queryKey: [...INVITATIONS_KEY, 'codes', filter.campaign_id ?? null, filter.state ?? null],
        queryFn: () => invitationsApi.listCodes(filter),
        staleTime: STALE,
    });
}

export function useReferrals(filter: ReferralsFilter = {}) {
    return useQuery({
        queryKey: [...INVITATIONS_KEY, 'referrals', filter.campaign_id ?? null, filter.status ?? null],
        queryFn: () => invitationsApi.listReferrals(filter),
        staleTime: STALE,
    });
}

export function useRewards(filter: RewardsFilter = {}) {
    return useQuery({
        queryKey: [...INVITATIONS_KEY, 'rewards', filter.state ?? null, filter.party ?? null],
        queryFn: () => invitationsApi.listRewards(filter),
        staleTime: STALE,
    });
}

export function useWaitlist(filter: WaitlistFilter = {}) {
    return useQuery({
        queryKey: [...INVITATIONS_KEY, 'waitlist', filter.status ?? null],
        queryFn: () => invitationsApi.listWaitlist(filter),
        staleTime: STALE,
    });
}

export function useAbuseSignals(filter: AbuseFilter = {}) {
    return useQuery({
        queryKey: [...INVITATIONS_KEY, 'abuse', filter.severity ?? null, filter.action ?? null],
        queryFn: () => invitationsApi.listAbuseSignals(filter),
        staleTime: STALE,
    });
}

export function useGenerateCodes() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (payload: GenerateCodesPayload) => invitationsApi.generateCodes(payload),
        onSuccess: () => qc.invalidateQueries({ queryKey: INVITATIONS_KEY }),
    });
}

export function useRevokeCode() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (id: number) => invitationsApi.revokeCode(id),
        onSuccess: () => qc.invalidateQueries({ queryKey: INVITATIONS_KEY }),
    });
}
