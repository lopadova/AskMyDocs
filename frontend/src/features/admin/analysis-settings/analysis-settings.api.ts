import { api } from '../../../lib/api';

/**
 * v8.8/W3 — per-(tenant, project) AI deep-analysis gate settings.
 */

export interface AnalysisFlags {
    enabled: boolean;
    canonical: boolean;
    non_canonical: boolean;
    delete_enabled: boolean;
}

/** A nullable override row: null on a field = inherit the next level up. */
export interface AnalysisOverride {
    enabled: boolean | null;
    canonical: boolean | null;
    non_canonical: boolean | null;
    delete_enabled: boolean | null;
}

export interface AnalysisSettingEntry {
    project_key: string;
    override: AnalysisOverride | null;
    effective: AnalysisFlags;
}

export interface AnalysisSettingsResponse {
    defaults: AnalysisFlags;
    wildcard: AnalysisSettingEntry;
    projects: AnalysisSettingEntry[];
}

export type AnalysisFlagKey = keyof AnalysisFlags;

export const FLAG_KEYS: AnalysisFlagKey[] = ['enabled', 'canonical', 'non_canonical', 'delete_enabled'];

export const FLAG_LABELS: Record<AnalysisFlagKey, string> = {
    enabled: 'Enabled',
    canonical: 'Canonical docs',
    non_canonical: 'Non-canonical docs',
    delete_enabled: 'On delete',
};

export async function getAnalysisSettings(): Promise<AnalysisSettingsResponse> {
    const { data } = await api.get<AnalysisSettingsResponse>('/api/admin/kb/analysis-settings');
    return data;
}

export interface UpsertAnalysisSettingPayload {
    project_key: string;
    enabled?: boolean | null;
    canonical?: boolean | null;
    non_canonical?: boolean | null;
    delete_enabled?: boolean | null;
}

export async function upsertAnalysisSetting(
    payload: UpsertAnalysisSettingPayload,
): Promise<{ ok: boolean; setting: AnalysisSettingEntry }> {
    const { data } = await api.put('/api/admin/kb/analysis-settings', payload);
    return data;
}
