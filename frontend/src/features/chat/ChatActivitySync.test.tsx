import { act, render, screen, waitFor, cleanup } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatActivitySync } from './ChatActivitySync';
import { ChatActivityBadge } from './ChatActivityBadge';
import { chatApi, type ConversationActivity } from './chat.api';
import { clearAgentChatSessions, getAgentChatSession } from './agent-chat-session';
import { useAuthStore } from '../../lib/auth-store';
import { useTeamStore } from '../../lib/team-store';

const activity: ConversationActivity = {
    conversation_id: 7, last_read_message_id: 0,
    latest_reply: { message_id: 11, created_at: new Date(Date.now() - 180_000).toISOString() },
    run: { run_id: 'run-1', status: 'completed', locale: 'it', events_url: '/events', cancel_url: '/cancel', continue_url: '/continue', confirmation: null },
};
const clients: QueryClient[] = [];
function mount() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    clients.push(client);
    return render(<QueryClientProvider client={client}>
        <ChatActivitySync /><ChatActivityBadge conversationId={7} active={false} />
    </QueryClientProvider>);
}

beforeEach(() => {
    clearAgentChatSessions();
    useAuthStore.setState({ user: { id: 1, name: 'Owner', email: 'owner@example.test', locale: 'en' } });
    useTeamStore.setState({ userId: 1, currentTeam: 'acme' });
    vi.spyOn(chatApi, 'listMessages').mockResolvedValue([]);
});
afterEach(() => {
    cleanup();
    for (const client of clients) client.clear();
    clients.length = 0;
    clearAgentChatSessions();
    useAuthStore.setState({ user: null });
    useTeamStore.setState({ userId: null, currentTeam: null });
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('ChatActivitySync', () => {
    it('recovers badges on app startup outside a chat, across restarts, and keeps an entered chat read', async () => {
        let readThrough = 0;
        vi.spyOn(chatApi, 'listActivity').mockImplementation(async () => [{ ...activity, last_read_message_id: readThrough }]);
        const read = vi.spyOn(chatApi, 'markConversationRead').mockImplementation(async (_id, messageId) => {
            readThrough = messageId;
            return { last_read_message_id: readThrough };
        });
        const first = mount();
        expect(await screen.findByTestId('chat-activity-7')).toHaveTextContent('3 min');
        expect(read).not.toHaveBeenCalled();
        first.unmount();
        clearAgentChatSessions();
        const second = mount();
        expect(await screen.findByTestId('chat-activity-7')).toHaveTextContent('3 min');
        act(() => { getAgentChatSession(7, 1, 'acme').attach()(); });
        await waitFor(() => expect(readThrough).toBe(11));
        expect(screen.queryByTestId('chat-activity-7')).not.toBeInTheDocument();
        second.unmount();
        clearAgentChatSessions();
        mount();
        await waitFor(() => expect(chatApi.listActivity).toHaveBeenCalledTimes(3));
        expect(screen.queryByTestId('chat-activity-7')).not.toBeInTheDocument();
    });

    it('waits for authenticated user/team bootstrap before discovering any runs', async () => {
        useAuthStore.setState({ user: null });
        const list = vi.spyOn(chatApi, 'listActivity').mockResolvedValue([activity]);
        mount();
        expect(list).not.toHaveBeenCalled();
        act(() => useAuthStore.setState({ user: { id: 1, name: 'Owner', email: 'owner@example.test' } }));
        await waitFor(() => expect(list).toHaveBeenCalledWith('acme'));
    });

    it('does not hydrate a late snapshot from the previous tenant into the new foreground scope', async () => {
        let resolveFirst!: (rows: ConversationActivity[]) => void;
        const list = vi.spyOn(chatApi, 'listActivity').mockImplementation(async (tenant) => tenant === 'acme'
            ? new Promise((resolve) => { resolveFirst = resolve; }) : []);
        mount();
        await waitFor(() => expect(list).toHaveBeenCalledWith('acme'));
        act(() => useTeamStore.setState({ currentTeam: 'globex' }));
        await waitFor(() => expect(list).toHaveBeenCalledWith('globex'));
        await act(async () => resolveFirst([activity]));
        expect(getAgentChatSession(7, 1, 'globex').store.getState().unreadAt).toBeNull();
        expect(screen.queryByTestId('chat-activity-7')).not.toBeInTheDocument();
    });
});
