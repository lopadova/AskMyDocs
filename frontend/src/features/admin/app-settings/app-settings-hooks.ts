import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminAppSettingsApi, type AppSettingDto } from './app-settings.api';

/*
 * TanStack Query hooks over /api/admin/app-settings. The list is keyed by the
 * active project scope; a successful set() writes the server-returned roster
 * straight into the cache (the BE returns the refreshed list).
 */

export const APP_SETTINGS_KEY = ['admin', 'app-settings'] as const;

export function useAppSettings(projectKey: string) {
    return useQuery<AppSettingDto[]>({
        queryKey: [...APP_SETTINGS_KEY, projectKey],
        queryFn: () => adminAppSettingsApi.list(projectKey),
        staleTime: 10_000,
        retry: false,
    });
}

export function useSetAppSetting(projectKey: string) {
    const qc = useQueryClient();
    return useMutation<
        AppSettingDto[],
        Error,
        { key: string; value: string | number | boolean | null }
    >({
        mutationFn: ({ key, value }) => adminAppSettingsApi.set(key, value, projectKey),
        onSuccess: (roster) => {
            // The BE returns the refreshed roster for this scope — seed the cache
            // so the table reflects the new effective value + source immediately.
            qc.setQueryData([...APP_SETTINGS_KEY, projectKey], roster);
            // A change to ANY scope can shift the value a DIFFERENT scope inherits
            // (a tenant '*' change is inherited by every project view) — invalidate
            // the other cached scope rosters so they refetch when next shown.
            qc.invalidateQueries({
                queryKey: APP_SETTINGS_KEY,
                predicate: (q) => q.queryKey[2] !== projectKey,
            });
        },
    });
}
