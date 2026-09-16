import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import {
    chatApi,
    type ChatCollectionOption,
    type Conversation,
    type FilterState,
    type LiveSourceCatalog,
    type LiveSourceKind,
    type LiveSourceSelection,
    type Message as AppMessage,
    type MessageCitation,
} from './chat.api';
import { useChatStore } from './chat.store';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore } from '../../lib/team-store';
import { useAgentChat, type UseAgentChatResult } from './use-agent-chat';
import {
    useRealtimeAgent,
    type RealtimeAgentFeatureStatus,
    type UseRealtimeAgentResult,
} from './use-realtime-agent';
import { chatPreferencesApi, CHAT_PREFERENCES_QUERY_KEY } from './chat-preferences.api';
import type { AgentArtifactSelection } from './AgentTableArtifact';

const COLLECTION_SCOPE_PREF_PREFIX = 'askmydocs.chat.collection_scope.';

/**
 * Normalize an `unknown` error value (TanStack Query's `error` field
 * is `unknown` by default) into the `Error | null` shape the
 * downstream `MessageThread`/`Composer` props expect. Previously we
 * cast via `as Error`, which silently hides non-Error values that
 * would crash on `.message` access in render. This wraps anything
 * non-Error into `new Error(String(e))` so the rendered error
 * message stays informative regardless of the underlying throw.
 */
export function toError(e: unknown): Error | null {
    if (e == null) {
        return null;
    }
    if (e instanceof Error) {
        return e;
    }
    return new Error(typeof e === 'string' ? e : String(e));
}

/**
 * Navigation targets, INJECTED rather than hard-coded.
 *
 * The chat orchestration needs to move the user between "new chat" and
 * "this conversation", but the ROUTE those map to differs per surface:
 * `ChatView` lives at `/app/$teamHash/chat[/$conversationId]` while
 * `SessionsView` lives at `/app/$teamHash/sessions[/$conversationId]`.
 * TanStack Router's `to` is a typed literal, so the navigate() calls
 * must stay in the route-owning component — the hook only asks for the
 * transition. This is the ONLY reason the hook is not a drop-in.
 */
export interface ChatSessionNavigator {
    toNewChat: () => void;
    toConversation: (id: number) => void;
}

export interface UseChatSessionOptions {
    nav: ChatSessionNavigator;
}

export interface UseChatSessionResult {
    // Scope + identity.
    activeId: number | null;
    activeConversation: Conversation | null;
    /** False when `activeId` is set but the row is in neither cache. */
    activeConversationKnown: boolean;
    /** Exposed for assertions; surfaces read `activeConversation` instead. */
    conversationsQuery: UseQueryResult<Conversation[]>;
    projectKey: string | null;
    projectLabel: string;
    projectScopeValue: string | null;
    teamProjectKeys: string[];
    headerMeta: string;
    canViewKb: boolean;

    // Turn engine — shared, never re-implemented per surface.
    chat: UseAgentChatResult;
    realtime: UseRealtimeAgentResult;
    realtimeAvailability: RealtimeAgentFeatureStatus | undefined;
    isStreaming: boolean;
    threadMessages: AppMessage[];
    initialQuery: UseQueryResult<AppMessage[]>;
    turnSettleId: number;

    // Composer inputs.
    filters: FilterState;
    setFilters: React.Dispatch<React.SetStateAction<FilterState>>;
    /** Exposed for assertions; the turn engine consumes it internally. */
    effectiveFilters: FilterState;
    collections: ChatCollectionOption[];
    liveSources: LiveSourceCatalog | undefined;
    liveSourceSelection: LiveSourceSelection | undefined;
    onLiveSourcesChange: (kind: LiveSourceKind, enabledKeys: string[]) => void;
    showCounterfactual: boolean;

