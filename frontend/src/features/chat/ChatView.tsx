import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from '@tanstack/react-router';
import { ConversationList } from './ConversationList';
import { ConversationTitle } from './ConversationTitle';
import { MessageThread } from './MessageThread';
import { Composer } from './Composer';
import { chatApi, type Conversation, type FilterState, type Message as AppMessage, type MessageCitation } from './chat.api';
import { useChatStore } from './chat.store';
import { useAuthStore } from '../../lib/auth-store';
import { selectCurrentHash, useTeamStore } from '../../lib/team-store';
import { Icon } from '../../components/Icons';
import { useChatStream } from './use-chat-stream';
import type { RenderableMessage } from './message-shape-adapters';
import { SuggestedFollowups } from './SuggestedFollowups';
import { CitationDocumentModal } from './CitationDocumentModal';
import { chatPreferencesApi, CHAT_PREFERENCES_QUERY_KEY } from './chat-preferences.api';

const COLLECTION_SCOPE_PREF_PREFIX = 'askmydocs.chat.collection_scope.';

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
/**
 * Normalize an `unknown` error value (TanStack Query's `error` field
 * is `unknown` by default) into the `Error | null` shape the
 * downstream `MessageThread`/`Composer` props expect. Previously we
 * cast via `as Error`, which silently hides non-Error values that
 * would crash on `.message` access in render. This wraps anything
 * non-Error into `new Error(String(e))` so the rendered error
 * message stays informative regardless of the underlying throw.
 */
