import { api } from '../../../lib/api';

export interface TriageOption { key: string; label: string; criterion: string; text: string; tag: string | null }
export interface TriageRule { key: string; label: string; type: 'noul' | 'choice' | 'score'; enabled: boolean; instructions: string; threshold: number; options: TriageOption[] }
export interface TriageConfig { enabled: boolean; rules: TriageRule[] }
export interface TriageProfile extends TriageConfig { id: number; revision: number; model: string }
export interface TriageCampaign { id: number; status: string; profile_id: number; selected: number; unavailable: number; counts: Record<string, number>; items?: { id: number; knowledge_document_id: number; status: string; error_code: string | null }[] }
export interface TriageCoverage { partial: boolean; body_truncated: boolean; attachments: { name: string; reason: string; characters?: number }[] }
export interface TriageStatus {
    current_model?: string;
    profile: TriageProfile | null;
    versions: { id: number; revision: number; enabled: boolean; model: string }[];
    readiness: { credentials: boolean; worker: boolean; asynchronous_queue: boolean };
    counts: Record<string, number>;
    campaign: TriageCampaign | null;
    sample_documents: { id: number; title: string }[];
    messages: { id: number; subject: string | null; status: string; stale: boolean; coverage: TriageCoverage | null; index_status: string | null; indexed_revision: number | null; error_code: string | null }[];
}
export interface TriagePreview { card: string; coverage: TriageCoverage; tags: string[]; latency_ms: number; usage: { cost?: number; input_tokens?: number }; result: { fields: { key: string; label: string; text: string; confidence: number }[] } }
export interface TriageSuggestion { summary: string; rules: TriageRule[]; examples: { email: string; expected_tags: string[] }[]; model: string; latency_ms: number; usage: { input_tokens: number | null; output_tokens: number | null } }
export interface CampaignFilters { project: string; mailbox: string; from: string; to: string; state: 'never' | 'stale' | 'failed' | 'all' }
export interface CampaignPreview { total: number; profile_id: number; availability_sample: { checked: number; available: number; unavailable: number; unchecked: number }; estimated_decision_usd: number | null; estimated_reindex_usd: number | null }

const root = (id: number) => `/api/admin/connectors/${id}/email-triage`;
async function post<T>(path: string, payload: unknown): Promise<T> { return (await api.post<{ data: T }>(path, payload)).data.data; }
export const emailTriageApi = {
    status: async (id: number): Promise<TriageStatus> => (await api.get<{ data: TriageStatus }>(root(id))).data.data,
    save: async (id: number, config: TriageConfig): Promise<TriageProfile> => (await api.put<{ data: TriageProfile }>(root(id), config)).data.data,
    suggest: (id: number, prompt: string, profile: TriageConfig) => post<TriageSuggestion>(`${root(id)}/suggest`, { prompt, profile }),
    preview: (id: number, documentId: number, profile: TriageConfig) => post<TriagePreview>(`${root(id)}/preview`, { document_id: documentId, profile }),
    campaignPreview: (id: number, filters: CampaignFilters) => post<CampaignPreview>(`${root(id)}/campaign-preview`, filters),
    start: (id: number, filters: CampaignFilters, profileId: number) => post<TriageCampaign>(`${root(id)}/campaigns`, { ...filters, profile_id: profileId }),
    control: (id: number, campaignId: number, action: 'pause' | 'resume') => post<TriageCampaign>(`${root(id)}/campaigns/${campaignId}`, { action }),
    retry: (id: number, messageId: number) => post<{ status: string }>(`${root(id)}/messages/${messageId}/retry`, {}),
    retryItem: (id: number, campaignId: number, itemId: number) => post<{ status: string }>(`${root(id)}/campaigns/${campaignId}/items/${itemId}/retry`, {}),
};
