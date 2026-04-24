import { useEffect, useRef, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { chatApi, type Message } from './chat.api';
import { MessageBubble } from './MessageBubble';
import { Icon } from '../../components/Icons';

export interface MessageThreadProps {
    conversationId: number | null;
    projectKey?: string | null;
    isSending?: boolean;
}

/**
 * Scrollable message thread. Scrolls to the bottom whenever new
 * messages arrive. Empty state shows three suggested prompts so a
 * brand-new user has somewhere to start.
 *
 * R11: `data-state ∈ {idle, loading, ready, empty, error}` +
 * `aria-live="polite"` so screen readers announce new messages, and
 * `aria-busy` flips during send.
 */
export function MessageThread({ conversationId, projectKey, isSending = false }: MessageThreadProps): ReactNode {
    const threadRef = useRef<HTMLDivElement>(null);

    const { data, isLoading, isError, error } = useQuery<Message[]>({
        queryKey: ['messages', conversationId ?? 'none'],
        queryFn: () => {
            if (conversationId === null) {
                return Promise.resolve<Message[]>([]);
            }
            return chatApi.listMessages(conversationId);
        },
        enabled: conversationId !== null,
        staleTime: 0,
    });

    useEffect(() => {
        threadRef.current?.scrollTo({ top: threadRef.current.scrollHeight, behavior: 'smooth' });
    }, [data?.length, isSending]);

    const messages = data ?? [];
    const state = resolveState({ conversationId, isLoading, isError, messageCount: messages.length });

    return (
        <section
            ref={threadRef}
            data-testid="chat-thread"
            data-state={state}
            aria-label="Conversation messages"
            aria-live="polite"
            aria-busy={isLoading || isSending}
            className="grid-bg"
            style={{ flex: 1, overflow: 'auto', padding: '24px 32px' }}
        >
            <div style={{ maxWidth: 780, margin: '0 auto' }}>
                {state === 'empty' && <EmptyThread />}
                {state === 'error' && (
                    <div data-testid="chat-thread-error" role="alert" style={errorStyle}>
                        {(error as Error | undefined)?.message ?? 'Could not load messages.'}
                    </div>
                )}
                {messages.map((m) => (
                    <MessageBubble
                        key={m.id}
                        conversationId={conversationId as number}
                        message={m}
                        projectKey={projectKey}
                    />
                ))}
                {isSending && (
                    <MessageBubble
                        conversationId={conversationId as number}
                        message={{
                            id: -1,
                            role: 'assistant',
                            content: '',
                            metadata: null,
                            rating: null,
                            created_at: new Date().toISOString(),
                        }}
                        streaming
                    />
                )}
            </div>
        </section>
    );
}

function resolveState(args: {
    conversationId: number | null;
    isLoading: boolean;
    isError: boolean;
    messageCount: number;
}): 'idle' | 'loading' | 'ready' | 'empty' | 'error' {
    if (args.conversationId === null) {
        return 'idle';
    }
    if (args.isLoading) {
        return 'loading';
    }
    if (args.isError) {
        return 'error';
    }
    if (args.messageCount === 0) {
        return 'empty';
    }
    return 'ready';
}

const errorStyle = {
    padding: '12px 14px',
    borderRadius: 10,
    background: 'rgba(239,68,68,.08)',
    border: '1px solid rgba(239,68,68,.3)',
    color: 'var(--err)',
    fontSize: 13,
} as const;

function EmptyThread(): ReactNode {
    const prompts = [
        'How does PTO work for new hires?',
        'Show me the remote work policy',
        'What’s the incident response checklist?',
    ];
    return (
        <div
            data-testid="chat-thread-empty"
            style={{
                maxWidth: 560,
                margin: '64px auto',
                padding: 24,
                background: 'var(--panel-solid)',
                border: '1px solid var(--panel-border)',
                borderRadius: 14,
                textAlign: 'center',
            }}
        >
            <div
                style={{
                    width: 40,
                    height: 40,
                    margin: '0 auto 10px',
                    background: 'var(--grad-accent)',
                    borderRadius: 10,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                }}
            >
                <Icon.Sparkles size={18} />
            </div>
            <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 6 }}>Ask your knowledge base</div>
            <div style={{ fontSize: 13, color: 'var(--fg-2)', marginBottom: 18, lineHeight: 1.6 }}>
                Answers are grounded in your canonical docs. Every reply cites the
                sources it pulled from.
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                {prompts.map((p, i) => (
                    <button
                        key={i}
                        type="button"
                        data-testid={`chat-suggested-prompt-${i}`}
                        className="btn"
                        style={{
                            justifyContent: 'flex-start',
                            fontSize: 12.5,
                            color: 'var(--fg-1)',
                            background: 'var(--bg-2)',
                            border: '1px solid var(--panel-border)',
                        }}
                    >
                        {p}
                    </button>
                ))}
            </div>
        </div>
    );
}
