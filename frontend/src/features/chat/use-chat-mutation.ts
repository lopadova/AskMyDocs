import { useMutation, useQueryClient, type UseMutationResult } from '@tanstack/react-query';
import { chatApi, type Message } from './chat.api';

interface SendMessageArgs {
    conversationId: number;
    content: string;
}

/**
 * Sends a user message, persists the assistant reply, and optimistically
 * appends the user turn to the message cache so the UI shows it
 * immediately without waiting for the round-trip.
 *
 * Errors surface via the standard TanStack `error` field — the
 * consumer (Composer / MessageThread) renders a testid-tagged inline
 * error (see R11).
 */
interface MutationContext {
    previous: Message[] | undefined;
    optimisticId: number;
}

export function useChatMutation(): UseMutationResult<Message, Error, SendMessageArgs, MutationContext> {
    const qc = useQueryClient();
    return useMutation<Message, Error, SendMessageArgs, MutationContext>({
        mutationFn: async ({ conversationId, content }) => {
            return chatApi.sendMessage(conversationId, content);
        },
        onMutate: async ({ conversationId, content }) => {
            await qc.cancelQueries({ queryKey: ['messages', conversationId] });
            const previous = qc.getQueryData<Message[]>(['messages', conversationId]);
            const optimisticId = -Date.now();
            qc.setQueryData<Message[]>(['messages', conversationId], (old) => {
                const tmp: Message = {
                    id: optimisticId,
                    role: 'user',
                    content,
                    metadata: null,
                    rating: null,
                    created_at: new Date().toISOString(),
                };
                return old ? [...old, tmp] : [tmp];
            });
            return { previous, optimisticId };
        },
        onError: (_err, { conversationId }, context) => {
            if (context?.previous) {
                qc.setQueryData(['messages', conversationId], context.previous);
            }
        },
        onSuccess: (assistantMessage, { conversationId }, context) => {
            // Copilot #5 fix: remove ONLY the specific optimistic id we
            // inserted, not every negative-id message. Otherwise a user
            // sending two messages back-to-back would see the first
            // optimistic echo flicker out while the second is still in
            // flight. The optimistic user row stays visible until the
            // `invalidateQueries` refetch replaces it with the canonical
            // server row.
            qc.setQueryData<Message[]>(['messages', conversationId], (old) => {
                if (!old) {
                    return [assistantMessage];
                }
                const optimisticId = context?.optimisticId;
                const filtered = optimisticId === undefined
                    ? old
                    : old.filter((m) => m.id !== optimisticId);
                return [...filtered, assistantMessage];
            });
            qc.invalidateQueries({ queryKey: ['conversations'] });
            qc.invalidateQueries({ queryKey: ['messages', conversationId] });
        },
    });
}
