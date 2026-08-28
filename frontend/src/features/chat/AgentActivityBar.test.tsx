import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { AgentActivityBar } from './AgentActivityBar';

const progressEvent: AgentRunEvent = {
    run_id: 'run-1',
    sequence: 2,
    type: 'tool.progress',
    phase: 'tool',
    locale: 'it-IT',
    message_key: 'tool.progress',
    message_params: { completed: 3, estimated: 10 },
    message: 'Completate 3 richieste API su circa 10.',
    progress: {
        logical: { completed: 1, estimated: { min: 1, likely: 2, max: 4 } },
        physical: { completed: 3, estimated: { min: 5, likely: 10, max: 20 } },
        eta_ms: 4200,
    },
    can_cancel: true,
    data: { tool: 'list_orders', tool_kind: 'api', tool_display_name: 'list-orders' },
    created_at: null,
};

describe('AgentActivityBar', () => {
    it('renders localized live progress and cancellation', () => {
        const cancel = vi.fn();
        render(<AgentActivityBar events={[progressEvent]} active awaitingConfirmation={false} onCancel={cancel} onContinue={() => undefined} />);

        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Chiamata API');
        expect(screen.getByTestId('agent-activity-message')).toHaveTextContent('list-orders');
        expect(screen.getByTestId('agent-activity-calls')).toHaveTextContent('3 / ~10 chiamate');
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '30');
        expect(screen.getByRole('progressbar')).toHaveAttribute('data-kind', 'api');
        fireEvent.click(screen.getByTestId('agent-activity-cancel'));
        expect(cancel).toHaveBeenCalledOnce();
    });

    it('offers continuation when the backend requests confirmation', () => {
        const proceed = vi.fn();
        render(<AgentActivityBar events={[{ ...progressEvent, type: 'run.awaiting_confirmation' }]} active={false} awaitingConfirmation onCancel={() => undefined} onContinue={proceed} />);

        fireEvent.click(screen.getByTestId('agent-activity-continue'));
        expect(proceed).toHaveBeenCalledOnce();
        expect(screen.getByTestId('agent-activity-bar')).toHaveAttribute('data-state', 'confirmation');
        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Serve una conferma');
    });

    it('keeps a settled summary compact and exposes the chronological timeline on demand', () => {
        const completedEvent: AgentRunEvent = {
            ...progressEvent,
            sequence: 3,
            type: 'run.completed',
            message: 'La risposta è pronta.',
            progress: {
                ...progressEvent.progress!,
                physical: { completed: 10, estimated: { min: 10, likely: 10, max: 10 } },
            },
        };

        render(<AgentActivityBar events={[progressEvent, completedEvent]} active={false} awaitingConfirmation={false} onCancel={() => undefined} onContinue={() => undefined} />);

        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Risultato pronto');
        expect(screen.getByText('Cronologia attività')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
        expect(screen.queryByTestId('agent-activity-timeline')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Mostra attività' }));
        expect(screen.getByTestId('agent-activity-timeline')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Nascondi attività' })).toHaveAttribute('aria-expanded', 'true');
    });

    it('identifies the active MCP server and tool at a glance', () => {
        const mcpStarted: AgentRunEvent = {
            ...progressEvent,
            type: 'tool.started',
            message: 'Sto chiamando search-customers.',
            message_params: { tool: 'search-customers' },
            data: {
                tool: 'mcp_01m113xm_search_customers_60ededa1',
                tool_kind: 'mcp',
                tool_display_name: 'search-customers',
                mcp_server_name: 'Gescat',
                mcp_tool_name: 'search-customers',
            },
        };

        render(<AgentActivityBar events={[mcpStarted]} active awaitingConfirmation={false} onCancel={() => undefined} onContinue={() => undefined} />);

        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Chiamata MCP');
        expect(screen.getByTestId('agent-activity-message')).toHaveTextContent('Gescat · search-customers');
        expect(screen.getByRole('progressbar')).toHaveAttribute('data-kind', 'mcp');
    });

    it('switches to result analysis after a tool responds', () => {
        render(
            <AgentActivityBar
                events={[{ ...progressEvent, type: 'tool.completed', message: 'list-orders completato.' }]}
                active
                awaitingConfirmation={false}
                onCancel={() => undefined}
                onContinue={() => undefined}
            />,
        );

        expect(screen.getByTestId('agent-activity-heading')).toHaveTextContent('Analisi del risultato');
        expect(screen.getByRole('progressbar')).toHaveAttribute('data-kind', 'analyzing');
    });

    it('shows local MCP request and response details inside the matching activity event', async () => {
        const mcpEvent: AgentRunEvent = {
            ...progressEvent,
            sequence: 3,
            type: 'tool.completed',
            message: 'list-my-orders completato.',
            data: {
                tool: 'mcp_gescat_list_my_orders',
                mcp_debug: {
                    protocol: 'MCP',
                    method: 'tools/call',
                    runtime: 'connector',
                    server_name: 'Gescat',
                    connection_id: 'connection-1',
                    tool_local_name: 'mcp_gescat_list_my_orders',
                    tool_remote_name: 'list-my-orders',
                    status: 'ok',
                    duration_ms: 42,
                    parameters: { customer_id: 17 },
                    response: { orders: [{ id: 123 }] },
                    error: null,
                },
            },
        };

        render(<AgentActivityBar events={[progressEvent, mcpEvent]} active={false} awaitingConfirmation={false} onCancel={() => undefined} onContinue={() => undefined} />);

        expect(screen.queryByText('Dettagli chiamata MCP')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Mostra attività' }));
        expect(screen.getByText('Dettagli chiamata MCP')).toBeInTheDocument();
        expect(screen.getAllByText('list-my-orders').length).toBeGreaterThan(0);
        expect(screen.getByText('ok · 42 ms')).toBeInTheDocument();
        expect(screen.getByText(/"customer_id": 17/)).toBeInTheDocument();
        expect(screen.getByText(/"id": 123/)).toBeInTheDocument();

        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
        fireEvent.click(screen.getByRole('button', { name: 'Copia: Parametri' }));
        await waitFor(() => expect(writeText).toHaveBeenCalledWith('{\n  "customer_id": 17\n}'));
        expect(screen.getByRole('button', { name: 'Copiato: Parametri' })).toBeInTheDocument();
    });

    it('keeps MCP debug reachable even when it is the only activity event', () => {
        render(
            <AgentActivityBar
                events={[{
                    ...progressEvent,
                    data: {
                        mcp_debug: {
                            method: 'tools/call',
                            runtime: 'connector',
                            server_name: 'Gescat',
                            connection_id: null,
                            tool_local_name: 'mcp_gescat_get_order',
                            tool_remote_name: 'get-order',
                            status: 'ok',
                            duration_ms: 18,
                            parameters: { id: 10 },
                            response: { id: 10 },
                            error: null,
                        },
                    },
                }]}
                active={false}
                awaitingConfirmation={false}
                onCancel={() => undefined}
                onContinue={() => undefined}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Mostra attività' }));
        expect(screen.getByText('Dettagli chiamata MCP')).toBeInTheDocument();
    });

    it('does not render MCP debug controls when the backend omits the local-only payload', () => {
        render(<AgentActivityBar events={[progressEvent]} active={false} awaitingConfirmation={false} onCancel={() => undefined} onContinue={() => undefined} />);

        expect(screen.queryByText('Dettagli chiamata MCP')).not.toBeInTheDocument();
    });
});
