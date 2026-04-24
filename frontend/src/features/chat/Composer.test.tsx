import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { Composer } from './Composer';
import { useChatStore } from './chat.store';
import { api } from '../../lib/api';

function renderWithClient(ui: ReactElement) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('Composer', () => {
    beforeEach(() => {
        useChatStore.setState({ activeConversationId: 1, draft: '', isListening: false, showGraph: false, sidebarOpen: true });
    });

    it('rejects an empty draft with a client-side validation error', () => {
        renderWithClient(<Composer conversationId={1} />);
        const send = screen.getByTestId('chat-composer-send');
        fireEvent.click(send);
        expect(screen.getByTestId('message-error')).toHaveTextContent('required');
    });

    it('posts the typed message and clears the draft on send', async () => {
        const postSpy = vi.spyOn(api, 'post').mockResolvedValue({
            data: {
                id: 42,
                role: 'assistant',
                content: 'Hi!',
                metadata: null,
                rating: null,
                created_at: new Date().toISOString(),
            },
        } as never);

        renderWithClient(<Composer conversationId={1} />);
        const input = screen.getByTestId('chat-composer-input') as HTMLTextAreaElement;
        fireEvent.change(input, { target: { value: 'Hello' } });
        fireEvent.submit(input.closest('form') as HTMLFormElement);

        await waitFor(() => {
            expect(postSpy).toHaveBeenCalledWith('/conversations/1/messages', { content: 'Hello' });
        });
    });
});
