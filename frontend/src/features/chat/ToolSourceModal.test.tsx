import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setLocalUiLocale } from '../../lib/ui-locale';
import { chatApi, type Message, type ToolSourceDetail } from './chat.api';
import { MessageBubble } from './MessageBubble';
import { ToolSourceModal } from './ToolSourceModal';
import { getToolSources } from './message-shape-adapters';

const detail: ToolSourceDetail = {
    execution_id: 17, tool: 'gescat_cerca_ordini', display_name: 'GESCAT · Ordini', kind: 'api', status: 'completed',
    arguments: { ord_codiceweb: 'ORDER-A' },
    request: { method: 'GET', url: 'https://erp.example.test/orders', query: { ord_codiceweb: 'ORDER-A' }, headers: {}, body: null },
    response: { data: [{ ord_codiceweb: 'ORDER-A', note: '<script>not executable</script>' }] },
    complete: true, stop_reason: null, physical_requests: 1, latency_ms: 84, retrieved_at: '2026-10-27T08:40:00Z',
};

function client() { return new QueryClient({ defaultOptions: { queries: { retry: false } } }); }
function renderModal() {
    return render(<QueryClientProvider client={client()}><ToolSourceModal source={detail} conversationId={9} messageId={23} onClose={vi.fn()} /></QueryClientProvider>);
}

describe('API source references', () => {
    beforeEach(() => setLocalUiLocale('en'));

    it('opens a recorded call alongside document references, supports copying, and closes with the keyboard', async () => {
        const user = userEvent.setup();
        const fetch = vi.spyOn(chatApi, 'fetchToolSource').mockResolvedValue(detail);
        const message: Message = { id: 23, role: 'assistant', content: 'Order information.', rating: null, created_at: '', metadata: {
            citations: [{ document_id: 1, title: 'Return label', source_path: 'label.pdf' }], tool_sources: [detail],
        } };
        render(<QueryClientProvider client={client()}><MessageBubble conversationId={9} message={message} /></QueryClientProvider>);
        expect(screen.getByTestId('chat-citations')).toHaveAttribute('data-count', '2');
        expect(fetch).not.toHaveBeenCalled();
        const chip = screen.getByRole('button', { name: 'Open API call: GESCAT · Ordini' });
        chip.focus();
        await user.keyboard('{Enter}');
        expect(await screen.findByTestId('chat-tool-source-response')).toHaveTextContent('ORDER-A');
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch).toHaveBeenCalledWith(9, 23, 17, null);
        expect(screen.getByRole('dialog')).toHaveTextContent('GET');
        expect(screen.getByTestId('chat-tool-source-request').textContent).toContain('\n  "query": {\n');
        expect(screen.getByTestId('chat-tool-source-response')).toHaveTextContent('<script>not executable</script>');
        expect(document.querySelector('script')).toBeNull();
        const copy = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue();
        await user.click(screen.getByRole('button', { name: 'Copy response' }));
        expect(copy).toHaveBeenCalledWith(JSON.stringify(detail.response, null, 2));
        const close = screen.getByRole('button', { name: 'Close API call' });
        close.focus();
        await user.keyboard('{Enter}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('shows API-only references even when no document was cited', () => {
        const message: Message = { id: 23, role: 'assistant', content: 'Order information.', rating: null, created_at: '', metadata: { tool_sources: [detail] } };
        const queryClient = client();
        const wrap = (bubble: React.ReactNode) => <QueryClientProvider client={queryClient}>{bubble}</QueryClientProvider>;
        const { rerender } = render(wrap(<MessageBubble conversationId={9} message={message} />));
        expect(screen.getByTestId('chat-citations')).toHaveAttribute('data-count', '1');
        rerender(wrap(<MessageBubble conversationId={9} message={message} streaming />));
        expect(screen.queryByTestId('chat-citations')).not.toBeInTheDocument();
        rerender(wrap(<MessageBubble conversationId={9} message={{ ...message, refusal_reason: 'insufficient_data' }} />));
        expect(screen.queryByTestId('chat-citations')).not.toBeInTheDocument();
    });

    it('handles loading and retries a failed read', async () => {
        let complete: (value: ToolSourceDetail) => void = () => undefined;
        const pending = new Promise<ToolSourceDetail>((resolve) => { complete = resolve; });
        const fetch = vi.spyOn(chatApi, 'fetchToolSource').mockRejectedValueOnce(new Error('Unavailable')).mockReturnValueOnce(pending);
        renderModal();
        expect(screen.getByRole('status')).toHaveTextContent('Loading');
        expect(await screen.findByRole('alert')).toHaveTextContent('Could not load this call');
        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
        await act(async () => { complete(detail); });
        expect(await screen.findByTestId('chat-tool-source-response')).toBeInTheDocument();
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('shows historical tool arguments without inventing an HTTP request, and marks partial results', async () => {
        vi.spyOn(chatApi, 'fetchToolSource').mockResolvedValue({ ...detail, request: null, complete: false, used_in_answer: false });
        renderModal();
        expect(await screen.findByTestId('chat-tool-source-request')).toHaveTextContent('gescat_cerca_ordini');
        expect(screen.getByRole('heading', { name: 'Tool call' })).toBeInTheDocument();
        expect(screen.getByText(/HTTP request snapshot is not available/)).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('This result is partial.');
        expect(screen.getByText(/Called during research/)).toBeInTheDocument();
    });

    it('exposes a useful fallback when copying fails', async () => {
        const user = userEvent.setup();
        vi.spyOn(chatApi, 'fetchToolSource').mockResolvedValue({ ...detail, response: null });
        renderModal();
        await screen.findByTestId('chat-tool-source-request');
        expect(screen.getByText('No response was recorded for this call.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Copy response' })).not.toBeInTheDocument();
        vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(new Error('Denied'));
        await user.click(screen.getByRole('button', { name: 'Copy request' }));
        expect(screen.getByRole('status')).toHaveTextContent('Select the text');
    });

    it('filters malformed, duplicate and non-external source references', () => {
        const other = { ...detail, execution_id: 19, tool: 'other_call' };
        const message = { metadata: { tool_sources: [detail, detail, { ...detail, execution_id: -1 }, { ...detail, execution_id: 18, kind: 'catalog' }, null],
            tool_references: [detail, other] } } as unknown as Message;
        expect(getToolSources(message)).toEqual([detail, other]);
    });

    it('renders the call controls and headings in Italian', async () => {
        setLocalUiLocale('it');
        vi.spyOn(chatApi, 'fetchToolSource').mockResolvedValue(detail);
        renderModal();
        expect(await screen.findByRole('heading', { name: 'Risposta ottenuta' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Chiudi chiamata API' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Copia richiesta' })).toBeInTheDocument();
    });
});
