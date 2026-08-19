import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ToolCallBubble, type ToolCallData } from './ToolCallBubble';

const { getMock, postMock } = vi.hoisted(() => ({ getMock: vi.fn(), postMock: vi.fn() }));
vi.mock('../../../lib/api', () => ({ api: { get: getMock, post: postMock } }));

function makeToolCall(overrides: Partial<ToolCallData> = {}): ToolCallData {
    return {
        id: 'tool_42',
        name: 'list_repositories',
        status: 'ok',
        server_name: 'github',
        server_id: 1,
        arguments: { owner: 'lopadova' },
        result: { repositories: ['AskMyDocs', 'laravel-flow'] },
        error: null,
        ...overrides,
    };
}

describe('ToolCallBubble', () => {
    it('renders the tool name + status pill + via-server hint', () => {
        render(<ToolCallBubble toolCall={makeToolCall()} />);
        const bubble = screen.getByTestId('chat-tool-call-tool_42');
        expect(bubble).toHaveAttribute('data-tool-name', 'list_repositories');
        expect(bubble).toHaveAttribute('data-tool-status', 'ok');
        expect(screen.getByText('list_repositories')).toBeInTheDocument();
        expect(screen.getByText(/via github/i)).toBeInTheDocument();
        expect(screen.getByText(/completed/i)).toBeInTheDocument();
    });

    it('toggles the details panel when the header is clicked', () => {
        render(<ToolCallBubble toolCall={makeToolCall()} />);
        expect(screen.queryByTestId('chat-tool-call-tool_42-details')).toBeNull();
        fireEvent.click(screen.getByTestId('chat-tool-call-tool_42-toggle'));
        expect(screen.getByTestId('chat-tool-call-tool_42-details')).toBeInTheDocument();
        expect(screen.getByTestId('chat-tool-call-tool_42-arguments')).toBeInTheDocument();
        expect(screen.getByTestId('chat-tool-call-tool_42-result')).toBeInTheDocument();
    });

    it('surfaces the error section for failed tool calls', () => {
        render(
            <ToolCallBubble
                toolCall={makeToolCall({
                    status: 'error',
                    error: 'Stdio MCP server crashed',
                    result: null,
                })}
            />,
        );
        fireEvent.click(screen.getByTestId('chat-tool-call-tool_42-toggle'));
        const errorSection = screen.getByTestId('chat-tool-call-tool_42-error');
        expect(errorSection).toBeInTheDocument();
        expect(errorSection).toHaveTextContent('Stdio MCP server crashed');
    });

    it('renders the timeout label when status is timeout', () => {
        render(<ToolCallBubble toolCall={makeToolCall({ status: 'timeout' })} />);
        expect(screen.getByText(/timeout/i)).toBeInTheDocument();
    });

    it('renders the denied label and lock icon when status is denied', () => {
        render(<ToolCallBubble toolCall={makeToolCall({ status: 'denied' })} />);
        expect(screen.getByText(/denied/i)).toBeInTheDocument();
    });

    it('resumes a confirmation-scoped MCP call through the authenticated conversation endpoint', async () => {
        postMock.mockResolvedValueOnce({ data: { status: 'completed', artifact: { text: 'Write completed.' } } });
        render(
            <ToolCallBubble
                conversationId={17}
                toolCall={makeToolCall({
                    status: 'confirmation_required',
                    pending_interaction_id: '01K2PENDING',
                    prompt: { message: 'Confirm this write.' },
                    result: null,
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(postMock).toHaveBeenCalledWith(
            '/api/conversations/mcp/interactions/01K2PENDING',
            { conversation_id: '17', response: { confirmed: true } },
        ));
        expect(await screen.findByTestId('chat-tool-call-tool_42-resumed-result')).toHaveTextContent('Write completed.');
        expect(screen.getByTestId('chat-tool-call-tool_42')).toHaveAttribute('data-tool-status', 'ok');
    });

    it('polls a persisted MCP task and renders its final artifact', async () => {
        getMock.mockResolvedValueOnce({
            data: {
                task_id: '01M0TASK',
                status: 'completed',
                artifact: { text: 'Generated report.' },
                terminal: true,
            },
        });
        render(
            <ToolCallBubble
                conversationId={17}
                toolCall={makeToolCall({
                    status: 'task_accepted',
                    task_id: '01M0TASK',
                    task: { status: 'working', poll_interval_ms: 1000 },
                    result: null,
                })}
            />,
        );

        await waitFor(() => expect(getMock).toHaveBeenCalledWith(
            '/api/conversations/mcp/tasks/01M0TASK',
            { params: { conversation_id: '17' } },
        ));
        expect(await screen.findByTestId('chat-tool-call-tool_42-resumed-result')).toHaveTextContent('Generated report.');
        expect(screen.getByTestId('chat-tool-call-tool_42')).toHaveAttribute('data-tool-status', 'ok');
    });

    it('submits task input through the task-scoped endpoint', async () => {
        getMock.mockResolvedValueOnce({
            data: {
                task_id: '01M0TASKINPUT',
                status: 'input_required',
                input_requests: { approval: { method: 'elicitation/create' } },
            },
        });
        postMock.mockResolvedValueOnce({
            data: {
                task_id: '01M0TASKINPUT',
                status: 'completed',
                artifact: { text: 'Approved result.' },
                terminal: true,
            },
        });
        render(
            <ToolCallBubble
                conversationId={18}
                toolCall={makeToolCall({ status: 'task_accepted', task_id: '01M0TASKINPUT', result: null })}
            />,
        );

        const input = await screen.findByLabelText('MCP input responses');
        fireEvent.change(input, { target: { value: '{"approval":{"approved":true}}' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send input' }));

        await waitFor(() => expect(postMock).toHaveBeenCalledWith(
            '/api/conversations/mcp/tasks/01M0TASKINPUT/input',
            {
                conversation_id: '18',
                input_responses: { approval: { approved: true } },
            },
        ));
        expect(await screen.findByTestId('chat-tool-call-tool_42-resumed-result')).toHaveTextContent('Approved result.');
    });

    it('requests cooperative cancellation without marking the task cancelled prematurely', async () => {
        getMock.mockResolvedValueOnce({
            data: {
                task_id: '01M0TASKCANCEL',
                status: 'working',
                poll_interval_ms: 30_000,
            },
        });
        postMock.mockResolvedValueOnce({
            data: {
                task_id: '01M0TASKCANCEL',
                status: 'working',
                poll_interval_ms: 30_000,
                cancel_requested: true,
            },
        });
        render(
            <ToolCallBubble
                conversationId={19}
                toolCall={makeToolCall({ status: 'task_accepted', task_id: '01M0TASKCANCEL', result: null })}
            />,
        );

        fireEvent.click(await screen.findByRole('button', { name: 'Cancel task' }));

        await waitFor(() => expect(postMock).toHaveBeenCalledWith(
            '/api/conversations/mcp/tasks/01M0TASKCANCEL/cancel',
            { conversation_id: '19' },
        ));
        expect(screen.getByTestId('chat-tool-call-tool_42')).toHaveAttribute('data-tool-status', 'cancel_requested');
        expect(screen.getByRole('button', { name: 'Cancellation requested' })).toBeDisabled();
    });
});
