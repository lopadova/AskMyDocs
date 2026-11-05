import { api } from '../../../lib/api';

export interface RetrievalGlossaryEntry {
    term: string;
    meaning: string;
    aliases?: string[];
}

export interface RetrievalProfile {
    project_key: string;
    configured: boolean;
    company_context: string;
    glossary: RetrievalGlossaryEntry[];
    relevant_entities: string[];
    expected_facts: string[];
    preferred_source_types: string[];
    updated_at: string | null;
}

export interface RetrievalProfilesResponse {
    profiles: RetrievalProfile[];
}

export interface UpsertRetrievalProfileInput {
    project_key: string;
    company_context: string;
    glossary: RetrievalGlossaryEntry[];
    relevant_entities: string[];
    expected_facts: string[];
    preferred_source_types: string[];
}

export async function getRetrievalProfiles(): Promise<RetrievalProfilesResponse> {
    const { data } = await api.get<RetrievalProfilesResponse>('/api/admin/kb/retrieval-profiles');
    return data;
}

export async function upsertRetrievalProfile(input: UpsertRetrievalProfileInput): Promise<RetrievalProfile> {
    const { data } = await api.put<{ ok: boolean; profile: RetrievalProfile }>(
        '/api/admin/kb/retrieval-profiles',
        input,
    );
    return data.profile;
}
