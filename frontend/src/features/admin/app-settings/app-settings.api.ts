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
    inherited_value?: string | number | boolean | null;
    inherited_source?: AppSettingSource;
    min?: number | null;
    max?: number | null;
}

export type SettingValue = AppSettingDto['value'];
export type SettingsDraft = Record<string, SettingValue>;
export interface AiModelOption {
    id: string;
    name: string;
    input_per_million: number | null;
    output_per_million: number | null;
    currency: string;
    price_source: string | null;
    price_updated_at: string | null;
    context_length: number | null;
    tools_supported: boolean | null;
    selectable: boolean;
    deprecated: boolean;
    created: number;
}
export interface AiModelCatalog {
    provider: string;
    configured: boolean;
    default_model: string | null;
    models: AiModelOption[];
    updated_at: string | null;
    stale: boolean;
    error: 'missing_credentials' | 'credentials_rejected' | 'catalog_unavailable' | null;
}
export interface SettingsProposal {
    summary: string;
    changes: { key: string; value: SettingValue; reason: string }[];
    provider: string;
    model: string;
}

const tenantHeaders = (tenant?: string | null) => tenant ? { headers: { 'X-Tenant-Id': tenant } } : undefined;

/** Sentinel project scope meaning "every project in this tenant". */
export const WILDCARD = '*';

export const adminAppSettingsApi = {
    async list(projectKey: string = WILDCARD, tenant?: string | null): Promise<AppSettingDto[]> {
        const { data } = await api.get<{ data: AppSettingDto[] }>('/api/admin/app-settings', {
            params: { project_key: projectKey },
            ...tenantHeaders(tenant),
        });
        return data.data;
    },

    async setMany(values: SettingsDraft, projectKey: string, tenant?: string | null): Promise<AppSettingDto[]> {
        const { data } = await api.put<{ data: AppSettingDto[] }>('/api/admin/app-settings/batch', { values, project_key: projectKey }, tenantHeaders(tenant));
        return data.data;
    },

    async models(provider: string, tenant?: string | null, refresh = false): Promise<AiModelCatalog> {
        const url = '/api/admin/app-settings/models';
        const response = refresh
            ? await api.post<AiModelCatalog>(`${url}/refresh`, { provider }, tenantHeaders(tenant))
            : await api.get<AiModelCatalog>(url, { params: { provider }, ...tenantHeaders(tenant) });
        return response.data;
    },

    async assist(prompt: string, target: string, draft: SettingsDraft, provider: string, projectKey: string, tenant?: string | null): Promise<SettingsProposal> {
        const { data } = await api.post<SettingsProposal>('/api/admin/app-settings/assist',
            { prompt, target, draft, provider, project_key: projectKey }, tenantHeaders(tenant));
        return data;
    },

    async set(
        key: string,
        value: string | number | boolean | null,
        projectKey: string = WILDCARD,
        tenant?: string | null,
    ): Promise<AppSettingDto[]> {
        const { data } = await api.put<{ data: AppSettingDto[] }>('/api/admin/app-settings', {
            key,
            value,
            project_key: projectKey,
        }, tenantHeaders(tenant));
        return data.data;
    },
};
