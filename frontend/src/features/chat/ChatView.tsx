import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from '@tanstack/react-router';
import { ConversationList } from './ConversationList';
import { MessageThread } from './MessageThread';
import { Composer } from './Composer';
import { chatApi, type Conversation, type FilterState, type Message as AppMessage } from './chat.api';
import { useChatStore } from './chat.store';
import { PROJECTS } from '../../lib/seed';
import { Icon } from '../../components/Icons';
import { useChatStream } from './use-chat-stream';
import type { RenderableMessage } from './message-shape-adapters';

/**
 * Chat feature root. Three columns:
 *   - ConversationList (sidebar)
 *   - Thread + Composer (centre)
 *   - (future PR) Related graph panel
 *
 * The route `/app/chat/:conversationId?` drives the active conversation;
 * navigating programmatically updates the URL via TanStack Router.
 *
 * v4.0/W3.2: ChatView owns the SDK streaming hook (`useChatStream`)
 * and threads its outputs (messages, status, error, sendMessage, stop)
 * down to MessageThread + Composer. The legacy `useChatMutation` flow
 * is gone — the SDK manages optimistic placeholder, streaming text
 * accretion, and stream lifecycle. Initial conversation history still
 * comes from a TanStack Query GET (the SDK doesn't fetch history
 * itself); the result seeds `useChatStream`'s `initialMessages` so
 * thread navigation back-and-forth doesn't lose prior turns.
 *
 * Filters are lifted from Composer-local state to ChatView so the
 * streaming hook can read them when building each turn's request body
 * (see `useChatStream`'s `prepareSendMessagesRequest`).
 */