function toError(e: unknown): Error | null {
    if (e == null) {
        return null;
    }
    if (e instanceof Error) {
        return e;
    }
    return new Error(typeof e === 'string' ? e : String(e));
}

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

    // Active project = first project the user can access in the ACTIVE
    // TEAM (team-store, synced from /api/auth/me `teams`). Replaces the
    // old `PROJECTS[0]` seed literal (R18), which pinned every chat to
    // 'hr-portal' regardless of the user's real memberships or team.
    // Null (no membership in this team) degrades to a project-less
    // conversation, same as the BE contract has always allowed.
    const teams = useTeamStore((s) => s.teams);
    const currentTeam = useTeamStore((s) => s.currentTeam);
    const teamHash = useTeamStore(selectCurrentHash) ?? '';
    const activeTeam = teams.find((t) => t.tenant_id === currentTeam);
    const projectKey = activeTeam?.projects[0]?.project_key ?? null;
    const projectLabel = projectKey ?? 'default';

    const [headerMeta] = useState<string>('claude-sonnet-4.5');

    // Clicking a citation opens the cited document in an in-chat modal — for
    // EVERY reader, not only admins. The modal fetches the source through a
    // tenant + AccessScope-scoped endpoint, so a reader can only ever open a
    // document they may see. Admins additionally get an "Open in Knowledge
    // Base" deep-link inside the modal (the old navigate target, which lives
    // behind the admin RBAC gate).
    const roles = useAuthStore((s) => s.roles);
    const canViewKb = roles.includes('admin') || roles.includes('super-admin');
    const [sourceCitation, setSourceCitation] = useState<MessageCitation | null>(null);

    // Conversations list (shared cache with the sidebar) — drives the header
    // title + the auto-generated/renamed name. TanStack dedupes the identical
    // queryKey so this does not double-fetch.
    const conversationsQuery = useQuery<Conversation[]>({
        queryKey: ['conversations'],
        queryFn: chatApi.listConversations,
    });
    const activeConversation =
        activeId !== null ? conversationsQuery.data?.find((c) => c.id === activeId) ?? null : null;

    // One auto-title attempt per conversation id (the BE generateTitle is a
    // real LLM call; never fire it twice for the same thread).
    const titleRequestedRef = useRef<Set<number>>(new Set());

    const handleOpenSource = (citation: MessageCitation) => {
        if (citation.document_id == null) {
            return;
        }
        setSourceCitation(citation);
    };

    // Admin-only secondary action inside the modal: jump to the full KB
    // document page (deep-link behind the admin/super-admin RBAC gate).
    const handleOpenInKb = (citation: MessageCitation) => {
        if (citation.document_id == null) {
            return;
        }
        setSourceCitation(null);
        navigate({
            to: '/app/$teamHash/admin/kb',
            params: { teamHash },
            search: { doc: citation.document_id, tab: 'preview' },
        });
    };

    // After a turn settles, if the conversation is still untitled, ask the BE
    // to generate a title from the transcript, then refetch the list so the
    // header + sidebar show the persisted name.
    const maybeGenerateTitle = async (id: number) => {
        if (titleRequestedRef.current.has(id)) {
            return;
        }
        const list = qc.getQueryData<Conversation[]>(['conversations']);
        const current = list?.find((c) => c.id === id)?.title;
        if (current != null && current.trim() !== '') {
            return;
        }
        titleRequestedRef.current.add(id);
        try {
            await chatApi.generateTitle(id);
            await qc.invalidateQueries({ queryKey: ['conversations'] });
        } catch {
            // Allow a retry on the next settled turn.
            titleRequestedRef.current.delete(id);
        }
    };

    // v4.0/W3.2: filters lifted from Composer to ChatView so the
    // streaming hook can read them when building each turn's
    // request body. Composer is now a controlled component for
    // filters via `filters` + `onFiltersChange` props.
    const [filters, setFilters] = useState<FilterState>({});
    const collectionsQuery = useQuery({
        queryKey: ['chat-collections'],
        queryFn: () => chatApi.listCollections(),
        staleTime: 60_000,
    });

    // v8.0.1 / deep-review F5 — counterfactual toggle is now a
    // per-user server-persisted preference (was browser-local
    // localStorage). Read the merged (defaults + stored) view from
    // the BE so multi-device / fresh-session usage keeps the user's
    // choice.
    const preferencesQuery = useQuery({
        queryKey: CHAT_PREFERENCES_QUERY_KEY,
        queryFn: () => chatPreferencesApi.load(),
        // Preferences rarely change; staleTime keeps the bell from
        // hammering the endpoint while the user is in chat.
        staleTime: 5 * 60_000,
        refetchOnWindowFocus: false,
    });
    // UX trade-off (iter-10 vs iter-11 of Copilot review on PR
    // #223 deep-review hotfix): two failure modes are in tension —
    //
    //   (A) `?? true` defaults to ON during loading/error → a
    //       user who saved `false` sees a brief panel flash
    //       (~one GET round-trip) before the BE confirms.
    //   (B) `=== true` defaults to HIDDEN during loading/error →
    //       a user with the default-ON preference (everyone
    //       except those who flipped it off) waits one GET for
    //       the panel to appear, AND a degraded-network user
    //       sees no panel + no error/retry indicator (the
    //       retry surface lives in NotificationPreferencesGrid,
    //       not in chat).
    //
    // (B) leaks LESS user preference (no flash of a hidden
    // panel) but is more conservative on the default UX. (A)
    // matches the BE-side DEFAULTS map literally but flashes.
    //
    // We split the difference: during the very first load
    // (`isLoading=true`, no data yet, no error) we OPTIMISTICALLY
    // assume the BE default (TRUE) so degraded-network users
    // and first-paint match the historical UX. Once data
    // arrives — or the query errors — we switch to strict mode:
    // show iff `=== true`. Background refetches don't reset
    // `data`, so the cached value stays stable and no second
    // flicker fires. The remaining saved-false-flash window is
    // the SINGLE first GET round trip per fresh session.
    const showCounterfactual =
        preferencesQuery.data !== undefined
            ? preferencesQuery.data.preferences.counterfactual_enabled === true
            : ! preferencesQuery.isError;

    useEffect(() => {
        if (activeId === null) {
            return;
        }
        const raw = window.localStorage.getItem(`${COLLECTION_SCOPE_PREF_PREFIX}${activeId}`);
        const parsed = raw !== null && raw !== '' ? Number(raw) : null;
        setFilters((prev) => ({
            ...prev,
            collection_id: parsed !== null && Number.isFinite(parsed) ? parsed : null,
        }));
    }, [activeId]);

    useEffect(() => {
        if (activeId === null) {
            return;
        }
        const key = `${COLLECTION_SCOPE_PREF_PREFIX}${activeId}`;
        if (filters.collection_id == null) {
            window.localStorage.removeItem(key);
            return;
        }
        window.localStorage.setItem(key, String(filters.collection_id));
    }, [activeId, filters.collection_id]);

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

    // v4.5/W7 Tier 2 #10 — bumped each time an assistant turn settles.
    // Drives the SuggestedFollowups refetch — never on every render.
    const [turnSettleId, setTurnSettleId] = useState(0);

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
                // Auto-name the thread from the transcript on first settle.
                void maybeGenerateTitle(activeId);
            }
            // v4.5/W7 — trigger suggested-followups refetch.
            setTurnSettleId((n) => n + 1);
        },
    });

    const onSelect = (id: number | null) => {
        setActive(id);
        if (id !== null) {
            navigate({ to: `/app/${teamHash}/chat/${id}` });
            return;
        }
        navigate({ to: '/app/$teamHash/chat', params: { teamHash } });
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
            navigate({ to: `/app/${teamHash}/chat/${created.id}` });
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

    // Settle ref for the queued first-message Promise. handleSend
    // resolves only when the underlying chat.sendMessage settles, so
    // Composer's try/catch can restore the draft on rejection.
    // Without this chain, the queued send's rejection is `void`d
    // inside the effect and the Composer sees an immediate resolve
    // (incorrect — the user thinks the send succeeded).
    const pendingSettleRef = useRef<((result: { error?: Error }) => void) | null>(null);

    useEffect(() => {
        if (pendingSend !== null && activeId !== null) {
            // Clear FIRST so a re-entry during the awaited dispatch
            // doesn't see the old pending value.
            const text = pendingSend;
            const settle = pendingSettleRef.current;
            setPendingSend(null);
            sendMessageRef.current({ text })
                .then(() => settle?.({}))
                .catch((err: unknown) => {
                    settle?.({
                        error: err instanceof Error ? err : new Error(String(err)),
                    });
                })
                .finally(() => {
                    pendingSettleRef.current = null;
                });
        }
    }, [pendingSend, activeId]);

    const handleSend = async (content: string): Promise<void> => {
        if (activeId !== null) {
            await chat.sendMessage({ text: content });
            return;
        }
        // First message on a brand-new chat — queue and let the
        // useEffect dispatch once activeId propagates. Chain the
        // queued dispatch's settle through `pendingSettleRef` so
        // this Promise mirrors the underlying chat.sendMessage
        // result; rejection bubbles up to Composer's try/catch.
        const result = await new Promise<{ error?: Error }>((resolve) => {
            pendingSettleRef.current = resolve;
            setPendingSend(content);
        });
        if (result.error) {
            throw result.error;
        }
    };

    // v4.5/W7 Tier 1 #2 — regenerate the LAST assistant turn.
    const handleRegenerate = () => {
        chat.regenerate();
    };

    // v4.5/W7 Tier 1 #3 — fork the conversation at a chosen
    // assistant message. The BE persists every message up to AND
    // INCLUDING the named one into a fresh conversation; we then
    // navigate to it so the user can branch the discussion without
    // touching the source thread.
    const handleBranchAt = async (messageId: number) => {
        if (activeId === null) {
            return;
        }
        try {
            const result = await chatApi.branchFromMessage(activeId, messageId);
            // Optimistically prepend the new conversation row to the
            // sidebar list so the user sees it immediately; the next
            // invalidate refreshes ordering.
            qc.setQueryData<Conversation[]>(['conversations'], (old) =>
                old ? [result.conversation, ...old] : [result.conversation],
            );
            setActive(result.conversation.id);
            navigate({ to: `/app/${teamHash}/chat/${result.conversation.id}` });
        } catch (err) {
            // Branch is a non-critical action — log and let the user
            // retry. We don't surface a separate error banner; the
            // existing chat-composer-error path handles transport
            // errors for now.
            console.error('Branch failed:', err);
        }
    };

    // v4.5/W7 Tier 1 #4 — inline edit a user message and re-submit.
    // The edit flow requires a backend truncation FIRST (R20 — the BE
    // loads history from DB, not from client-sent messages) so the
    // next turn's context window starts from the edit point:
    //   1. DELETE /conversations/{id}/messages-from/{messageId} — removes
    //      the edited message + everything after it from the DB.
    //   2. chat.setMessages() truncates the SDK's in-memory cache to match.
    //   3. sendMessage({ text: newContent }) sends the replacement text.
    // On onFinish the TanStack invalidation refetches the trimmed history
    // + new user + assistant pair, so the thread looks exactly as if
    // the user had typed the new content from the start.
    const handleEditUserMessage = async (messageIndex: number, messageId: number | null, newContent: string) => {
        if (activeId !== null && messageId !== null) {
            // Truncate DB history from the edited message onwards.
            await chatApi.truncateMessagesFrom(activeId, messageId);
        }
        // Truncate the SDK in-memory cache to keep the UI consistent
        // with the DB state during the in-flight request window.
        chat.setMessages((prev) => prev.slice(0, messageIndex));
        await chat.sendMessage({ text: newContent });
    };

    const isStreaming = chat.status === 'submitted' || chat.status === 'streaming';

    // The SDK returns `messages: UIMessage[]` (string ids, no metadata).
    // The TanStack history query returns `AppMessage[]` (numeric ids,
    // full MessageMetadata).
    //
    // v4.5/W7 Tier 1 #3 + #5 — the branch button and the token-cost
    // meter are both gated on data the SDK UIMessage doesn't carry
    // (numeric id + token telemetry). Once the stream settles and
    // `onFinish` invalidates the messages query, the refetched
    // AppMessages cover the turns the SDK knows about — overlay them
    // onto the SDK message tail by index-from-end so each assistant
    // bubble surfaces the canonical persisted shape (numeric id +
    // metadata) without losing any user turns the SDK still tracks
    // but the GET stub / partial-history endpoint omitted. During
    // the streaming window the SDK's live UIMessages remain
    // exclusively in charge — they carry the in-flight tokens the
    // user is watching accrue.
    const persistedMessages = initialQuery.data;
    const threadMessages: RenderableMessage[] = (() => {
        if (isStreaming || !persistedMessages || persistedMessages.length === 0) {
            return chat.messages;
        }
        // Merge: keep SDK-only head turns, overlay persisted tail.
        // The persisted list aligns to the END of the SDK list (the
        // BE returns the most recent N messages; the SDK may hold
        // optimistic user turns the BE hasn't yet persisted).
        const sdkLen = chat.messages.length;
        const persistedLen = persistedMessages.length;
        if (persistedLen >= sdkLen) {
            return persistedMessages;
        }
        const headFromSdk = chat.messages.slice(0, sdkLen - persistedLen);
        return [...headFromSdk, ...persistedMessages];
    })();

    return (
        <div data-testid="chat-view" style={{ display: 'flex', height: '100%', flex: 1, minWidth: 0 }}>
            <ConversationList
                projectKey={projectKey}
                onSelect={onSelect}
                onNewAnonymous={() =>
                    navigate({ to: '/app/$teamHash/chat/anonymous', params: { teamHash } })
                }
            />
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
                        {activeId !== null ? (
                            <ConversationTitle
                                conversationId={activeId}
                                title={
                                    activeConversation?.title?.trim()
                                        ? activeConversation.title
                                        : `Conversation #${activeId}`
                                }
                            />
                        ) : (
                            <div style={{ fontSize: 13.5, fontWeight: 500, color: 'var(--fg-0)' }}>
                                New chat
                            </div>
                        )}
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
                    error={chat.error ?? toError(initialQuery.error)}
                    onRegenerate={handleRegenerate}
                    onBranchAt={handleBranchAt}
                    onEditUserMessage={handleEditUserMessage}
                    showCounterfactual={showCounterfactual}
                    onOpenSource={handleOpenSource}
                />

                <SuggestedFollowups
                    conversationId={activeId}
                    turnId={turnSettleId}
                    isStreaming={isStreaming}
                    onPick={(prompt) => void handleSend(prompt)}
                />

                <Composer
                    conversationId={activeId}
                    projectLabel={projectLabel}
                    projectKey={projectKey}
                    modelLabel={headerMeta}
                    onRequireConversation={requireConversation}
                    availableCollections={collectionsQuery.data ?? []}
                    filters={filters}
                    onFiltersChange={setFilters}
                    onSend={handleSend}
                    onStop={chat.stop}
                    isStreaming={isStreaming}
                    error={chat.error ?? null}
                />
            </div>

            {sourceCitation && (
                <CitationDocumentModal
                    citation={sourceCitation}
                    onClose={() => setSourceCitation(null)}
                    onOpenInKb={canViewKb ? handleOpenInKb : undefined}
                />
            )}
        </div>
    );
}
