import { useQuery } from '@tanstack/react-query';
import { api } from '../../lib/api';
import { useTeamStore } from '../../lib/team-store';

export const RETRIEVAL_PROFILE_STATUS_KEY = 'chat-retrieval-profile-status';
export interface RetrievalProfileStatus {
    required: boolean;
    profiles: Array<{ project_key: string; configured: boolean }>;
}

export function useRetrievalProfileStatus(projectKeys: string[]) {
    const tenant = useTeamStore((s) => s.currentTeam);
    const projects = [...new Set(projectKeys.filter(Boolean))].sort();
    const query = useQuery({
        queryKey: [RETRIEVAL_PROFILE_STATUS_KEY, tenant, projects],
        queryFn: async () => {
            const { data } = await api.get<RetrievalProfileStatus>('/api/kb/chat/retrieval-profile-status', {
                params: { project_keys: projects },
            });
            return data;
        },
        enabled: tenant !== null && projects.length > 0,
        staleTime: 15_000,
        retry: false,
    });
    const missing = query.data?.required ? query.data.profiles.filter((profile) => !profile.configured) : [];
    return { ...query, missing, blocked: missing.length > 0 };
}
