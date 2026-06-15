import { api } from '../../../lib/api';

/**
 * v8.11/P10 — Wiki Explorer: browse typed wiki pages (auto/human tier) with their
 * backlink + outgoing-edge counts, and the two editorial writes — promote an auto
 * page to the human-vouched tier, and discard (soft-delete) an auto page. Backed
 * by the P10 `WikiExplorerService` endpoints.
 */

export type WikiTier = 'all' | 'auto' | 'human';

export interface WikiPage {
    id: number;
    project_key: string;
    slug: string;
    title: string;
    canonical_type: string | null;
    canonical_status: string | null;
    generation_source: 'auto' | 'human';
    outgoing_edges: number;
    backlinks: number;
    updated_at: string | null;
}

export interface WikiPageList {
    tier: WikiTier;
    project_key: string | null;
    total: number;
    pages: WikiPage[];
}

export interface PromoteResult {
    promoted: boolean;
    reason?: string;
    slug?: string | null;
}

export interface DiscardResult {
    discarded: boolean;
    reason?: string;
    slug?: string | null;
}

export async function listWikiPages(projectKey: string, tier: WikiTier): Promise<WikiPageList> {
    const { data } = await api.get<{ data: WikiPageList }>('/api/admin/kb/wiki-pages', {
        params: { project_key: projectKey, tier },
    });
    return data.data;
}

export async function promoteWikiPage(id: number): Promise<PromoteResult> {
    const { data } = await api.post<{ data: PromoteResult }>(`/api/admin/kb/documents/${id}/wiki-promote`, {});
    return data.data;
}

export async function discardWikiPage(id: number): Promise<DiscardResult> {
    const { data } = await api.post<{ data: DiscardResult }>(`/api/admin/kb/documents/${id}/wiki-discard`, {});
    return data.data;
}
