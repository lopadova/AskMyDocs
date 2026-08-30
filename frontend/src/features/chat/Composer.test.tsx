import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { Composer } from './Composer';
import { useChatStore } from './chat.store';
import type { FilterState } from './chat.api';

/**
 * v4.0/W3.2 — Composer is now a controlled component for filters +
 * streaming state. Tests pass props directly instead of mounting an
 * api client; the post-on-send behaviour now lives in ChatView's
 * `handleSend` (which wraps `useChatStream().sendMessage()`), so
 * Composer's tests stay focused on its own concerns: client-side
 * validation, draft → onSend wiring, send/stop morph.
 */

function renderWithClient(ui: ReactElement) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

function makeProps(overrides: Partial<{
    conversationId: number | null;
    filters: FilterState;
    onFiltersChange: (next: FilterState | ((p: FilterState) => FilterState)) => void;
    onSend: (content: string) => void | Promise<void>;
    onStop: () => void;
    isStreaming: boolean;
    error: Error | null;
}> = {}) {
    return {
        conversationId: 1,
        filters: {},
        onFiltersChange: vi.fn(),
        onSend: vi.fn(),
        onStop: vi.fn(),
        isStreaming: false,
        error: null,
        ...overrides,
    };
}

describe('Composer', () => {
    beforeEach(() => {
        useChatStore.setState({
            activeConversationId: 1,
            draft: '',
            isListening: false,
            showGraph: false,
            sidebarOpen: true,
        });
    });

    it('rejects an empty draft and opens accessible validation details', async () => {
        const props = makeProps();
        renderWithClient(<Composer {...props} />);
        const send = screen.getByTestId('chat-composer-send');
        fireEvent.click(send);
        expect(await screen.findByTestId('message-error')).toHaveTextContent('required');
        expect(screen.getByTestId('message-error-trigger')).toHaveAttribute('aria-expanded', 'true');
        expect(props.onSend).not.toHaveBeenCalled();
    });

    it('calls onSend with the typed message when the form submits', () => {
        const onSend = vi.fn();
        const props = makeProps({ onSend });
        renderWithClient(<Composer {...props} />);

        const input = screen.getByTestId('chat-composer-input') as HTMLTextAreaElement;
        fireEvent.change(input, { target: { value: 'Hello' } });
        fireEvent.submit(input.closest('form') as HTMLFormElement);

        expect(onSend).toHaveBeenCalledWith('Hello');
    });

    it('uses a restrained container focus state and canonical action buttons', () => {
        renderWithClient(<Composer {...makeProps()} />);

        const form = screen.getByTestId('chat-composer');
        const input = screen.getByTestId('chat-composer-input');
        const attach = screen.getByTestId('chat-composer-attach');
        const voice = screen.getByTestId('chat-composer-voice');
        const send = screen.getByTestId('chat-composer-send');

        expect(form).not.toHaveClass('glow-frame');
        fireEvent.focus(input);
        expect(form).toHaveClass('is-focused');
        fireEvent.blur(input);
        expect(form).not.toHaveClass('is-focused');
        expect(attach).toHaveClass('ui-button');
        expect(voice).toHaveClass('ui-button');
        expect(send).toHaveClass('ui-button');
        expect(send).toHaveAttribute('data-variant', 'primary');
    });

    it('renders chat-composer-stop instead of chat-composer-send while isStreaming', () => {
        const props = makeProps({ isStreaming: true });
        renderWithClient(<Composer {...props} />);
        expect(screen.queryByTestId('chat-composer-send')).toBeNull();
        expect(screen.getByTestId('chat-composer-stop')).toBeInTheDocument();
    });

    it('calls onStop when chat-composer-stop is clicked', () => {
        const onStop = vi.fn();
        const props = makeProps({ isStreaming: true, onStop });
        renderWithClient(<Composer {...props} />);
        fireEvent.click(screen.getByTestId('chat-composer-stop'));
        expect(onStop).toHaveBeenCalledTimes(1);
    });

    it('surfaces an external error via chat-composer-error', () => {
        const props = makeProps({ error: new Error('Provider rate limited') });
        renderWithClient(<Composer {...props} />);
        const trigger = screen.getByTestId('chat-composer-error-trigger');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(trigger);
        expect(screen.getByTestId('chat-composer-error')).toHaveTextContent('Provider rate limited');
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
    });

    it('does not repeat the same send error as both local and external feedback', async () => {
        const message = 'I could not complete the search.';
        const props = makeProps({
            error: new Error(message),
            onSend: vi.fn().mockRejectedValue(new Error(message)),
        });
        renderWithClient(<Composer {...props} />);

        const input = screen.getByTestId('chat-composer-input');
        fireEvent.change(input, { target: { value: 'Try this' } });
        fireEvent.submit(screen.getByTestId('chat-composer'));

        await waitFor(() => expect(input).toHaveValue('Try this'));
        expect(props.onSend).toHaveBeenCalledOnce();
        expect(screen.queryByTestId('message-error')).not.toBeInTheDocument();
        fireEvent.click(screen.getByTestId('chat-composer-error-trigger'));
        expect(screen.getByTestId('chat-composer-error')).toHaveTextContent(message);
        expect(screen.getAllByText(message)).toHaveLength(1);
    });
});
