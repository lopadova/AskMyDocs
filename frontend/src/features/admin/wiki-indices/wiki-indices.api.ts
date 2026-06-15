import { api } from '../../../lib/api';

/**
 * v8.11/P10 — Auto-Wiki indices: the per-tenant index hub + per-project roll-ups
 * + the append-only operation log. Backed by the P4 `WikiIndexBuilder` endpoints
 * (GET/POST /api/admin/kb/wiki-index, GET /api/admin/kb/wiki-operations).
 */

export interface WikiHubPayload {
    project_count: number;
    total_pages: number;
    total_concepts: number;
    projects: Array<{
        project_key: string;
        page_total: number;
        concept_count: number;
        auto_count: number;
        human_count: number;
    }>;
    built_at?: string;
}

export interface WikiProjectPayload {
    page_counts_by_type: Record<string, number>;
    page_total: number;
    concept_count: number;
    auto_count: number;
    human_count: number;
    recently_changed: Array<{ slug: string; title: string; type: string; generation_source: string }>;
    built_at?: string;
}

export interface WikiHubEntry {
    project_key: string;
    index_type: string;
    payload: WikiHubPayload;
    updated_at: string | null;
}

export interface WikiProjectEntry {
    project_key: string;
    index_type: string;
    payload: WikiProjectPayload;
    updated_at: string | null;
}

export interface WikiIndexHub {
    hub: WikiHubEntry | null;
    projects: WikiProjectEntry[];
}

export interface WikiOperation {
    id: number;
    project_key: string;
    doc_id: string | null;
    slug: string | null;
    event_type: string;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
}

export interface WikiRebuildResult {
    projects: string[];
    hub_project_count: number;
}

export async function fetchWikiIndex(): Promise<WikiIndexHub> {
    const { data } = await api.get<{ data: WikiIndexHub }>('/api/admin/kb/wiki-index');
    return data.data;
}

export async function fetchWikiOperations(limit = 50): Promise<WikiOperation[]> {
    const { data } = await api.get<{ data: WikiOperation[] }>('/api/admin/kb/wiki-operations', {
        params: { limit },
    });
    return data.data;
}

export async function rebuildWikiIndex(): Promise<WikiRebuildResult> {
    const { data } = await api.post<{ data: WikiRebuildResult }>('/api/admin/kb/wiki-index', {});
    return data.data;
}
