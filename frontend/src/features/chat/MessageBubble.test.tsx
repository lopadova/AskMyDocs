import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { Message } from './chat.api';
import { MessageBubble } from './MessageBubble';

function renderWithClient(ui: ReactElement) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('MessageBubble', () => {
    it('hides the model JSON and renders a readable selection receipt', () => {
        const message: Message = {
            id: 120,
            role: 'user',
            content: 'I selected this row:\n\n```json\n{"public_id":"ORDER-77","status":"in_transit"}\n```',
            metadata: {
                locale: 'it-IT',
                agent_selection: {
                    row_key: 'ORDER-77',
                    label: 'In transito',
                    record: {
                        public_id: 'ORDER-77',
                        status: 'in_transit',
                        label: 'In transito',
                        location: 'München',
                        occurred_at: '2026-08-21T09:00:00+00:00',
                    },
                    display: {
                        title: 'In transito',
                        fields: [
                            { key: 'status', label: 'Stato', value: 'in_transit' },
                            { key: 'label', label: 'Etichetta', value: 'In transito' },
                            { key: 'location', label: 'Località', value: 'München' },
                            { key: 'occurred_at', label: 'Data evento', value: '2026-08-21T09:00:00+00:00' },
                            { key: 'public_id', label: 'ID pubblico', value: 'ORDER-77' },
                        ],
                    },
                },
            },
            rating: null,
            created_at: '2026-08-21T09:00:00+00:00',
        };

        render(
            <MessageBubble
                conversationId={8}
                message={message}
                onEditSubmit={vi.fn()}
            />,
        );

        expect(screen.getByTestId('agent-selection-receipt')).toBeInTheDocument();
        expect(screen.getByText('Selezione effettuata')).toBeInTheDocument();
        expect(screen.getByText('In transito')).toBeInTheDocument();
        expect(screen.getByText('München')).toBeInTheDocument();
        expect(screen.getByText('ORDER-77')).toBeInTheDocument();
        expect(screen.queryByText(/public_id/)).not.toBeInTheDocument();
        expect(screen.queryByText(/```json/)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit your message' })).not.toBeInTheDocument();
    });

    it('masks legacy selection JSON using the redacted record as a fallback', () => {
        const message: Message = {
            id: 121,
            role: 'user',
            content: '```json\n{"name":"Alice","api_key":"[REDACTED]"}\n```',
            metadata: {
                agent_selection: {
                    row_key: 'alice',
                    label: 'Alice',
                    record: { name: 'Alice', email: 'alice@example.test', api_key: '[REDACTED]' },
                },
            },
            rating: null,
            created_at: '2026-08-21T09:00:00+00:00',
        };

        renderWithClient(<MessageBubble conversationId={8} message={message} />);

        expect(screen.getByText('Selection saved')).toBeInTheDocument();
        expect(screen.getByText('alice@example.test')).toBeInTheDocument();
        expect(screen.queryByText('[REDACTED]')).not.toBeInTheDocument();
        expect(screen.queryByText(/api_key/)).not.toBeInTheDocument();
    });

    it('uses a semantic, neutral agent avatar for assistant replies', () => {
        const message: Message = {
            id: 122,
            role: 'assistant',
            content: 'Done.',
            metadata: null,
            rating: null,
            created_at: '2026-08-21T09:00:00+00:00',
        };

        renderWithClient(<MessageBubble conversationId={8} message={message} />);

        expect(screen.getByTestId('chat-agent-avatar')).toHaveClass('chat-agent-avatar');
        expect(screen.getByTestId('chat-agent-avatar').querySelector('svg')).toBeInTheDocument();
    });
});