    // Actions.
    onSelectConversation: (id: number | null) => void;
    onScopeChange: (next: string) => void;
    requireConversation: () => Promise<number | null>;
    handleSend: (content: string) => Promise<void>;
    handleMcpAppMessage: (content: string, appId: string) => Promise<void>;
    handleAgentArtifactSelection: (selection: AgentArtifactSelection) => Promise<void>;
    handleRegenerate: () => void;
    handleBranchAt: (messageId: number) => Promise<void>;
    handleEditUserMessage: (
        messageIndex: number,
        messageId: number | null,
        newContent: string,
    ) => Promise<void>;

    // Citation modal.
    sourceCitation: MessageCitation | null;
    openSource: (citation: MessageCitation) => void;
    closeSource: () => void;

    // Normalised errors.
    threadError: Error | null;
    composerError: Error | null;
}

/**
 * The whole chat turn orchestration, headless.
 *
 * Extracted verbatim from `ChatView` so a SECOND presentation surface
 * (the ChatGPT-style Sessions workspace) can render a different shell
 * over the SAME engine: one deferred-send queue, one auto-title policy,
 * one project-scope derivation, one filter constraint, one realtime
 * session. Adding a surface must never fork this logic.
 *
 * Query-key contract — the archived list is a NESTED key
 * (`['conversations', 'archived']`), not a sibling like
 * `['conversations-archived']`, precisely so that TanStack's prefix
 * matching makes `invalidateQueries({ queryKey: ['conversations'] })`
 * below invalidate BOTH lists. Flattening it silently leaves the
 * archived drawer stale after every turn.
 */
