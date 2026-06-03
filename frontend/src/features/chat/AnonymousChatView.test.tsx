import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { AnonymousChatView } from './AnonymousChatView';
import { anonymousChatApi, type AnonymousChatAnswer } from './chat.api';

/**
 * v8.8.3 — AnonymousChatView unit coverage. R16: each test drives the
 * transition its name promises (disabled landing, happy send, refusal).
 */

vi.mock('@tanstack/react-router', () => ({
    useNavigate: () => vi.fn(),
}));

// Markdown pulls remark/unified — render a plain passthrough so the test
// stays focused on the view's own behaviour, not markdown internals.
vi.mock('../../lib/markdown', () => ({
    Markdown: ({ source }: { source: string }) => <div data-testid="md">{source}</div>,
}));

vi.mock('./chat.api', async (orig) => {
    const actual = await orig<typeof import('./chat.api')>();
    return {
        ...actual,
        anonymousChatApi: {
            config: vi.fn(),
            send: vi.fn(),
        },
    };
});

const mockedConfig = vi.mocked(anonymousChatApi.config);
const mockedSend = vi.mocked(anonymousChatApi.send);

function renderView(ui: ReactElement) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

function answer(overrides: Partial<AnonymousChatAnswer> = {}): AnonymousChatAnswer {
    return {
        answer: 'The grounded answer.',
        citations: [],
        confidence: 80,
        refusal_reason: null,
        meta: { provider: 'fake', model: 'm', chunks_used: 2, primary_count: 2, latency_ms: 5 },
        ...overrides,
    };
}

describe('AnonymousChatView', () => {
    beforeEach(() => {
        mockedConfig.mockReset();
        mockedSend.mockReset();
    });

    it('renders the disabled landing when the feature is off', async () => {
        mockedConfig.mockResolvedValue({ enabled: false });
        renderView(<AnonymousChatView />);

        expect(await screen.findByTestId('anonymous-chat-disabled')).toBeInTheDocument();
        // No composer when disabled.
        expect(screen.queryByTestId('anonymous-chat-input')).not.toBeInTheDocument();
    });

    it('shows the banner + empty state when enabled', async () => {
        mockedConfig.mockResolvedValue({ enabled: true });
        renderView(<AnonymousChatView />);

        expect(await screen.findByTestId('anonymous-chat-banner')).toBeInTheDocument();
        expect(screen.getByTestId('anonymous-chat-empty')).toBeInTheDocument();
        expect(screen.getByTestId('anonymous-chat-input')).toBeInTheDocument();
    });

    it('posts the question and renders the grounded answer', async () => {
        const user = userEvent.setup();
        mockedConfig.mockResolvedValue({ enabled: true });
        mockedSend.mockResolvedValue(answer({ answer: 'Rotate via the admin panel.' }));

        renderView(<AnonymousChatView />);
        const input = await screen.findByTestId('anonymous-chat-input');
        await user.type(input, 'How do I rotate the key?');
        await user.click(screen.getByTestId('anonymous-chat-send'));

        expect(await screen.findByTestId('anonymous-chat-turn-0-answer')).toHaveTextContent(
            'Rotate via the admin panel.',
        );
        expect(mockedSend).toHaveBeenCalledWith('How do I rotate the key?');
        // The empty state is gone once a turn exists.
        expect(screen.queryByTestId('anonymous-chat-empty')).not.toBeInTheDocument();
    });

    it('renders a refusal notice (not an answer) when the BE refuses', async () => {
        const user = userEvent.setup();
        mockedConfig.mockResolvedValue({ enabled: true });
        mockedSend.mockResolvedValue(
            answer({ answer: 'No grounded answer available.', refusal_reason: 'no_relevant_context', confidence: 0 }),
        );

        renderView(<AnonymousChatView />);
        const input = await screen.findByTestId('anonymous-chat-input');
        await user.type(input, 'Unknown thing?');
        await user.click(screen.getByTestId('anonymous-chat-send'));

        const notice = await screen.findByTestId('refusal-notice');
        expect(notice).toHaveAttribute('data-reason', 'no_relevant_context');
        expect(screen.queryByTestId('anonymous-chat-turn-0-answer')).not.toBeInTheDocument();
    });

    it('surfaces a send error in the DOM', async () => {
        const user = userEvent.setup();
        mockedConfig.mockResolvedValue({ enabled: true });
        mockedSend.mockRejectedValue(new Error('Network down'));

        renderView(<AnonymousChatView />);
        const input = await screen.findByTestId('anonymous-chat-input');
        await user.type(input, 'anything');
        await user.click(screen.getByTestId('anonymous-chat-send'));

        await waitFor(() =>
            expect(screen.getByTestId('anonymous-chat-turn-0-error')).toHaveTextContent('Network down'),
        );
    });
});
