import { api } from '../../../lib/api';

/**
 * v8.7/W3–W4 — read-only admin client for AI document-change analyses.
 */

export interface CrossReference {
    slug: string;
    title: string;
    why: string;
}

export interface ImpactedDoc {
    slug: string;
    title: string;
    impact: string;
    suggested_action: string;
}

export interface AnalysisJson {
    enhancement_suggestions: string[];
    cross_references: CrossReference[];
    impacted_docs: ImpactedDoc[];
}

export interface DocAnalysis {
    id: number;
    project_key: string;
    knowledge_document_id: number;
    document_title: string | null;
    doc_slug: string | null;
    trigger: 'ingested' | 'modified';
    analysis_json: AnalysisJson;
    suggestion_count: number;
    impacted_count: number;
    status: 'completed' | 'failed';
    provider: string | null;
    model: string | null;
    error: string | null;
    created_at: string | null;
}

export interface AnalysesPage {
    data: DocAnalysis[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export async function listAnalyses(params?: { projectKeys?: string[]; status?: string }): Promise<AnalysesPage> {
    const search = new URLSearchParams();
    for (const k of params?.projectKeys ?? []) {
        search.append('project_keys[]', k);
    }
    if (params?.status) {
        search.append('status', params.status);
    }
    const url = '/api/admin/kb/analyses' + (search.toString() ? `?${search.toString()}` : '');
    const { data } = await api.get<AnalysesPage>(url);
    return data;
}
