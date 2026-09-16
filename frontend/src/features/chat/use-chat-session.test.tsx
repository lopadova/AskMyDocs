import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PropsWithChildren } from 'react';
import { chatApi, type Conversation, type Message } from './chat.api';
import { useChatSession, toError, type ChatSessionNavigator } from './use-chat-session';
import { useChatStore } from './chat.store';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore } from '../../lib/team-store';

/**
 * Safety net for the ChatView → useChatSession extraction (v8.x). The
 * 794-line ChatView had ZERO unit coverage; these tests pin the async
 * orchestration that only the E2E suite exercised, so a regression in
 * the deferred-send queue, the project-scope constraint or the
 * auto-title policy fails here in seconds instead of in a 20-minute
 * Playwright shard.
 *
 * R16: every test drives the transition its name promises.
 */

// The hook reads the URL through useParams; the nav targets are injected
// so the typed `to` literals stay in the route-owning components.
let routeParams: { conversationId?: string } = {};

vi.mock('@tanstack/react-router', () => ({
    useParams: () => routeParams,
}));

vi.mock('./use-realtime-agent', () => ({
    useRealtimeAgent: () => ({
        status: 'idle',
        error: null,
        active: false,
        start: vi.fn(),
        stop: vi.fn(),
    }),
}));

const sendMessage = vi.fn(async () => undefined);
let agentOptions: { onFinish?: () => void; filters?: unknown } = {};

vi.mock('./use-agent-chat', () => ({
    useAgentChat: (options: { onFinish?: () => void; filters?: unknown }) => {
        agentOptions = options;
        return {
            messages: [] as Message[],
            status: 'ready',
            error: null,
            events: [],
            activeRun: null,
            confirmation: null,
            sendMessage,
            stop: vi.fn(),
            regenerate: vi.fn(),
            continueRun: vi.fn(),
            adoptExternalRun: vi.fn(),
            setMessages: vi.fn(),
        };
    },
}));

function conversation(overrides: Partial<Conversation> = {}): Conversation {
    return {
        id: 7,
        title: 'Ordini Q3',
        project_key: 'engineering',
        created_at: '2026-09-01T10:00:00Z',
        updated_at: '2026-09-01T10:00:00Z',
        ...overrides,
    } as Conversation;
}

/**
 * The injected navigator must behave like the real router: TanStack
 * rewrites the URL, and the hook's URL→store effect then KEEPS the id.
 * A no-op spy would let that effect immediately reset activeId to null
 * (the URL still says "new chat"), which stalls the deferred-send queue
 * forever — the exact failure mode this harness must not fake away.
 */
const nav: ChatSessionNavigator = {
    toNewChat: vi.fn(() => {
        routeParams = {};
    }),
    toConversation: vi.fn((id: number) => {
        routeParams = { conversationId: String(id) };
    }),
};

