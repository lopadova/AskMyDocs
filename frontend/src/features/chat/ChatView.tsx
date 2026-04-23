import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from '@tanstack/react-router';
import { ConversationList } from './ConversationList';
import { MessageThread } from './MessageThread';
import { Composer } from './Composer';
import { chatApi, type Conversation } from './chat.api';
import { useChatStore } from './chat.store';
import { PROJECTS } from '../../lib/seed';
import { Icon } from '../../components/Icons';
import { useChatMutation } from './use-chat-mutation';

/**
 * Chat feature root. Three columns:
 *   - ConversationList (sidebar)
 *   - Thread + Composer (centre)
 *   - (future PR) Related graph panel
 *
 * The route `/app/chat/:conversationId?` drives the active conversation;
 * navigating programmatically updates the URL via TanStack Router.
 */
export function ChatView(): ReactNode {
    const navigate = useNavigate();
    const params = useParams({ strict: false }) as { conversationId?: string };
    const qc = useQueryClient();
    const activeId = useChatStore((s) => s.activeConversationId);
    const setActive = useChatStore((s) => s.setActiveConversation);
    const mutationStatus = useChatMutation();

    // Sync URL param → store. The URL is the source of truth (R11 §5).
    useEffect(() => {
        const fromUrl = params.conversationId ? Number(params.conversationId) : null;
        if (fromUrl !== activeId) {
            setActive(Number.isFinite(fromUrl as number) ? fromUrl : null);
        }
    }, [params.conversationId, activeId, setActive]);

    const project = PROJECTS[0];
    const projectLabel = project?.label ?? 'default';
    const projectKey = project?.key ?? null;

    const [headerMeta] = useState<string>('claude-sonnet-4.5');

    const onSelect = (id: number | null) => {
        setActive(id);
        if (id !== null) {
            navigate({ to: `/app/chat/${id}` });
            return;
        }
        navigate({ to: '/app/chat' });
    };

    const requireConversation = async (): Promise<number | null> => {
        if (activeId !== null) {
            return activeId;
        }
        try {
            const created = await chatApi.createConversation(projectKey);
            qc.setQueryData<Conversation[]>(['conversations'], (old) =>
                old ? [created, ...old] : [created],
            );
            setActive(created.id);
            navigate({ to: `/app/chat/${created.id}` });
            return created.id;
        } catch {
            return null;
        }
    };

    const thread = useMemo(
        () => (
            <MessageThread
                conversationId={activeId}
                projectKey={projectKey}
                isSending={mutationStatus.isPending}
            />
        ),
        [activeId, projectKey, mutationStatus.isPending],
    );

    return (
        <div data-testid="chat-view" style={{ display: 'flex', height: '100%', flex: 1, minWidth: 0 }}>
            <ConversationList projectKey={projectKey} onSelect={onSelect} />
            <div
                style={{
                    flex: 1,
                    display: 'flex',
                    flexDirection: 'column',
                    minWidth: 0,
                    position: 'relative',
                }}
            >
                <header
                    data-testid="chat-header"
                    style={{
                        padding: '12px 24px',
                        borderBottom: '1px solid var(--hairline)',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                    }}
                >
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 13.5, fontWeight: 500, color: 'var(--fg-0)' }}>
                            {activeId ? `Conversation #${activeId}` : 'New chat'}
                        </div>
                        <div
                            style={{
                                fontSize: 11,
                                color: 'var(--fg-3)',
                                fontFamily: 'var(--font-mono)',
                                marginTop: 2,
                                display: 'flex',
                                gap: 10,
                            }}
                        >
                            <span>{projectLabel}</span>
                            <span>·</span>
                            <span>{headerMeta}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        className="btn icon sm ghost"
                        data-testid="chat-header-more"
                        aria-label="Conversation actions"
                    >
                        <Icon.MoreH size={14} />
                    </button>
                </header>

                {thread}

                <Composer
                    conversationId={activeId}
                    projectLabel={projectLabel}
                    modelLabel={headerMeta}
                    onRequireConversation={requireConversation}
                />
            </div>
        </div>
    );
}
