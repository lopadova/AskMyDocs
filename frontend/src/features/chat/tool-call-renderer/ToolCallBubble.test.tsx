import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ToolCallBubble, type ToolCallData } from './ToolCallBubble';

const { postMock } = vi.hoisted(() => ({ postMock: vi.fn() }));
vi.mock('../../../lib/api', () => ({ api: { post: postMock } }));

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
});
