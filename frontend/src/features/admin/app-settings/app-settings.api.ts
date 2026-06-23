import { api } from '../../../lib/api';

/*
 * v8.22 (Ciclo 3) — Runtime configuration governance HTTP client. Mirrors
 * `AppSettingsController` (see `routes/api.php` +
 * `app/Http/Controllers/Api/Admin/AppSettingsController.php`) and the
 * `AppSettingsResolver::all()` payload shape (R9 — names match the BE).
 */

export type AppSettingType = 'bool' | 'int' | 'string' | 'enum' | (string & {});

/** Where the effective value comes from after layering. */
export type AppSettingSource = 'config' | 'tenant' | 'project' | (string & {});

export interface AppSettingDto {
    key: string;
    label: string;
    type: AppSettingType;
    /** 'tenant' (one value per tenant) or 'both' (tenant + per-project). */
    scope: 'tenant' | 'both' | (string & {});
    /** Deploy-managed knobs are read-only here (visible, never settable). */
    deploy_only: boolean;
    /** Allowed values for an enum key, else null. */
    enum: string[] | null;
    value: string | number | boolean | null;
    source: AppSettingSource;
}

/** Sentinel project scope meaning "every project in this tenant". */
export const WILDCARD = '*';

export const adminAppSettingsApi = {
    async list(projectKey: string = WILDCARD): Promise<AppSettingDto[]> {
        const { data } = await api.get<{ data: AppSettingDto[] }>('/api/admin/app-settings', {
            params: { project_key: projectKey },
        });
        return data.data;
    },

    async set(
        key: string,
        value: string | number | boolean | null,
        projectKey: string = WILDCARD,
    ): Promise<AppSettingDto[]> {
        const { data } = await api.put<{ data: AppSettingDto[] }>('/api/admin/app-settings', {
            key,
            value,
            project_key: projectKey,
        });
        return data.data;
    },
};
