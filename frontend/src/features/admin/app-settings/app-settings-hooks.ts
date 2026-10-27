import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminAppSettingsApi, type AppSettingDto, type SettingsDraft } from './app-settings.api';
import { useTeamStore } from '../../../lib/team-store';

/*
 * TanStack Query hooks over /api/admin/app-settings. The list is keyed by the
 * active project scope; a successful set() writes the server-returned roster
 * straight into the cache (the BE returns the refreshed list).
 */

export const APP_SETTINGS_KEY = ['admin', 'app-settings'] as const;

export function useAppSettings(projectKey: string) {
    const tenant = useTeamStore((state) => state.currentTeam);
    return useQuery<AppSettingDto[]>({
        queryKey: [...APP_SETTINGS_KEY, tenant, projectKey],
        queryFn: () => adminAppSettingsApi.list(projectKey, tenant),
        staleTime: 10_000,
        retry: false,
    });
}

export function useSetAppSetting(projectKey: string) {
    const tenant = useTeamStore((state) => state.currentTeam);
    const qc = useQueryClient();
    return useMutation<
        AppSettingDto[],
        Error,
        { key: string; value: string | number | boolean | null },
        { scope: string; tenant: string | null }
    >({
        mutationFn: ({ key, value }) => adminAppSettingsApi.set(key, value, projectKey, tenant),
        // Snapshot the scope the PUT was issued for — onMutate runs synchronously
        // at mutate() time, so if the user changes the scope input while the
        // request is in flight, onSuccess still writes the CORRECT cache entry
        // (not the now-current closure value).
        onMutate: () => ({ scope: projectKey, tenant }),
        onSuccess: (roster, _vars, ctx) => {
            const scope = ctx.scope;
            // The BE returns the refreshed roster for this scope — seed the cache
            // so the table reflects the new effective value + source immediately.
            qc.setQueryData([...APP_SETTINGS_KEY, ctx.tenant, scope], roster);
            // A change to ANY scope can shift the value a DIFFERENT scope inherits
            // (a tenant '*' change is inherited by every project view) — invalidate
            // the other cached scope rosters so they refetch when next shown.
            qc.invalidateQueries({
                queryKey: APP_SETTINGS_KEY,
                predicate: (q) => q.queryKey[2] === ctx.tenant && q.queryKey[3] !== scope,
            });
        },
    });
}

export function useSetAppSettings(projectKey: string) {
    const tenant = useTeamStore((state) => state.currentTeam);
    const client = useQueryClient();
    return useMutation({
        mutationFn: (values: SettingsDraft) => adminAppSettingsApi.setMany(values, projectKey, tenant),
        onMutate: () => ({ tenant, projectKey }),
        onSuccess: (data, _values, context) => {
            client.setQueryData([...APP_SETTINGS_KEY, context.tenant, context.projectKey], data);
            void client.invalidateQueries({ queryKey: APP_SETTINGS_KEY,
                predicate: (query) => query.queryKey[2] === context.tenant && query.queryKey[3] !== context.projectKey });
        },
    });
}

export function useAiModelCatalog(provider: string) {
    const tenant = useTeamStore((state) => state.currentTeam);
    const client = useQueryClient();
    const key = ['settings-model-catalog', tenant, provider];
    const query = useQuery({ queryKey: key, queryFn: () => adminAppSettingsApi.models(provider, tenant),
        staleTime: 60_000, retry: false });
    const refresh = useMutation({ mutationFn: () => adminAppSettingsApi.models(provider, tenant, true),
        onMutate: () => ({ tenant, provider }),
        onSuccess: (data, _values, context) => client.setQueryData(['settings-model-catalog', context.tenant, context.provider], data) });
    return { ...query, refresh };
}
