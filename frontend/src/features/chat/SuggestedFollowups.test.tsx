import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent, cleanup } from '@testing-library/react';
import { SuggestedFollowups } from './SuggestedFollowups';
import { chatApi } from './chat.api';

vi.mock('./chat.api', async (importOriginal) => {
    const actual = (await importOriginal()) as Record<string, unknown>;
    return {
        ...actual,
        chatApi: {
            ...((actual as { chatApi: Record<string, unknown> }).chatApi),
            suggestedFollowups: vi.fn(),
        },
    };
});

describe('SuggestedFollowups', () => {
    beforeEach(() => {
        cleanup();
        vi.mocked(chatApi.suggestedFollowups).mockReset();
    });

    it('renders nothing when conversationId is null', () => {
        const { container } = render(
            <SuggestedFollowups
                conversationId={null}
                turnId={1}
                isStreaming={false}
                onPick={() => undefined}
            />,
        );
        expect(container.firstChild).toBeNull();
        expect(chatApi.suggestedFollowups).not.toHaveBeenCalled();
    });

    it('renders nothing when turnId is 0 (no settled assistant turn yet)', () => {
        const { container } = render(
            <SuggestedFollowups
                conversationId={42}
                turnId={0}
                isStreaming={false}
                onPick={() => undefined}
            />,
        );
        expect(container.firstChild).toBeNull();
        expect(chatApi.suggestedFollowups).not.toHaveBeenCalled();
    });

    it('renders nothing while streaming', () => {
        const { container } = render(
            <SuggestedFollowups
                conversationId={42}
                turnId={1}
                isStreaming={true}
                onPick={() => undefined}
            />,
        );
        expect(container.firstChild).toBeNull();
        expect(chatApi.suggestedFollowups).not.toHaveBeenCalled();
    });

    it('renders three pills after fetching suggestions', async () => {
        vi.mocked(chatApi.suggestedFollowups).mockResolvedValue([
            'How does this affect remote workers?',
            'Compare with the v2 policy',
            'Why was the previous version rejected?',
        ]);

        render(
            <SuggestedFollowups
                conversationId={42}
                turnId={1}
                isStreaming={false}
                onPick={() => undefined}
            />,
        );

        await waitFor(() => {
            expect(screen.getByTestId('chat-suggested-followup-0')).toBeInTheDocument();
        });
        expect(screen.getByTestId('chat-suggested-followup-0')).toHaveTextContent('How does this affect remote workers?');
        expect(screen.getByTestId('chat-suggested-followup-1')).toHaveTextContent('Compare with the v2 policy');
        expect(screen.getByTestId('chat-suggested-followup-2')).toHaveTextContent('Why was the previous version rejected?');
        expect(screen.getByTestId('chat-suggested-followups')).toHaveAttribute('data-state', 'ready');
    });

    it('truncates to 3 pills if the BE returns more than 3', async () => {
        vi.mocked(chatApi.suggestedFollowups).mockResolvedValue([
            'one',
            'two',
            'three',
            'four',
            'five',
        ]);

        render(
            <SuggestedFollowups
                conversationId={42}
                turnId={2}
                isStreaming={false}
                onPick={() => undefined}
            />,
        );

        await waitFor(() => {
            expect(screen.getByTestId('chat-suggested-followup-0')).toBeInTheDocument();
        });
        expect(screen.queryByTestId('chat-suggested-followup-3')).toBeNull();
        expect(screen.queryByTestId('chat-suggested-followup-4')).toBeNull();
    });

    it('renders nothing when the BE returns an empty array', async () => {
        vi.mocked(chatApi.suggestedFollowups).mockResolvedValue([]);

        const { container } = render(
            <SuggestedFollowups
                conversationId={42}
                turnId={1}
                isStreaming={false}
                onPick={() => undefined}
            />,
        );

        // Wait until the promise settles, then assert the container is empty.
        await waitFor(() => {
            expect(chatApi.suggestedFollowups).toHaveBeenCalled();
        });
        await waitFor(() => {
            expect(container.firstChild).toBeNull();
        });
    });

    it('renders nothing when the BE call rejects (best-effort surface)', async () => {
        vi.mocked(chatApi.suggestedFollowups).mockRejectedValue(new Error('boom'));

        const { container } = render(
            <SuggestedFollowups
                conversationId={42}
                turnId={1}
                isStreaming={false}
                onPick={() => undefined}
            />,
        );

        await waitFor(() => {
            expect(chatApi.suggestedFollowups).toHaveBeenCalled();
        });
        await waitFor(() => {
            expect(container.firstChild).toBeNull();
        });
    });

    it('fires onPick with the prompt when a pill is clicked', async () => {
        vi.mocked(chatApi.suggestedFollowups).mockResolvedValue([
            'first prompt',
            'second prompt',
            'third prompt',
        ]);
        const onPick = vi.fn();

        render(
            <SuggestedFollowups
                conversationId={42}
                turnId={1}
                isStreaming={false}
                onPick={onPick}
            />,
        );

        const pill = await screen.findByTestId('chat-suggested-followup-1');
        fireEvent.click(pill);
        expect(onPick).toHaveBeenCalledWith('second prompt');
    });
});
