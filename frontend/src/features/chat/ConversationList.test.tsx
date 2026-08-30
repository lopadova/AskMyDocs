import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactElement } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { chatApi, type Conversation } from './chat.api';
import { useChatStore } from './chat.store';
import { ConversationList } from './ConversationList';

function renderWithClient(ui: ReactElement) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

function conversation(id: number, title: string, ageMs: number): Conversation {
    const timestamp = new Date(Date.now() - ageMs).toISOString();

    return {
        id,
        title,
        project_key: 'date',
        created_at: timestamp,
        updated_at: timestamp,
    };
}

describe('ConversationList', () => {
    const rows = [
        conversation(1, 'Orders today', 60 * 60 * 1000),
        conversation(2, 'Weekly shipment review', 3 * 24 * 60 * 60 * 1000),
        conversation(3, 'Archived customer lookup', 12 * 24 * 60 * 60 * 1000),
    ];

    beforeEach(() => {
        useChatStore.setState({ activeConversationId: 2 });
        vi.spyOn(chatApi, 'listConversations').mockResolvedValue(rows);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('groups history into useful time ranges and marks the active conversation', async () => {
        renderWithClient(
            <ConversationList projectKey="date" onSelect={vi.fn()} onNewAnonymous={vi.fn()} />,
        );

        expect(await screen.findByText('Orders today')).toBeInTheDocument();
        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('Previous 7 days')).toBeInTheDocument();
        expect(screen.getByText('Earlier')).toBeInTheDocument();
        expect(screen.getByTestId('chat-conversation-2')).toHaveAttribute('data-active', 'true');
        expect(screen.getByText('3 chats')).toBeInTheDocument();
    });

    it('filters by title and can clear the search without losing history', async () => {
        renderWithClient(
            <ConversationList projectKey="date" onSelect={vi.fn()} onNewAnonymous={vi.fn()} />,
        );
        await screen.findByText('Orders today');

        fireEvent.change(screen.getByTestId('chat-sidebar-search'), {
            target: { value: 'shipment' },
        });

        expect(screen.getByText('Weekly shipment review')).toBeInTheDocument();
        expect(screen.queryByText('Orders today')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Clear conversation search' }));
        expect(screen.getByText('Orders today')).toBeInTheDocument();
    });

    it('keeps new and anonymous chat actions explicit', async () => {
        const onSelect = vi.fn();
        const onNewAnonymous = vi.fn();
        const created = conversation(4, 'New conversation', 0);
        vi.spyOn(chatApi, 'createConversation').mockResolvedValue(created);
        renderWithClient(
            <ConversationList projectKey="date" onSelect={onSelect} onNewAnonymous={onNewAnonymous} />,
        );
        await screen.findByText('Orders today');

        fireEvent.click(screen.getByTestId('chat-new-anonymous-chat'));
        expect(onNewAnonymous).toHaveBeenCalledOnce();

        fireEvent.click(screen.getByTestId('chat-new-conversation'));
        await waitFor(() => expect(onSelect).toHaveBeenCalledWith(4));
    });
});
