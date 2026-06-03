import { api } from '../../lib/api';

/**
 * v8.8/W6 — chat-side related-graph: 1-hop kb_edges neighbours of the
 * canonical docs an answer cited.
 */

export interface RelatedNode {
    slug: string;
    title: string | null;
    edge_type: string;
    direction: 'incoming' | 'outgoing';
    weight: number;
}

export interface RelatedResponse {
    related: RelatedNode[];
    meta: { count: number };
}

export async function getRelated(projectKey: string, slugs: string[]): Promise<RelatedResponse> {
    const qs = new URLSearchParams();
    qs.set('project_key', projectKey);
    for (const s of slugs) qs.append('slugs[]', s);
    const { data } = await api.get<RelatedResponse>(`/api/kb/related?${qs.toString()}`);
    return data;
}
