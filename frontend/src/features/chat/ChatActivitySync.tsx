import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore } from '../../lib/team-store';
import { subscribeAgentActivity } from '../../lib/agent-realtime';
import { getAgentChatSession, setAgentChatVisibility } from './agent-chat-session';
import { chatApi } from './chat.api';

/** Recover server-owned activity even when the app opens outside the chat. */
export function ChatActivitySync() {
    const userId = useAuthStore((state) => state.user?.id ?? null);
    const tenantId = useTeamStore((state) => state.currentTeam);
    const teamUserId = useTeamStore((state) => state.userId);
    const qc = useQueryClient();
    const [visible, setVisible] = useState(document.visibilityState !== 'hidden');
    useEffect(() => {
        const changed = () => {
            const next = document.visibilityState !== 'hidden';
            setVisible(next);
            setAgentChatVisibility(next);
        };
        document.addEventListener('visibilitychange', changed);
        return () => document.removeEventListener('visibilitychange', changed);
    }, []);
    useEffect(() => {
        if (!visible || userId === null || tenantId === null || userId !== teamUserId) return;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const leave = subscribeAgentActivity(userId, tenantId, () => {
            // Bursts of progress events require only one activity refresh.
            if (timer !== undefined) return;
            timer = setTimeout(() => {
                timer = undefined;
                void qc.invalidateQueries({ queryKey: ['chat-activity', userId, tenantId] });
            }, 100);
        });
        return () => { clearTimeout(timer); leave(); };
    }, [visible, userId, tenantId, teamUserId, qc]);
    const { data, dataUpdatedAt } = useQuery({
        queryKey: ['chat-activity', userId, tenantId],
        queryFn: () => chatApi.listActivity(tenantId!),
        enabled: visible && userId !== null && userId === teamUserId && tenantId !== null,
        staleTime: 0,
        refetchOnWindowFocus: true,
        refetchOnReconnect: true,
        refetchInterval: 30_000,
        refetchIntervalInBackground: false,
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
