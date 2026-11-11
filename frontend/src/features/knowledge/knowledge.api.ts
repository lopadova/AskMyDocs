import { api } from '../../lib/api';

export const knowledgeBlocks = ['types', 'identity', 'admission', 'contributions'] as const;
export type BlockKey = typeof knowledgeBlocks[number];
export type Blocks = Record<BlockKey, Record<string, unknown> | null>;
export interface Revision { id: number; description: string; blocks: Blocks; activated_at: string | null }
export interface SpecificationData { scope: { enabled: boolean; active_revision_id: number } | null; versions: Revision[]; template: Blocks; effective: { revision_id: number; company_revision_id: number | null; blocks: Blocks } | null }
export interface Entity { id: number; type: string; namespace: string; identifier: string; project_key: string }
export interface KnowledgeEvent { id: number; subject?: Entity; value: string; property: string; quote: string; support: string; event_at: string | null; stale_revision: boolean; source: { document_id: number; title: string; section: number }; target: Entity | null; relation: string | null }
export interface EntityContext { entity: Entity; summary: string[]; timeline: KnowledgeEvent[]; relations: { type: string; entity: Entity }[]; next_cursor: number | null; coverage: { partial: boolean; stale_revision: boolean } }
const base = '/api/admin/kb/knowledge-specifications';
export const knowledgeApi = {
    specifications: async (project: string) => (await api.get<SpecificationData>(base, { params: { project_key: project } })).data,
    draft: async (project: string, description: string, blocks: Blocks) => (await api.post<{ revision: Revision }>(`${base}/drafts`, { project_key: project, description, blocks })).data.revision,
    generate: async (description: string) => (await api.post<{ blocks: Blocks }>(`${base}/generate`, { description })).data.blocks,
    activate: async (project: string, revision: number) => (await api.post(`${base}/${revision}/activate`, { project_key: project })).data,
    disable: async (project: string) => (await api.post(`${base}/disable`, { project_key: project })).data,
    preview: async (project: string, revision: number, documentIds: number[]) => (await api.post(`${base}/${revision}/preview`, { project_key: project, document_ids: documentIds })).data,
    reprocess: async (project: string, selection: Record<string, unknown>) => (await api.post(`${base}/reprocess`, { project_key: project, selection })).data,
    runs: async (project: string) => (await api.get<{ runs: { id: number; knowledge_document_id: number; status: string; total_sections: number; processed_sections: number; abstained_sections: number; error: string | null }[] }>(`${base}/runs`, { params: { project_key: project } })).data.runs,
    entities: async (project: string, q: string, namespace = '') => (await api.get<{ entities: Entity[]; coverage: { partial: boolean } }>('/api/kb/knowledge/entities', { params: { project_key: project, q, ...(namespace ? { namespace } : {}) } })).data,
    context: async (project: string, entity: number, cursor?: number) => (await api.get<EntityContext>(`/api/kb/knowledge/entities/${entity}`, { params: { project_key: project, cursor } })).data,
};
