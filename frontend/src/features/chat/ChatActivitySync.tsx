import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore } from '../../lib/team-store';
import { getAgentChatSession } from './agent-chat-session';
import { chatApi } from './chat.api';

/** Recover server-owned activity even when the app opens outside the chat. */
export function ChatActivitySync() {
    const userId = useAuthStore((state) => state.user?.id ?? null);
    const tenantId = useTeamStore((state) => state.currentTeam);
    const teamUserId = useTeamStore((state) => state.userId);
    const qc = useQueryClient();
    const { data, dataUpdatedAt } = useQuery({
        queryKey: ['chat-activity', userId, tenantId],
        queryFn: () => chatApi.listActivity(tenantId!),
        enabled: userId !== null && userId === teamUserId && tenantId !== null,
        staleTime: 0,
        refetchOnWindowFocus: true,
        refetchOnReconnect: true,
        refetchInterval: (query) => query.state.data?.some((item) => item.run
            && !['completed', 'partial', 'failed', 'cancelled'].includes(item.run.status)) ? 5_000 : 30_000,
    });

    useEffect(() => {
        if (!data || userId === null || tenantId === null || userId !== teamUserId) return;
        const ownsCurrentScope = () => useTeamStore.getState().userId === userId
            && useTeamStore.getState().currentTeam === tenantId;
        if (!ownsCurrentScope()) return;
        for (const activity of data) {
            getAgentChatSession(activity.conversation_id, userId, tenantId).restore(activity, () => {
                if (!ownsCurrentScope()) return;
                void qc.invalidateQueries({ queryKey: ['conversations'] });
                void qc.invalidateQueries({ queryKey: ['messages', activity.conversation_id] });
                void qc.invalidateQueries({ queryKey: ['chat-activity', userId, tenantId] });
            });
        }
    }, [data, dataUpdatedAt, userId, tenantId, teamUserId, qc]);

    return null;
}
