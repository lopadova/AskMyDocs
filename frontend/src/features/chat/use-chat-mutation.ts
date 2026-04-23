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
export function useChatMutation(): UseMutationResult<Message, Error, SendMessageArgs, { previous: Message[] | undefined }> {
    const qc = useQueryClient();
    return useMutation<Message, Error, SendMessageArgs, { previous: Message[] | undefined }>({
        mutationFn: async ({ conversationId, content }) => {
            return chatApi.sendMessage(conversationId, content);
        },
        onMutate: async ({ conversationId, content }) => {
            await qc.cancelQueries({ queryKey: ['messages', conversationId] });
            const previous = qc.getQueryData<Message[]>(['messages', conversationId]);
            qc.setQueryData<Message[]>(['messages', conversationId], (old) => {
                const tmp: Message = {
                    id: -Date.now(),
                    role: 'user',
                    content,
                    metadata: null,
                    rating: null,
                    created_at: new Date().toISOString(),
                };
                return old ? [...old, tmp] : [tmp];
            });
            return { previous };
        },
        onError: (_err, { conversationId }, context) => {
            if (context?.previous) {
                qc.setQueryData(['messages', conversationId], context.previous);
            }
        },
        onSuccess: (assistantMessage, { conversationId }) => {
            qc.setQueryData<Message[]>(['messages', conversationId], (old) => {
                if (!old) {
                    return [assistantMessage];
                }
                // Drop the optimistic user placeholder (negative id) and append
                // the real assistant message. The server also persisted the
                // real user row — refetching once makes the ids canonical.
                const filtered = old.filter((m) => m.id > 0);
                return [...filtered, assistantMessage];
            });
            // Conversation list's updated_at moved — bump it.
            qc.invalidateQueries({ queryKey: ['conversations'] });
            // Pull the canonical message list (gets real user id from server).
            qc.invalidateQueries({ queryKey: ['messages', conversationId] });
        },
    });
}
