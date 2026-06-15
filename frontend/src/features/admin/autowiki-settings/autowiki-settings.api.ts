import { api } from '../../../lib/api';

/**
 * v8.11/P10 — per-(tenant, project) Auto-Wiki gate settings (auto-build). Backed
 * by the P10 `KbAutoWikiSettingController` over the shared `kb_analysis_settings`
 * table (autowiki_* columns) + `AutoWikiGate` effective resolution.
 */

export interface AutoWikiFlags {
    enabled: boolean;
    canonical: boolean;
    non_canonical: boolean;
}

/** A nullable override row: null on a field = inherit the next level up. */
export interface AutoWikiOverride {
    enabled: boolean | null;
    canonical: boolean | null;
    non_canonical: boolean | null;
}

export interface AutoWikiSettingEntry {
    project_key: string;
    override: AutoWikiOverride | null;
    effective: AutoWikiFlags;
}

export interface AutoWikiSettingsResponse {
    defaults: AutoWikiFlags;
    wildcard: AutoWikiSettingEntry;
    projects: AutoWikiSettingEntry[];
}

export type AutoWikiFlagKey = keyof AutoWikiFlags;

export const FLAG_KEYS: AutoWikiFlagKey[] = ['enabled', 'canonical', 'non_canonical'];

export const FLAG_LABELS: Record<AutoWikiFlagKey, string> = {
    enabled: 'Auto-build',
    canonical: 'Canonical docs',
    non_canonical: 'Non-canonical docs',
};

/** Map an effective/override flag key to its API column name. */
const COLUMN: Record<AutoWikiFlagKey, 'autowiki_enabled' | 'autowiki_canonical' | 'autowiki_non_canonical'> = {
    enabled: 'autowiki_enabled',
    canonical: 'autowiki_canonical',
    non_canonical: 'autowiki_non_canonical',
};

export async function getAutoWikiSettings(): Promise<AutoWikiSettingsResponse> {
    const { data } = await api.get<AutoWikiSettingsResponse>('/api/admin/kb/autowiki-settings');
    return data;
}

export async function upsertAutoWikiSetting(
    projectKey: string,
    flag: AutoWikiFlagKey,
    value: boolean | null,
): Promise<{ ok: boolean; setting: AutoWikiSettingEntry }> {
    const { data } = await api.put<{ ok: boolean; setting: AutoWikiSettingEntry }>(
        '/api/admin/kb/autowiki-settings',
        { project_key: projectKey, [COLUMN[flag]]: value },
    );
    return data;
}
