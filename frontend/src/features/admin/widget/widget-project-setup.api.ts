import { api } from '../../../lib/api';
import type { WidgetIntro, WidgetMode, WidgetTheme } from '../../../widget/types';
import type { UploadBatch } from '../kb/upload/kb-upload.api';

export interface WidgetSetupInput {
    name: string;
    project_key: string;
    user_auth_enabled: boolean;
    language: string;
    audience: string;
    topics: string;
}

export interface WidgetSetupSummary extends WidgetSetupInput {
    id: number;
    widget_key_id: number | null;
}

export interface WidgetSetupKey {
    id: number;
    label: string;
    project_key: string;
    public_key: string;
    allowed_origins: string[];
    is_active: boolean;
    user_auth_enabled: boolean;
    theme: WidgetTheme;
    intro: WidgetIntro | null;
}

export interface WidgetSetup extends WidgetSetupSummary {
    indexed_documents: number;
    is_indexing: boolean;
    failures: { path: string; error: string | null }[];
    batches: Pick<UploadBatch, 'id' | 'status' | 'sub_path'>[];
    key: WidgetSetupKey | null;
}

export interface WidgetSetupKeyInput {
    label: string;
    mode: WidgetMode;
    allowed_origins: string[];
    accept_partial: boolean;
}

const base = '/api/admin/widget-setups';

export const widgetSetupApi = {
    async list(): Promise<WidgetSetupSummary[]> {
        return (await api.get<{ data: WidgetSetupSummary[] }>(base)).data.data;
    },
    async get(id: number): Promise<WidgetSetup> {
        return (await api.get<{ data: WidgetSetup }>(`${base}/${id}`)).data.data;
    },
    async create(input: WidgetSetupInput): Promise<WidgetSetupSummary> {
        return (await api.post<{ data: WidgetSetupSummary }>(base, input)).data.data;
    },
    async issue(id: number, input: WidgetSetupKeyInput): Promise<{ data: WidgetSetupKey; identity_plain_secret?: string | null }> {
        return (await api.post<{ data: WidgetSetupKey; identity_plain_secret?: string | null }>(`${base}/${id}/key`, input)).data;
    },
};
