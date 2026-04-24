import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ChatLogRow, ChatLogsQuery, LogsPaginated } from './logs.api';

// Capture the last query the component passed to the list hook so
// we can assert the filter-keyed query shape. Also let tests mutate
// the mocked data + loading/error flags.
const chatState: {
    lastQuery: ChatLogsQuery | null;
    data: LogsPaginated<ChatLogRow> | undefined;
    isLoading: boolean;
    isError: boolean;
} = {
    lastQuery: null,
    data: undefined,
    isLoading: false,
    isError: false,
};

vi.mock('./logs.api', () => ({
    useChatLogs: (q: ChatLogsQuery) => {
        chatState.lastQuery = q;
        return {
            data: chatState.data,
            isLoading: chatState.isLoading,
            isError: chatState.isError,
        };
    },
    useChatLog: () => ({
        data: undefined,
        isLoading: false,
        isError: false,
    }),
}));

import { ChatLogsTab } from './ChatLogsTab';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

function sampleRow(id: number, overrides: Partial<ChatLogRow> = {}): ChatLogRow {
    return {
        id,
        session_id: 'uuid-' + id,
        user_id: null,
        question: 'q ' + id,
        answer: 'a ' + id,
        project_key: 'hr-portal',
        ai_provider: 'openai',
        ai_model: 'gpt-4o',
        chunks_count: 0,
        sources: [],
        prompt_tokens: 10,
        completion_tokens: 20,
        total_tokens: 30,
        latency_ms: 100,
        client_ip: null,
        user_agent: null,
        extra: null,
        created_at: '2026-04-24T10:00:00Z',
        ...overrides,
    };
}

describe('ChatLogsTab', () => {
    it('renders the loading state initially', () => {
        chatState.data = undefined;
        chatState.isLoading = true;
        chatState.isError = false;
        wrap(<ChatLogsTab />);
        expect(screen.getByTestId('chat-logs-loading')).toBeInTheDocument();
        expect(screen.getByTestId('chat-logs')).toHaveAttribute('data-state', 'loading');
    });

    it('surfaces an error panel when the query fails', () => {
        chatState.data = undefined;
        chatState.isLoading = false;
        chatState.isError = true;
        wrap(<ChatLogsTab />);
        expect(screen.getByTestId('chat-logs-error')).toBeInTheDocument();
        expect(screen.getByTestId('chat-logs')).toHaveAttribute('data-state', 'error');
    });

    it('renders the empty state when data is empty', () => {
        chatState.data = {
            data: [],
            meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 },
        };
        chatState.isLoading = false;
        chatState.isError = false;
        wrap(<ChatLogsTab />);
        expect(screen.getByTestId('chat-logs-empty')).toBeInTheDocument();
    });

    it('renders a row per entry with a stable testid', () => {
        chatState.data = {
            data: [sampleRow(1), sampleRow(2)],
            meta: { current_page: 1, per_page: 20, total: 2, last_page: 1 },
        };
        chatState.isLoading = false;
        chatState.isError = false;
        wrap(<ChatLogsTab />);
        expect(screen.getByTestId('chat-log-row-1')).toBeInTheDocument();
        expect(screen.getByTestId('chat-log-row-2')).toBeInTheDocument();
    });

    it('forwards filter values into the TanStack query key', async () => {
        chatState.data = {
            data: [],
            meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 },
        };
        chatState.isLoading = false;
        chatState.isError = false;
        wrap(<ChatLogsTab />);

        await userEvent.type(screen.getByTestId('chat-filter-project'), 'hr-portal');
        await userEvent.type(screen.getByTestId('chat-filter-model'), 'gpt-4o');
        await userEvent.type(screen.getByTestId('chat-filter-min-latency'), '500');

        // The mock captured the last query shape — assert the SPA
        // pushed the filters rather than inventing them.
        expect(chatState.lastQuery?.project).toBe('hr-portal');
        expect(chatState.lastQuery?.model).toBe('gpt-4o');
        expect(chatState.lastQuery?.min_latency_ms).toBe(500);
    });
});
