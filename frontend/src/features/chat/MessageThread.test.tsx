import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import type { Message } from './chat.api';
import { MessageThread } from './MessageThread';

const completedEvent: AgentRunEvent = {
    run_id: 'run-1',
    sequence: 2,
    type: 'run.completed',
    phase: 'run',
    locale: 'it-IT',
    message_key: 'run.completed',
    message_params: {},
    message: 'La risposta è pronta.',
    progress: null,
    can_cancel: false,
    data: {},
    created_at: '2026-08-30T10:00:01+00:00',
};

function message(
    id: number,
    role: Message['role'],
    content: string,
    metadata: Message['metadata'] = null,
): Message {
    return {
        id,
        role,
        content,
        metadata,
        rating: null,
        created_at: '2026-08-30T10:00:00+00:00',
    };
}

function renderThread(node: ReactElement) {
    const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });

    return render(
        <QueryClientProvider client={queryClient}>
            {node}
        </QueryClientProvider>,
    );
}

describe('MessageThread agent activity', () => {
    beforeEach(() => {
        Object.defineProperty(HTMLElement.prototype, 'scrollTo', {
            configurable: true,
            value: vi.fn(),
        });
    });

    it('renders persisted activity between its user request and assistant answer', () => {
        const user = message(10, 'user', 'Dove si trova la spedizione?', { agent_run_id: 'run-1' });
        const assistant = message(11, 'assistant', 'La spedizione è stata consegnata.', {
            agent_run_id: 'run-1',
            agent_activity: [completedEvent],
        });

        const { container } = renderThread(
            <MessageThread
                conversationId={4}
                messages={[user, assistant]}
                sdkStatus="ready"
            />,
        );

        const content = container.querySelector('.chat-thread-content');
        expect(content).not.toBeNull();
        expect(Array.from(content!.children).map((node) => (
            node.getAttribute('data-role') ?? node.getAttribute('data-testid')
        ))).toEqual(['user', 'agent-activity-bar', 'assistant']);
        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Risultato pronto');
        expect(screen.getByText('La spedizione è stata consegnata.')).toBeInTheDocument();
    });

    it('shows the live activity immediately after the active user request', () => {
        const startedEvent: AgentRunEvent = {
            ...completedEvent,
            sequence: 1,
            type: 'tool.started',
            message: 'Sto chiamando shipments-get.',
            data: {
                tool: 'mcp_hubhive_shipments_get',
                tool_kind: 'mcp',
                tool_display_name: 'shipments-get',
                mcp_server_name: 'HubHive',
                mcp_tool_name: 'shipments-get',
            },
        };
        const user = message(20, 'user', 'Controlla la spedizione.', { agent_run_id: 'run-live' });

        const { container } = renderThread(
            <MessageThread
                conversationId={4}
                messages={[user]}
                sdkStatus="streaming"
                agentEvents={[{ ...startedEvent, run_id: 'run-live' }]}
                activeAgentRunId="run-live"
                onCancelAgent={vi.fn()}
            />,
        );

        const content = container.querySelector('.chat-thread-content');
        expect(Array.from(content!.children).map((node) => (
            node.getAttribute('data-role') ?? node.getAttribute('data-testid')
        ))).toEqual(['user', 'agent-activity-bar']);
        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Chiamata MCP');
        expect(screen.getByTestId('agent-activity-message')).toHaveTextContent('HubHive · shipments-get');
        expect(screen.getByTestId('agent-activity-bar')).toHaveAttribute('aria-busy', 'true');
    });

    it('presents failures through the shared destructive alert structure', () => {
        const user = message(30, 'user', 'Mostrami gli ultimi ordini.');

        renderThread(
            <MessageThread
                conversationId={4}
                messages={[user]}
                sdkStatus="error"
                error={new Error('The live source did not respond.')}
            />,
        );

        const alert = screen.getByTestId('chat-thread-error');
        expect(alert).toHaveAttribute('data-variant', 'destructive');
        expect(alert).toHaveTextContent('We couldn’t complete that request');
        expect(alert).toHaveTextContent('The live source did not respond.');
        expect(alert.querySelector('[data-slot="alert-icon"]')).not.toBeNull();
    });
});