function harness() {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const wrapper = ({ children }: PropsWithChildren) => (
        <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    return {
        client,
        ...renderHook(() => useChatSession({ nav }), { wrapper }),
    };
}

beforeEach(() => {
    routeParams = {};
    agentOptions = {};
    sendMessage.mockClear();
    vi.mocked(nav.toNewChat).mockClear();
    vi.mocked(nav.toConversation).mockClear();
    useChatStore.getState().setActiveConversation(null);
    useTeamStore.setState({
        teams: [
            {
                tenant_id: 'acme',
                hash: 'h-acme',
                name: 'Acme',
                projects: [
                    { project_key: 'engineering', role: 'member', scope: null },
                    { project_key: 'accounting', role: 'member', scope: null },
                ],
            },
        ],
        currentTeam: 'acme',
        userId: 1,
    });
    useAuthStore.setState({ roles: ['viewer'], features: {} });
    vi.spyOn(chatApi, 'listConversations').mockResolvedValue([conversation()]);
    vi.spyOn(chatApi, 'listMessages').mockResolvedValue([]);
    vi.spyOn(chatApi, 'listLiveSources').mockResolvedValue({ api: [], mcp: [] });
    vi.spyOn(chatApi, 'listCollections').mockResolvedValue([]);
});

afterEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('toError', () => {
    it('passes an Error through and wraps anything else', () => {
        const err = new Error('boom');
        expect(toError(err)).toBe(err);
        expect(toError(null)).toBeNull();
        expect(toError('plain')).toBeInstanceOf(Error);
        expect(toError({ weird: true })?.message).toBe('[object Object]');
    });
});

describe('useChatSession — URL sync', () => {
    it('adopts a numeric conversation id from the route', async () => {
        routeParams = { conversationId: '7' };
        const { result } = harness();
        await waitFor(() => expect(result.current.activeId).toBe(7));
    });

    it('mirrors the derived id into the store for the sidebar highlight', async () => {
        // ConversationList reads the store, not the route, so the mirror is
        // a real contract — but it flows one way only (route -> store).
        routeParams = { conversationId: '7' };
        const { result } = harness();
        await waitFor(() => expect(useChatStore.getState().activeConversationId).toBe(7));
        expect(result.current.activeId).toBe(7);
    });

    it('ignores a stale store value instead of rendering the wrong thread', async () => {
        // The store is a module singleton shared by /chat and /sessions. If
        // it were an INPUT, opening the second surface would render the
        // other surface's last thread for a frame and fetch its messages.
        useChatStore.getState().setActiveConversation(99);
        routeParams = {};
        const { result } = harness();

        await waitFor(() => expect(result.current.activeId).toBeNull());
        expect(chatApi.listMessages).not.toHaveBeenCalled();
    });

    it('treats a non-numeric id as "new chat" without thrashing the store', async () => {
        // Regression guard for the NaN loop: `NaN !== activeId` is always
        // true, so an unsanitized parse re-fired setActive on every render.
        const setActive = vi.spyOn(useChatStore.getState(), 'setActiveConversation');
        routeParams = { conversationId: 'anonymous' };
        const { result, rerender } = harness();
        await waitFor(() => expect(result.current.activeId).toBeNull());
        rerender();
        rerender();
        expect(setActive).not.toHaveBeenCalled();
    });
});

describe('useChatSession — project scope', () => {
    it('constrains a project-less turn to the team projects', async () => {
        const { result } = harness();
        await waitFor(() => expect(result.current.conversationsQuery.data).toHaveLength(1));

        act(() => result.current.onScopeChange(''));

        await waitFor(() =>
            expect(result.current.effectiveFilters.project_keys).toEqual([
                'accounting',
                'engineering',
            ]),
        );
        expect(result.current.projectKey).toBeNull();
    });

    it('falls back to a deny-all sentinel when the team has no projects', async () => {
        useTeamStore.setState({
            teams: [{ tenant_id: 'acme', hash: 'h-acme', name: 'Acme', projects: [] }],
            currentTeam: 'acme',
            userId: 1,
        });
        const { result } = harness();

        act(() => result.current.onScopeChange(''));

        await waitFor(() =>
            expect(result.current.effectiveFilters.project_keys).toEqual([
                '__no_project_access__',
            ]),
        );
    });

    it('leaves an explicit project filter untouched', async () => {
        const { result } = harness();
        act(() => result.current.onScopeChange(''));
        act(() => result.current.setFilters({ project_keys: ['accounting'] }));

        await waitFor(() =>
            expect(result.current.effectiveFilters.project_keys).toEqual(['accounting']),
        );
    });

    it('does not constrain a project-bound conversation', async () => {
        routeParams = { conversationId: '7' };
        const { result } = harness();
        await waitFor(() => expect(result.current.projectKey).toBe('engineering'));
        expect(result.current.effectiveFilters.project_keys).toBeUndefined();
    });
});

describe('useChatSession — an archived session opened by URL', () => {
    it('resolves the session from the archived cache, not as an unknown row', async () => {
        // ['conversations'] holds the ACTIVE slice only, so a thread opened
        // from the Archived drawer would otherwise be invisible to the hook.
        const archived = conversation({ id: 7, title: 'Old laptop request', archived_at: 'x' });
        vi.mocked(chatApi.listConversations).mockResolvedValue([]);
        routeParams = { conversationId: '7' };

        const { result, client } = harness();
        client.setQueryData(['conversations', 'archived'], [archived]);

        await waitFor(() => expect(result.current.activeConversation?.id).toBe(7));
        expect(result.current.activeConversationKnown).toBe(true);
        // The bound project is preserved, so the scope does not silently
        // widen to "all projects".
        expect(result.current.projectKey).toBe('engineering');
    });

    it('never auto-titles a session it cannot see in either cache', async () => {
        // Regression: the old guard read the title out of the active slice
        // and treated `undefined` as "untitled", so an archived or
        // cold-deep-linked session had its title overwritten by a real LLM
        // call on the next settled turn.
        const generateTitle = vi
            .spyOn(chatApi, 'generateTitle')
            .mockResolvedValue({ title: 'Regenerated' });
        vi.mocked(chatApi.listConversations).mockResolvedValue([]);
        routeParams = { conversationId: '7' };

        const { result } = harness();
        await waitFor(() => expect(result.current.activeId).toBe(7));
        expect(result.current.activeConversationKnown).toBe(false);

        await act(async () => {
            agentOptions.onFinish?.();
        });

        expect(generateTitle).not.toHaveBeenCalled();
    });

    it('still auto-titles a known session whose title is empty', async () => {
        // The paired assertion: failing closed must not disable the feature.
        const generateTitle = vi
            .spyOn(chatApi, 'generateTitle')
            .mockResolvedValue({ title: 'Generated' });
        vi.mocked(chatApi.listConversations).mockResolvedValue([
            conversation({ id: 7, title: null }),
        ]);
        routeParams = { conversationId: '7' };

        const { result } = harness();
        await waitFor(() => expect(result.current.activeConversationKnown).toBe(true));

        await act(async () => {
            agentOptions.onFinish?.();
        });

        await waitFor(() => expect(generateTitle).toHaveBeenCalledWith(7));
    });
});

describe('useChatSession — deferred send queue', () => {
    it('queues the first message until the new conversation id propagates', async () => {
        vi.spyOn(chatApi, 'createConversation').mockResolvedValue(
            conversation({ id: 42, title: null }),
        );
        const { result } = harness();
        await waitFor(() => expect(result.current.activeId).toBeNull());

        // Composer's real order: create the conversation, then send.
        await act(async () => {
            const id = await result.current.requireConversation();
            expect(id).toBe(42);
        });
        await act(async () => {
            await result.current.handleSend('Dammi gli ordini');
        });

        expect(sendMessage).toHaveBeenCalledWith({ text: 'Dammi gli ordini' });
        expect(nav.toConversation).toHaveBeenCalledWith(42);
    });

    it('rejects handleSend when the queued dispatch fails', async () => {
        // The queued promise must MIRROR the underlying send result —
        // otherwise Composer sees a resolve and drops the user's draft.
        sendMessage.mockRejectedValueOnce(new Error('transport down'));
        vi.spyOn(chatApi, 'createConversation').mockResolvedValue(
            conversation({ id: 43, title: null }),
        );
        const { result } = harness();

        await act(async () => {
            await result.current.requireConversation();
        });
        await expect(
            act(async () => {
                await result.current.handleSend('ciao');
            }),
        ).rejects.toThrow('transport down');
    });

    it('does not duplicate a created session already present in the cache', async () => {
        // R25: the prepend must dedupe by the SERVER id, or a refetch that
        // resolved first leaves two rows with the same id.
        const created = conversation({ id: 42, title: null });
        // The list ALREADY contains the row the POST is about to return —
        // the refetch-resolved-first race.
        vi.mocked(chatApi.listConversations).mockResolvedValue([created]);
        vi.spyOn(chatApi, 'createConversation').mockResolvedValue(created);
        const { result, client } = harness();
        await waitFor(() =>
            expect(client.getQueryData<Conversation[]>(['conversations'])).toHaveLength(1),
        );

        await act(async () => {
            await result.current.requireConversation();
        });

        const cached = client.getQueryData<Conversation[]>(['conversations']) ?? [];
        expect(cached.filter((c) => c.id === 42)).toHaveLength(1);
    });

    it('returns null and stays on a new chat when conversation creation fails', async () => {
        vi.spyOn(chatApi, 'createConversation').mockRejectedValue(new Error('422'));
        const { result } = harness();

        await act(async () => {
            expect(await result.current.requireConversation()).toBeNull();
        });
        expect(result.current.activeId).toBeNull();
        expect(nav.toConversation).not.toHaveBeenCalled();
    });
});

describe('useChatSession — auto title', () => {
    it('asks for a title once per conversation and never for a titled one', async () => {
        const generateTitle = vi
            .spyOn(chatApi, 'generateTitle')
            .mockResolvedValue({ title: 'Ordini' });
        vi.mocked(chatApi.listConversations).mockResolvedValue([
            conversation({ id: 7, title: null }),
        ]);
        routeParams = { conversationId: '7' };
        const { result } = harness();
        await waitFor(() => expect(result.current.activeId).toBe(7));

        await act(async () => {
            agentOptions.onFinish?.();
        });
        await waitFor(() => expect(generateTitle).toHaveBeenCalledTimes(1));

        // A second settled turn must NOT fire a second LLM title call.
        await act(async () => {
            agentOptions.onFinish?.();
        });
        expect(generateTitle).toHaveBeenCalledTimes(1);
    });

    it('skips the title call when the conversation already has one', async () => {
        const generateTitle = vi
            .spyOn(chatApi, 'generateTitle')
            .mockResolvedValue({ title: 'x' });
        routeParams = { conversationId: '7' };
        const { result } = harness();
        await waitFor(() => expect(result.current.conversationsQuery.data).toHaveLength(1));

        await act(async () => {
            agentOptions.onFinish?.();
        });
        expect(generateTitle).not.toHaveBeenCalled();
    });

    it('bumps the settle id so suggested followups refetch', async () => {
        routeParams = { conversationId: '7' };
        const { result } = harness();
        await waitFor(() => expect(result.current.activeId).toBe(7));
        const before = result.current.turnSettleId;

        await act(async () => {
            agentOptions.onFinish?.();
        });
        expect(result.current.turnSettleId).toBe(before + 1);
    });
});

describe('useChatSession — realtime availability (R43: both flag states)', () => {
    it('passes an undefined availability through when the feature is absent', async () => {
        useAuthStore.setState({ roles: ['viewer'], features: {} });
        const { result } = harness();
        await waitFor(() => expect(result.current.realtimeAvailability).toBeUndefined());
    });

    it('passes the available payload through when the feature is on', async () => {
        useAuthStore.setState({
            roles: ['viewer'],
            features: { realtime_agent_live: { available: true, reason: null } },
        });
        const { result } = harness();
        await waitFor(() =>
            expect(result.current.realtimeAvailability).toEqual({
                available: true,
                reason: null,
            }),
        );
    });
});

describe('useChatSession — KB affordance', () => {
    it('grants the KB deep-link to admins only', async () => {
        const { result } = harness();
        await waitFor(() => expect(result.current.canViewKb).toBe(false));

        useAuthStore.setState({ roles: ['admin'], features: {} });
        const admin = harness();
        await waitFor(() => expect(admin.result.current.canViewKb).toBe(true));
    });
});

describe('useChatSession — collection scope persistence', () => {
    it('restores the per-conversation collection scope from localStorage', async () => {
        window.localStorage.setItem('askmydocs.chat.collection_scope.7', '3');
        routeParams = { conversationId: '7' };
        const { result } = harness();

        await waitFor(() => expect(result.current.filters.collection_id).toBe(3));
    });

    it('clears the stored scope when the collection filter is removed', async () => {
        window.localStorage.setItem('askmydocs.chat.collection_scope.7', '3');
        routeParams = { conversationId: '7' };
        const { result } = harness();
        await waitFor(() => expect(result.current.filters.collection_id).toBe(3));

        act(() => result.current.setFilters((prev) => ({ ...prev, collection_id: null })));

        await waitFor(() =>
            expect(window.localStorage.getItem('askmydocs.chat.collection_scope.7')).toBeNull(),
        );
    });
});
