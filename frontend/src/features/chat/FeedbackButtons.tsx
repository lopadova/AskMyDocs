import { useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { chatApi, type Message } from './chat.api';

export interface FeedbackButtonsProps {
    conversationId: number;
    messageId: number;
    initialRating: 'positive' | 'negative' | null;
}

/**
 * Two icon buttons (thumbs up / thumbs down) that POST to the feedback
 * endpoint and optimistically toggle the rating. Second click on the
 * same button clears the rating — matches the server's toggle semantics
 * in FeedbackController.
 */
export function FeedbackButtons({ conversationId, messageId, initialRating }: FeedbackButtonsProps): ReactNode {
    const [rating, setRating] = useState<'positive' | 'negative' | null>(initialRating);
    const qc = useQueryClient();

    const mutation = useMutation<{ rating: 'positive' | 'negative' | null }, Error, 'positive' | 'negative'>({
        mutationFn: (next) => chatApi.rateMessage(conversationId, messageId, next),
        onMutate: async (next) => {
            const before = rating;
            setRating(before === next ? null : next);
        },
        onSuccess: ({ rating: serverRating }) => {
            setRating(serverRating);
            qc.setQueryData<Message[]>(['messages', conversationId], (old) =>
                old?.map((m) => (m.id === messageId ? { ...m, rating: serverRating } : m)),
            );
        },
        onError: () => {
            // Revert optimistic flip.
            setRating(initialRating);
        },
    });

    return (
        <div data-testid="chat-feedback" data-rating={rating ?? 'none'} style={{ display: 'inline-flex', gap: 2 }}>
            <button
                type="button"
                className="btn icon sm ghost"
                data-testid="chat-feedback-up"
                aria-label="Mark answer as helpful"
                aria-pressed={rating === 'positive'}
                onClick={() => mutation.mutate('positive')}
                disabled={mutation.isPending}
                style={{ color: rating === 'positive' ? 'var(--accent-a)' : undefined }}
            >
                <span style={{ fontSize: 12 }}>👍</span>
            </button>
            <button
                type="button"
                className="btn icon sm ghost"
                data-testid="chat-feedback-down"
                aria-label="Mark answer as unhelpful"
                aria-pressed={rating === 'negative'}
                onClick={() => mutation.mutate('negative')}
                disabled={mutation.isPending}
                style={{ color: rating === 'negative' ? '#ef4444' : undefined }}
            >
                <span style={{ fontSize: 12 }}>👎</span>
            </button>
            {mutation.isError && (
                <span data-testid="chat-feedback-error" role="alert" style={{ fontSize: 11, color: 'var(--err)' }}>
                    Couldn’t save rating.
                </span>
            )}
        </div>
    );
}