export function ChatView(): ReactNode {
    const navigate = useNavigate();
    const params = useParams({ strict: false }) as { conversationId?: string };
    const qc = useQueryClient();
    const activeId = useChatStore((s) => s.activeConversationId);
    const setActive = useChatStore((s) => s.setActiveConversation);

    // Sync URL param → store. The URL is the source of truth (R11 §5).
    //
    // Copilot #6 fix: compute a sanitized `safeId` first. Without this,
    // a non-numeric URL segment produced NaN, and `NaN !== activeId`
    // evaluates true on every render, which re-fired `setActive(null)`
    // every render and thrashed Zustand subscribers into a loop.
    useEffect(() => {
        const parsed = params.conversationId !== undefined ? Number(params.conversationId) : NaN;
        const safeId: number | null = Number.isFinite(parsed) ? parsed : null;
        if (safeId !== activeId) {
            setActive(safeId);
        }
    }, [params.conversationId, activeId, setActive]);

    const project = PROJECTS[0];
    const projectLabel = project?.label ?? 'default';
    const projectKey = project?.key ?? null;

    const [headerMeta] = useState<string>('claude-sonnet-4.5');

    // v4.0/W3.2: filters lifted from Composer to ChatView so the
    // streaming hook can read them when building each turn's
    // request body. Composer is now a controlled component for
    // filters via `filters` + `onFiltersChange` props.
    const [filters, setFilters] = useState<FilterState>({});

    // Initial message history. The SDK's `useChat()` doesn't fetch
    // history from the BE — it only manages live state. This query
    // pulls the persisted thread once per conversation; the result
    // seeds `useChatStream`'s `initialMessages`. After mount the SDK
    // takes over; we set staleTime to Infinity so a hot remount
    // (e.g. tab focus) doesn't refetch and clobber the live SDK
    // messages with a stale snapshot.
    const initialQuery = useQuery<AppMessage[]>({
        queryKey: ['messages', activeId ?? 'none'],
        queryFn: () => {
            if (activeId === null) {
                return Promise.resolve<AppMessage[]>([]);
            }
            return chatApi.listMessages(activeId);
        },
        enabled: activeId !== null,
        staleTime: Infinity,
    });

    const initialMessages = useMemo<AppMessage[] | undefined>(
        () => initialQuery.data,
        [initialQuery.data],
    );

    const chat = useChatStream({
        conversationId: activeId,
        filters,
        initialMessages,
        onFinish: () => {
            // Refetch the conversations list (sidebar's recent activity
            // ordering) and the messages list for THIS conversation
            // (so the SDK's transient UIMessage gets swapped for the
            // BE-persisted AppMessage that carries metadata,
            // citations, and feedback rating).
            // The closure captures `activeId` from the render that
            // installed this `onFinish`. If the user navigates to a
            // different conversation mid-stream, the captured value
            // stays bound to the conversation the stream was FOR —
            // which is exactly what we want to invalidate, since
            // that's the cache whose persisted message just landed
            // on the BE. Invalidating the user's CURRENT location
            // would refetch the wrong conversation's messages.
            void qc.invalidateQueries({ queryKey: ['conversations'] });
            if (activeId !== null) {
                void qc.invalidateQueries({ queryKey: ['messages', activeId] });
            }
        },
    });

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

    // Deferred-send queue. When the user sends the first message on
    // a brand-new chat (activeId === null), Composer's send() awaits
    // `onRequireConversation()` which calls `setActive(newId)`. That
    // state change is async — the resulting re-render hasn't
    // propagated by the time the await resumes, so a direct
    // `chat.sendMessage()` call would post against the OLD (id=null)
    // useChatStream instance whose transport URL is
    // `/conversations/0/messages/stream` and whose internal state
    // map is keyed under `id='pending'`. The user's message would
    // land in an orphaned SDK state while MessageThread reads from
    // the freshly-rebuilt `id='conv-N'` state map (empty) → silent
    // disappearance.
    //
    // Fix: queue the send via state. The useEffect below fires AFTER
    // React has propagated the conversationId update (and the
    // useChatStream hook has rebuilt with the new id + transport),
    // so `chat.sendMessage` runs against the CURRENT chat instance
    // and the message lands in the rendered state map.
    //
    // For the conversationId-already-set path (subsequent messages
    // in an existing thread), we send synchronously — no queue
    // round-trip needed.
    const [pendingSend, setPendingSend] = useState<string | null>(null);

    // The effect's dispatch is guarded by:
    //   1. Synchronous `setPendingSend(null)` BEFORE any await — so a
    //      re-render triggered by `chat.sendMessage`'s internal state
    //      mutation reads `pendingSend === null` and skips.
    //   2. Effect deps EXCLUDE `chat` — `chat` is a fresh object every
    //      render of `useChatStream`, but its `sendMessage` is stable
    //      relative to the SDK state map (re-derived from id). We
    //      capture the latest `sendMessage` via a ref outside the
    //      dispatch path so the queued message uses the CURRENT
    //      transport/conversationId without forcing the effect to
    //      re-fire on every render. (Re-firing would dispatch the
    //      same message multiple times during the streaming window.)
    const sendMessageRef = useRef(chat.sendMessage);
    sendMessageRef.current = chat.sendMessage;

    useEffect(() => {
        if (pendingSend !== null && activeId !== null) {
            // Clear FIRST so a re-entry during the awaited dispatch
            // doesn't see the old pending value.
            const text = pendingSend;
            setPendingSend(null);
            void sendMessageRef.current({ text });
        }
    }, [pendingSend, activeId]);

    const handleSend = async (content: string): Promise<void> => {
        if (activeId !== null) {
            await chat.sendMessage({ text: content });
            return;
        }
        // First message on a brand-new chat — queue and let the
        // useEffect dispatch once activeId propagates.
        setPendingSend(content);
    };

    const isStreaming = chat.status === 'submitted' || chat.status === 'streaming';

    // The SDK returns `messages: UIMessage[]`. MessageThread accepts
    // RenderableMessage[] (= AppMessage | UIMessage); the cast widens
    // the array element type so both shapes flow through.
    const threadMessages: RenderableMessage[] = chat.messages;

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

                <MessageThread
                    conversationId={activeId}
                    projectKey={projectKey}
                    messages={threadMessages}
                    sdkStatus={chat.status}
                    isLoadingHistory={initialQuery.isLoading}
                    error={chat.error ?? (initialQuery.error as Error | null | undefined) ?? null}
                />

                <Composer
                    conversationId={activeId}
                    projectLabel={projectLabel}
                    modelLabel={headerMeta}
                    onRequireConversation={requireConversation}
                    filters={filters}
                    onFiltersChange={setFilters}
                    onSend={handleSend}
                    onStop={chat.stop}
                    isStreaming={isStreaming}
                    error={chat.error ?? null}
                />
            </div>
        </div>
    );
}
