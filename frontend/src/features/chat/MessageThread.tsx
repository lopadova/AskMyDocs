import { useEffect, useRef, type ReactNode } from 'react';
import { MessageBubble } from './MessageBubble';
import { Icon } from '../../components/Icons';
import { mapStatusToDataState, type SdkStatus } from './map-status-to-data-state';
import type { RenderableMessage } from './message-shape-adapters';
import { getMessageId, getTextContent } from './message-shape-adapters';

export interface MessageThreadProps {
    conversationId: number | null;
    projectKey?: string | null;
    /**
     * Messages to render. Owner (ChatView) calls `useChatStream()` and
     * threads `chat.messages` here. Both legacy AppMessage (from the
     * initial GET history fetch) and SDK UIMessage (live streaming
     * shape) are accepted via the shape adapters in MessageBubble.
     */
    messages: RenderableMessage[];
    /**
     * SDK status from `useChatStream().status`. Drives the
     * `data-state` attribute via `mapStatusToDataState()`.
     */
    sdkStatus?: SdkStatus;
    /**
     * Set when the initial GET /messages history fetch is in flight
     * (TanStack Query). Maps to `data-state="loading"` BEFORE the SDK
     * takes over the streaming lifecycle.
     */
    isLoadingHistory?: boolean;
    /**
     * Surface from `useChatStream().error` (or the initial-fetch
     * error). Drives `data-state="error"` + chat-thread-error.
     */
    error?: Error | null;
}

/**
 * Scrollable message thread. Auto-scrolls to the bottom whenever new
 * messages arrive OR while a stream is in flight (the assistant
 * bubble grows token-by-token so the user always sees the latest).
 *
 * R11: `data-state ∈ {idle, loading, ready, empty, error}` +
 * `aria-live="polite"` so screen readers announce new messages, and
 * `aria-busy` flips during streaming.
 *
 * v4.0/W3.2: dropped the local `useQuery(['messages'])`. ChatView
 * owns the SDK hook and threads messages + status here. The internal
 * `resolveState` helper is replaced by `mapStatusToDataState()` so
 * the SDK's full status set (`submitted` | `streaming` | `ready` |
 * `error`) maps to the existing `data-state` vocabulary.
 */
export function MessageThread({
    conversationId,
    projectKey,
    messages,
    sdkStatus,
    isLoadingHistory = false,
    error = null,
}: MessageThreadProps): ReactNode {
    const threadRef = useRef<HTMLDivElement>(null);

    // Stream-aware auto-scroll trigger. During token-by-token streaming
    // the assistant message accretes text inside the SAME message
    // object — `messages.length` and `sdkStatus` both stay constant
    // (`'streaming'` throughout). Without a length-aware tracker the
    // scroll wouldn't follow the growing bubble. We delegate text
    // extraction to the `getTextContent` adapter (which handles both
    // AppMessage and UIMessage shapes correctly) and sum the lengths.
    //
    // Computed inline (no useMemo) on purpose: the SDK's internal
    // state-update strategy isn't part of its public contract, so a
    // memo keyed on the `messages` reference could go stale if the
    // SDK ever mutates in place. The reduce is O(messageCount ×
    // parts) which is trivial for typical chat threads (≤100
    // messages, ≤1ms per render); the useEffect below depends on
    // the resulting scalar so it always detects growth correctly.
    const totalTextLength = messages.reduce(
        (acc, m) => acc + getTextContent(m).length,
        0,
    );

    const isStreaming = sdkStatus === 'submitted' || sdkStatus === 'streaming';

    useEffect(() => {
        // Scroll behavior matches the user's expectation in each
        // phase: during streaming, the assistant body grows
        // token-by-token and EVERY delta would queue a fresh smooth
        // scroll → continuous interrupted animation on fast streams
        // (the browser cancels each smooth scroll mid-flight when
        // the next one starts). Use `'auto'` (instant) during the
        // streaming window so the bottom-pinning is steady, then
        // fall back to `'smooth'` for the final settle on
        // status='ready' / new turn arrival.
        threadRef.current?.scrollTo({
            top: threadRef.current.scrollHeight,
            behavior: isStreaming ? 'auto' : 'smooth',
        });
    }, [messages.length, sdkStatus, totalTextLength, isStreaming]);

    const state = mapStatusToDataState({
        conversationId,
        isLoading: isLoadingHistory,
        isError: error !== null,
        messageCount: messages.length,
        sdkStatus,
    });

    return (
        <section
            ref={threadRef}
            data-testid="chat-thread"
            data-state={state}
            aria-label="Conversation messages"
            aria-live="polite"
            aria-busy={isLoadingHistory || isStreaming}
            className="grid-bg"
            style={{ flex: 1, overflow: 'auto', padding: '24px 32px' }}
        >
            <div style={{ maxWidth: 780, margin: '0 auto' }}>
                {state === 'empty' && <EmptyThread />}
                {state === 'error' && (
                    <div data-testid="chat-thread-error" role="alert" style={errorStyle}>
                        {error?.message ?? 'Could not load messages.'}
                    </div>
                )}
                {messages.map((m) => (
                    <MessageBubble
                        key={getMessageId(m)}
                        conversationId={conversationId as number}
                        message={m}
                        projectKey={projectKey}
                    />
                ))}
            </div>
        </section>
    );
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