export function useChatSession({ nav }: UseChatSessionOptions): UseChatSessionResult {
    const params = useParams({ strict: false }) as { conversationId?: string };
    const qc = useQueryClient();
    const setActive = useChatStore((s) => s.setActiveConversation);
    const storeActiveId = useChatStore((s) => s.activeConversationId);

    // The URL is the source of truth (R11 §5), so the active id is DERIVED
    // from the route rather than read back out of the store. The store used
    // to be authoritative, with an effect pushing the URL into it; that
    // made the id lag the route by one render, and because `useChatStore`
    // is a module singleton the lag is VISIBLE across surfaces: opening
    // /sessions right after /chat rendered the previous thread for a frame
    // and fired a wasted ['messages', staleId] fetch before correcting.
    //
    // Deriving also retires a whole bug class. The old comparison
    // `safeId !== activeId` had to sanitize NaN by hand (Copilot #6 on
    // PR #20), because `NaN !== activeId` is true on EVERY render and
    // re-fired setActive in a loop. A `useMemo` keyed on the raw param
    // cannot loop no matter what the segment contains.
    const activeId = useMemo<number | null>(() => {
        const parsed = params.conversationId !== undefined ? Number(params.conversationId) : NaN;
        return Number.isFinite(parsed) ? parsed : null;
    }, [params.conversationId]);

    // The store stays as a MIRROR for the components that read it without
    // access to the route (ConversationList's active-row highlight). It is
    // now a downstream copy, never an input to this hook.
    useEffect(() => {
        if (storeActiveId !== activeId) {
            setActive(activeId);
        }
    }, [activeId, storeActiveId, setActive]);

    // Active project = first project the user can access in the ACTIVE
    // TEAM (team-store, synced from /api/auth/me `teams`). Replaces the
    // old `PROJECTS[0]` seed literal (R18), which pinned every chat to
    // 'hr-portal' regardless of the user's real memberships or team.
    // Null (no membership in this team) degrades to a project-less
    // conversation, same as the BE contract has always allowed.
    const teams = useTeamStore((s) => s.teams);
    const currentTeam = useTeamStore((s) => s.currentTeam);
    const activeTeam = teams.find((t) => t.tenant_id === currentTeam);
    // Reachable projects in the ACTIVE TEAM (R18 — the real membership
    // domain from /api/auth/me, never a literal list). The DISPLAY list is
    // sorted alphabetically to read consistently with the admin Knowledge
    // picker; the NEW-chat default stays the team's first membership in
    // /me order (sorting must not silently change which project a brand-new
    // chat targets).
    const teamProjectKeys = useMemo(
        () =>
            (activeTeam?.projects ?? [])
                .map((p) => p.project_key)
                .sort((a, b) => a.localeCompare(b)),
        [activeTeam],
    );
    const defaultProjectKey = (activeTeam?.projects ?? [])[0]?.project_key ?? null;

    // Chosen scope for NEW conversations: null → team default (first
    // project), '' → All projects (search across every reachable project),
    // or a specific project_key. A conversation binds to ONE project at
    // creation (`conversations.project_key`) and the BE scopes every turn
    // to it, so the selector drives conversation creation, not a per-turn
    // filter.
    const [scope, setScope] = useState<string | null>(null);

    // Switching team can leave a stale scope pointing at a project the new
    // team can't reach — reset so the new team's default takes over.
    useEffect(() => {
        setScope(null);
    }, [currentTeam]);

    // R18 debt (pre-existing, inherited by every surface that renders a
    // model chip): the header model is a frozen literal, not the provider
    // the turn actually ran on (that only comes back in
    // `message.metadata.model`). Kept HERE, in the single shared hook, so
    // there is exactly one place to fix when a real model picker lands.
    const [headerMeta] = useState<string>('claude-sonnet-4.5');

    // Clicking a citation opens the cited document in an in-chat modal — for
    // EVERY reader, not only admins. The modal fetches the source through a
    // tenant + AccessScope-scoped endpoint, so a reader can only ever open a
    // document they may see. The admin-only "Open in Knowledge Base"
    // deep-link is a PER-SURFACE prop on the modal (ChatView points at the
    // admin KB page, SessionsView at the reader Browse KB page), so it
    // deliberately does not live in this hook.
    const roles = useAuthStore((s) => s.roles);
    const realtimeAvailability = useAuthStore((s) => s.features.realtime_agent_live);
    const canViewKb = roles.includes('admin') || roles.includes('super-admin');
    const [sourceCitation, setSourceCitation] = useState<MessageCitation | null>(null);

    // Conversations list (shared cache with the sidebar) — drives the header
    // title + the auto-generated/renamed name. TanStack dedupes the identical
    // queryKey so this does not double-fetch.
    const conversationsQuery = useQuery<Conversation[]>({
        queryKey: ['conversations'],
        queryFn: () => chatApi.listConversations(),
    });
    /**
     * The open session, resolved across BOTH cached slices.
     *
     * `['conversations']` holds the ACTIVE slice only (the server excludes
     * archived rows by default), so opening a thread from the Archived
     * drawer would leave this null — and then the header shows
     * "Session #12" instead of the title, the project scope reads as "all
     * projects" for a project-bound thread, and `maybeGenerateTitle` sees
     * no title and overwrites the user's with a fresh LLM one.
     *
     * `known` is the distinction that matters: null means "not in either
     * cache", which is NOT the same as "has no title" or "has no project".
     * Anything destructive must branch on `known`, never on nullish data.
     */
    const fromActiveSlice =
        activeId !== null ? conversationsQuery.data?.find((c) => c.id === activeId) : undefined;

    /*
     * Look in the archived slice ONLY when the active one does not hold the
     * open session — which is the archived-thread case, plus a cold deep
     * link where the drawer was never opened.
     *
     * This is a real `useQuery`, not a `getQueryData()` peek, for two
     * reasons. It SUBSCRIBES the hook to the key, so a resolution or a
     * refetch re-renders instead of leaving the session unresolved until
     * some unrelated state change; and it actually FETCHES, so a pasted
     * `/sessions/{archivedId}` URL resolves even though nothing has
     * populated that cache. It shares the sidebar drawer's key, so opening
     * the drawer costs no second request.
     *
     * Gated on `isSuccess`, not merely on a miss: during the active list's
     * own load `fromActiveSlice` is undefined for every session, so
     * without it EVERY thread open would fire a wasted archived fetch in
     * the first render window.
     */
    const archivedQuery = useQuery<Conversation[]>({
        queryKey: ['conversations', 'archived'],
        queryFn: () => chatApi.listConversations('archived'),
        enabled: activeId !== null && conversationsQuery.isSuccess && fromActiveSlice === undefined,
        staleTime: 30_000,
    });

    const activeConversation =
        activeId !== null
            ? fromActiveSlice ?? archivedQuery.data?.find((c) => c.id === activeId) ?? null
            : null;

    /**
     * False while the open session is in neither slice.
     *
     * Distinct from "has no title" and from "has no project": a session
     * still being looked up, or one that genuinely no longer exists, must
     * not be rendered as though its fields were empty.
     */
    const activeConversationKnown = activeId === null || activeConversation !== null;

    // Effective project scope. For an EXISTING conversation the bound
    // `conversations.project_key` is authoritative (the BE scopes every
    // turn to it; a per-turn project filter can only narrow within it).
    // For a brand-new chat we honour the user's selection, falling back to
    // the team default. `projectKey === null` means "All projects".
    const conversationProjectKey = activeConversation?.project_key ?? null;
    const projectKey =
        activeId !== null
            ? conversationProjectKey
            : scope === null
              ? defaultProjectKey
              : scope === ''
                ? null
                : scope;

    // "All projects" = a project-less scope that must be constrained to the
    // user's reachable projects, otherwise a null-project conversation hits
    // the WHOLE tenant — a cross-membership leak. The constraint is applied
    // via `effectiveFilters` below (project_keys = my projects).
    const isAllProjects = activeId !== null ? conversationProjectKey === null : scope === '';
    // An unresolved session has no known scope — saying "all projects"
    // there would assert something we have not established.
    const projectLabel = !activeConversationKnown
        ? 'resolving…'
        : projectKey ?? (isAllProjects ? 'all projects' : 'default');

    // The value rendered in the selector: '' for All, the project_key
    // otherwise. A new chat with no explicit choice shows the default.
    // When the team has no reachable projects, keep it as `null` (unknown)
    // rather than silently selecting the "All projects" sentinel.
    const projectScopeValue =
        activeId !== null
            ? conversationProjectKey ?? ''
            : scope ?? defaultProjectKey;

    // One auto-title attempt per conversation id (the BE generateTitle is a
    // real LLM call; never fire it twice for the same thread).
    const titleRequestedRef = useRef<Set<number>>(new Set());

    const openSource = (citation: MessageCitation) => {
        if (citation.document_id == null) {
            return;
        }
        setSourceCitation(citation);
    };

    const closeSource = () => {
        setSourceCitation(null);
    };

    // Close the source modal whenever the active conversation changes — a
    // citation belongs to one thread, so it must never stay mounted over a
    // different conversation (covers sidebar select, branch, requireConversation
    // and URL-driven activeId changes in one place).
    useEffect(() => {
        setSourceCitation(null);
    }, [activeId]);

    // After a turn settles, if the conversation is still untitled, ask the BE
    // to generate a title from the transcript, then refetch the list so the
    // header + sidebar show the persisted name.
    const maybeGenerateTitle = async (id: number) => {
        if (titleRequestedRef.current.has(id)) {
            return;
        }
        // Fail CLOSED on an unknown row. This used to read the title out of
        // the cache and treat `undefined` as "untitled" — so a session that
        // simply was not in the active slice (an archived one, or a cold
        // deep link) got its user-chosen title overwritten by a real LLM
        // call. Absence of evidence is not evidence of absence.
        const row =
            qc.getQueryData<Conversation[]>(['conversations'])?.find((c) => c.id === id)
            ?? qc.getQueryData<Conversation[]>(['conversations', 'archived'])?.find((c) => c.id === id);
        if (row === undefined) {
            return;
        }
        const current = row.title;
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

    // v4.0/W3.2: filters lifted from Composer to the shared hook so the
    // streaming hook can read them when building each turn's
    // request body. Composer is a controlled component for
    // filters via `filters` + `onFiltersChange` props.
    const [filters, setFilters] = useState<FilterState>({});
    const [disabledLiveSourcesByScope, setDisabledLiveSourcesByScope] = useState<Record<string, string[]>>({});

    const effectiveFilters = useMemo<FilterState>(() => {
        // Any project-less conversation must be explicitly constrained to the
        // user's reachable projects, otherwise retrieval becomes tenant-wide.
        if (projectKey !== null) {
            return filters;
        }
        if ((filters.project_keys?.length ?? 0) > 0) {
            return filters;
        }
        const keys = teamProjectKeys.length > 0 ? teamProjectKeys : ['__no_project_access__'];
        return { ...filters, project_keys: keys };
    }, [projectKey, filters, teamProjectKeys]);

    const liveSourcesQuery = useQuery({
        queryKey: ['chat-live-sources', currentTeam, projectKey ?? 'all-projects'],
        queryFn: () => chatApi.listLiveSources(projectKey),
        staleTime: 30_000,
    });
    // Preferences are intentionally local to the current team/project scope:
    // they survive starting a new conversation in the same scope, but never
    // leak into another team or project. Newly discovered sources default ON.
    const liveSourceScopeKey = `${currentTeam ?? 'no-team'}:${projectKey ?? 'all-projects'}`;
    const liveSourceSelection = useMemo<LiveSourceSelection | undefined>(() => {
        const catalog = liveSourcesQuery.data;
        if (!catalog) return undefined;
        const disabled = new Set(disabledLiveSourcesByScope[liveSourceScopeKey] ?? []);

        return {
            api: catalog.api.filter((source) => !disabled.has(source.key)).map((source) => source.key),
            mcp: catalog.mcp.filter((source) => !disabled.has(source.key)).map((source) => source.key),
        };
    }, [disabledLiveSourcesByScope, liveSourceScopeKey, liveSourcesQuery.data]);

    const onLiveSourcesChange = (kind: LiveSourceKind, enabledKeys: string[]) => {
        const catalog = liveSourcesQuery.data;
        if (!catalog) return;
        const groupKeys = new Set(catalog[kind].map((source) => source.key));
        const enabled = new Set(enabledKeys);
        setDisabledLiveSourcesByScope((current) => ({
            ...current,
            [liveSourceScopeKey]: [
                ...(current[liveSourceScopeKey] ?? []).filter((key) => !groupKeys.has(key)),
                ...catalog[kind].filter((source) => !enabled.has(source.key)).map((source) => source.key),
            ],
        }));
    };

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
    // seeds the agent hook's `initialMessages`. After mount the SDK
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

    const chat = useAgentChat({
        conversationId: activeId,
        filters: effectiveFilters,
        liveSources: liveSourceSelection,
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

    // Navigation only: the route change re-derives `activeId`, and the
    // mirror effect syncs the store. Writing the store here too would race
    // that effect for a render.
    const onSelectConversation = (id: number | null) => {
        if (id !== null) {
            nav.toConversation(id);
            return;
        }
        nav.toNewChat();
    };

    // Switching the project scope. A conversation is bound to one project
    // at creation, so to chat in a DIFFERENT scope we start a fresh thread:
    // when the user is inside an existing conversation whose project
    // differs from the chosen one, reset to a new chat so the next turn's
    // `requireConversation()` creates a conversation scoped to it. `next`
    // is a project_key or '' for All projects (→ project-less conversation).
    const onScopeChange = (next: string) => {
        setScope(next);
        const nextProjectKey = next === '' ? null : next;
        if (activeId !== null && conversationProjectKey !== nextProjectKey) {
            nav.toNewChat();
        }
    };

    const requireConversation = async (): Promise<number | null> => {
        if (activeId !== null) {
            return activeId;
        }
        try {
            const created = await chatApi.createConversation(projectKey);
            // R25: dedupe by the SERVER id before prepending. A refetch that
            // resolves between the POST and this write (the onFinish
            // invalidation, or the sidebar's own refresh) already holds this
            // row, and an undeduped prepend then renders two components with
            // the same id — a real regression, not flake.
            qc.setQueryData<Conversation[]>(['conversations'], (old) => [
                created,
                ...(old ?? []).filter((c) => c.id !== created.id),
            ]);
            nav.toConversation(created.id);
            return created.id;
        } catch {
            return null;
        }
    };

    const realtime = useRealtimeAgent({
        conversationId: activeId,
        filters: effectiveFilters,
        liveSources: liveSourceSelection,
        availability: realtimeAvailability,
        onRequireConversation: requireConversation,
        onAdoptRun: chat.adoptExternalRun,
    });

    // Deferred-send queue. When the user sends the first message on
    // a brand-new chat (activeId === null), Composer's send() awaits
    // `onRequireConversation()` which calls `setActive(newId)`. That
    // state change is async — the resulting re-render hasn't
    // propagated by the time the await resumes, so a direct
    // `chat.sendMessage()` call would post against the OLD (id=null)
    // instance whose transport URL is
    // `/conversations/0/messages/stream` and whose internal state
    // map is keyed under `id='pending'`. The user's message would
    // land in an orphaned SDK state while MessageThread reads from
    // the freshly-rebuilt `id='conv-N'` state map (empty) → silent
    // disappearance.
    //
    // Fix: queue the send via state. The useEffect below fires AFTER
    // React has propagated the conversationId update (and the
    // agent hook has rebuilt with the new id + transport),
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
    //      render of the agent hook, but its `sendMessage` is stable
    //      relative to the SDK state map (re-derived from id). We
    //      capture the latest `sendMessage` via a ref outside the
    //      dispatch path so the queued message uses the CURRENT
    //      transport/conversationId without forcing the effect to
    //      re-fire on every render. (Re-firing would dispatch the
    //      same message multiple times during the streaming window.)
    //
    // R17: the ref assignment below is deliberately RENDER-PHASE, not
    // inside an effect. Moving it into a `useEffect` would leave
    // `sendMessageRef.current` pointing at the id=null instance for
    // the first dispatch after a brand-new conversation is created,
    // silently dropping the user's first message.
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

    const handleMcpAppMessage = async (content: string, appId: string): Promise<void> => {
        if (activeId === null || chat.status === 'submitted' || chat.status === 'streaming') {
            throw new Error('The conversation is not ready for an MCP App message.');
        }
        await chat.sendMessage({ text: content }, { mcpAppId: appId });
    };

    const handleAgentArtifactSelection = async (selection: AgentArtifactSelection): Promise<void> => {
        if (activeId === null || chat.status === 'submitted' || chat.status === 'streaming') {
            throw new Error('The conversation is not ready for a selection.');
        }
        await chat.sendMessage(
            { text: selection.displayText },
            { selection: { message_id: selection.messageId, row_key: selection.rowKey } },
        );
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
            // Same R25 dedupe as requireConversation above.
            qc.setQueryData<Conversation[]>(['conversations'], (old) => [
                result.conversation,
                ...(old ?? []).filter((c) => c.id !== result.conversation.id),
            ]);
            nav.toConversation(result.conversation.id);
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

    // Agent runs materialize the final assistant row before their terminal
    // event. The hook reloads that canonical history, so every rendered item
    // already carries numeric ids, citations, API provenance and feedback.
    const threadMessages = chat.messages;

    return {
        activeId,
        activeConversation,
        activeConversationKnown,
        conversationsQuery,
        projectKey,
        projectLabel,
        projectScopeValue,
        teamProjectKeys,
        headerMeta,
        canViewKb,

        chat,
        realtime,
        realtimeAvailability,
        isStreaming,
        threadMessages,
        initialQuery,
        turnSettleId,

        filters,
        setFilters,
        effectiveFilters,
        collections: collectionsQuery.data ?? [],
        liveSources: liveSourcesQuery.data,
        liveSourceSelection,
        onLiveSourcesChange,
        showCounterfactual,

        onSelectConversation,
        onScopeChange,
        requireConversation,
        handleSend,
        handleMcpAppMessage,
        handleAgentArtifactSelection,
        handleRegenerate,
        handleBranchAt,
        handleEditUserMessage,

        sourceCitation,
        openSource,
        closeSource,

        threadError: chat.error ?? toError(initialQuery.error),
        composerError: chat.error ?? realtime.error,
    };
}
