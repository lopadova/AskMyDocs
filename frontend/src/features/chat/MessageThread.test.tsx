import { render, screen } from '@testing-library/react';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import type { Message } from './chat.api';
import { MessageThread } from './MessageThread';

vi.mock('./MessageBubble', () => ({
    MessageBubble: ({ message }: { message: Message }) => (
        <div data-testid={`chat-message-${message.id}`} data-role={message.role}>
            {message.content}
        </div>
    ),
}));

const originalScrollTo = Element.prototype.scrollTo;

beforeAll(() => {
    Element.prototype.scrollTo = vi.fn();
});

afterAll(() => {
    Element.prototype.scrollTo = originalScrollTo;
});

function message(id: number, role: Message['role'], content: string): Message {
    return {
        id,
        role,
        content,
        metadata: null,
        rating: null,
        created_at: `2026-08-26T12:00:0${id}Z`,
    };
}

describe('MessageThread agent activity placement', () => {
    it('places the current run activity after its user prompt and before the answer', () => {
        const messages = [
            message(1, 'user', 'Prima domanda'),
            message(2, 'assistant', 'Prima risposta'),
            message(3, 'user', 'Seconda domanda'),
            message(4, 'assistant', 'Seconda risposta'),
        ];

        render(
            <MessageThread
                conversationId={7}
                messages={messages}
                sdkStatus="ready"
                activityAfterMessageId={3}
                activity={<aside data-testid="agent-activity-bar">Attività completata</aside>}
            />,
        );

        const prompt = screen.getByTestId('chat-message-3');
        const activity = screen.getByTestId('chat-activity-row');
        const answer = screen.getByTestId('chat-message-4');

        expect(prompt.compareDocumentPosition(activity) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(activity.compareDocumentPosition(answer) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(screen.getByTestId('agent-activity-bar')).toHaveTextContent('Attività completata');
    });

    it('does not render activity when the associated prompt is not in this thread', () => {
        render(
            <MessageThread
                conversationId={7}
                messages={[message(1, 'user', 'Domanda')]}
                sdkStatus="ready"
                activityAfterMessageId={99}
                activity={<aside>Attività</aside>}
            />,
        );

        expect(screen.queryByTestId('chat-activity-row')).not.toBeInTheDocument();
    });
});
