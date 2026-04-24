import { type ReactNode } from 'react';
import { Icon } from '../../components/Icons';
import { Markdown } from '../../lib/markdown';
import { CitationsPopover } from './CitationsPopover';
import { ThinkingTrace } from './ThinkingTrace';
import { MessageActions } from './MessageActions';
import { FeedbackButtons } from './FeedbackButtons';
import type { Message } from './chat.api';

export interface MessageBubbleProps {
    conversationId: number;
    message: Message;
    projectKey?: string | null;
    streaming?: boolean;
}

/**
 * Single turn in the thread. User turns render as a right-aligned
 * speech bubble; assistant turns render full-width with (optional)
 * ThinkingTrace, Markdown body, Citations strip, and the action row
 * (copy + rate + graph + provider meta).
 *
 * R11: `data-testid="chat-message-<id>"`, `data-role` on every entry.
 *
 * Copilot #7 fix: the thinking-trace source is `metadata.reasoning_steps`
 * (populated when the AI provider returns a reasoning trace). When the
 * field is absent the component is intentionally skipped — no more
 * `undefined ? undefined : undefined` dead code that made the trace
 * unreachable even for providers that supply it.
 */
export function MessageBubble({ conversationId, message, projectKey, streaming = false }: MessageBubbleProps): ReactNode {
    const isUser = message.role === 'user';
    const rawSteps = message.metadata?.reasoning_steps;
    const thinking = Array.isArray(rawSteps) && rawSteps.every((s) => typeof s === 'string')
        ? (rawSteps as string[])
        : undefined;

    if (isUser) {
        return (
            <div
                data-testid={`chat-message-${message.id}`}
                data-role="user"
                className="popin"
                style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 18 }}
            >
                <div
                    style={{
                        maxWidth: '70%',
                        padding: '10px 14px',
                        background: 'var(--bg-3)',
                        border: '1px solid var(--panel-border)',
                        borderRadius: '14px 14px 4px 14px',
                        fontSize: 13.5,
                        lineHeight: 1.55,
                        color: 'var(--fg-0)',
                        whiteSpace: 'pre-wrap',
                    }}
                >
                    {message.content}
                </div>
            </div>
        );
    }

    const meta = message.metadata ?? {};
    const citations = meta.citations ?? [];

    return (
        <div
            data-testid={`chat-message-${message.id}`}
            data-role="assistant"
            className="popin"
            style={{ display: 'flex', gap: 12, marginBottom: 22 }}
        >
            <div
                style={{
                    width: 30,
                    height: 30,
                    borderRadius: 9,
                    background: 'var(--grad-accent)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    flex: '0 0 auto',
                }}
            >
                <Icon.Logo size={16} />
            </div>
            <div style={{ flex: 1, minWidth: 0 }}>
                {thinking && <ThinkingTrace steps={thinking} />}
                <div data-testid={`chat-message-${message.id}-body`} style={{ fontSize: 13.5, color: 'var(--fg-1)' }}>
                    <Markdown source={message.content} project={projectKey ?? undefined} />
                    {streaming && <span className="caret" />}
                </div>
                {!streaming && citations.length > 0 && (
                    <CitationsPopover citations={citations} />
                )}
                {!streaming && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 2, marginTop: 10 }}>
                        <MessageActions content={message.content} />
                        <FeedbackButtons
                            conversationId={conversationId}
                            messageId={message.id}
                            initialRating={message.rating}
                        />
                        <span style={{ flex: 1 }} />
                        {meta.model && (
                            <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                                {meta.provider ? `${meta.provider}/` : ''}
                                {meta.model}
                                {meta.latency_ms !== undefined ? ` · ${(meta.latency_ms / 1000).toFixed(1)}s` : ''}
                                {meta.total_tokens !== undefined ? ` · ${meta.total_tokens} tok` : ''}
                            </span>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
