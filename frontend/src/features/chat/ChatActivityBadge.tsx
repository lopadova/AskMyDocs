import { useEffect, useMemo, useState } from 'react';
import { useStore } from 'zustand';
import { LoaderCircle, MessageCircle, CircleAlert, CirclePause } from 'lucide-react';
import { useTeamStore } from '../../lib/team-store';
import { useUiTranslation } from '../../lib/ui-locale';
import { getAgentChatSession } from './agent-chat-session';

/** Shared by the Chat and Sessions sidebars, including their mobile drawers. */
export function ChatActivityBadge({ conversationId, active }: { conversationId: number; active: boolean }) {
    const { t } = useUiTranslation();
    const userId = useTeamStore((state) => state.userId);
    const tenantId = useTeamStore((state) => state.currentTeam);
    const session = useMemo(() => getAgentChatSession(conversationId, userId, tenantId), [conversationId, userId, tenantId]);
    const status = useStore(session.store, (state) => state.status);
    const confirmation = useStore(session.store, (state) => state.confirmation);
    const unreadAt = useStore(session.store, (state) => state.unreadAt);
    const [now, setNow] = useState(Date.now);

    useEffect(() => {
        if (unreadAt === null || active) return;
        setNow(Date.now());
        const timer = window.setInterval(() => setNow(Date.now()), 60_000);
        return () => window.clearInterval(timer);
    }, [unreadAt, active]);

    let kind: string;
    let label: string;
    let description: string;
    let glyph;
    if (confirmation) {
        kind = 'confirmation';
        label = description = t('Confirmation needed');
        glyph = <CirclePause size={11} aria-hidden="true" />;
    } else if (status === 'submitted' || status === 'streaming') {
        kind = 'running';
        label = t('Searching');
        description = active ? t('Search in progress') : t('Search continues in the background');
        glyph = <LoaderCircle size={11} aria-hidden="true" />;
    } else if (status === 'error') {
        kind = 'error';
        label = description = t('Search failed');
        glyph = <CircleAlert size={11} aria-hidden="true" />;
    } else if (unreadAt !== null && !active) {
        kind = 'unread';
        const minutes = Math.max(0, Math.floor((now - unreadAt) / 60_000));
        label = `${minutes} min`;
        description = t('Unread reply received {minutes} minutes ago', { minutes });
        glyph = <MessageCircle size={11} aria-hidden="true" />;
    } else {
        return null;
    }

    return (
        <span
            className="chat-activity-badge"
            data-testid={`chat-activity-${conversationId}`}
            data-state={kind}
            aria-label={description}
            title={description}
        >
            {glyph}<span>{label}</span>
        </span>
    );
}
