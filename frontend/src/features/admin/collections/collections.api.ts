import { api } from '../../../lib/api';

export interface KbCollection {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  visibility: 'private' | 'tenant';
  criteria: Record<string, unknown>;
  semantic_prompt: string | null;
  threshold: number;
  created_at: string;
  updated_at: string;
}

export interface KbCollectionPayload {
  slug: string;
  name: string;
  description: string | null;
  visibility: 'private' | 'tenant';
  criteria: Record<string, unknown> | null;
  semantic_prompt: string | null;
  threshold: number;
}

export interface KbCollectionMember {
  id: number;
  knowledge_document_id: number;
  reason: string;
  semantic_score: number | null;
  manually_excluded: boolean;
  created_at: string;
  document: {
    id: number;
    project_key: string;
    slug: string | null;
    title: string;
  } | null;
}

export async function listCollections(): Promise<KbCollection[]> {
  const response = await api.get<{ data: KbCollection[] }>('/api/admin/kb/collections');
  return response.data.data;
}

export async function createCollection(payload: KbCollectionPayload): Promise<KbCollection> {
  const response = await api.post<{ data: KbCollection }>('/api/admin/kb/collections', payload);
  return response.data.data;
}

export async function updateCollection(id: number, payload: KbCollectionPayload): Promise<KbCollection> {
  const response = await api.patch<{ data: KbCollection }>(`/api/admin/kb/collections/${id}`, payload);
  return response.data.data;
}

export async function deleteCollection(id: number): Promise<void> {
  await api.delete(`/api/admin/kb/collections/${id}`);
}

export async function listCollectionMembers(id: number): Promise<KbCollectionMember[]> {
  const response = await api.get<{ data: KbCollectionMember[] }>(`/api/admin/kb/collections/${id}/members`);
  return response.data.data;
}

export async function addCollectionMember(id: number, knowledgeDocumentId: number): Promise<void> {
  await api.post(`/api/admin/kb/collections/${id}/members`, { knowledge_document_id: knowledgeDocumentId });
}

export async function removeCollectionMember(id: number, knowledgeDocumentId: number): Promise<void> {
  await api.delete(`/api/admin/kb/collections/${id}/members/${knowledgeDocumentId}`);
}

