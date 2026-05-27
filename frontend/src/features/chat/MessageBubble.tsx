import { useState, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';
import { Markdown } from '../../lib/markdown';
import { CitationsPopover } from './CitationsPopover';
import { ConfidenceBadge } from './ConfidenceBadge';
import { RefusalNotice } from './RefusalNotice';
import { ThinkingTrace } from './ThinkingTrace';
import { MessageActions } from './MessageActions';
import { FeedbackButtons } from './FeedbackButtons';
import { TokenCostMeter } from './TokenCostMeter';
import { UserMessageEditor } from './UserMessageEditor';
import { RetrievalRunnerUpPanel } from './RetrievalRunnerUpPanel';
import { CounterfactualPanel } from './CounterfactualPanel';
import {
    getCitations,
    getConfidence,
    getCounterfactual,
    getMessageId,
    getReasoningSteps,
    getRefusalBody,
    getRefusalReason,
    getRunnerUp,
    getTextContent,
    getToolCalls,
    isUiMessage,
    type RenderableMessage,
} from './message-shape-adapters';
import { ToolCallBubble } from './tool-call-renderer/ToolCallBubble';

export interface MessageBubbleProps {
    conversationId: number;
    /**
     * Accepts BOTH the legacy AppMessage (TanStack cache + persisted
     * server row) AND the SDK UIMessage (delivered by useChat() over
     * the W3.1 SSE endpoint). All renderer reads go through the
     * adapter functions so the DOM contract stays byte-identical
     * between the two shapes.
     */
    message: RenderableMessage;
    projectKey?: string | null;
    streaming?: boolean;
    /**
     * v4.5/W7 Tier 1 #2 — assistant-only. Wired by the parent
     * (ChatView) for the LAST assistant turn to `chat.regenerate()`.
     */
    onRegenerate?: () => void;
    /**
     * v4.5/W7 Tier 1 #3 — assistant-only. Wired by the parent for any
     * assistant turn. Forks the conversation at this reply.
     */
    onBranch?: () => void;
    /**
     * v4.5/W7 Tier 1 #4 — user-only. Wired by the parent for any user
     * turn. Replaces the bubble with an inline textarea + Save/Cancel.
     * Save calls `onEditSubmit(newText)`; Cancel restores the original.
     */
    onEditSubmit?: (newContent: string) => void | Promise<void>;
    showCounterfactual?: boolean;
    /**
     * Click handler for a citation chip — opens the cited KB document.
     * Wired by ChatView (admin-gated). Forwarded to CitationsPopover.
     */
    onOpenSource?: (citation: import('./chat.api').MessageCitation) => void;
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
 *
 * v4.0/W3.2: citation / refusal / confidence / reasoning reads go
 * through `message-shape-adapters` so the renderer can ALSO accept the
 * SDK `UIMessage` shape after the bigger swap commit lands. Today the
 * `message` prop still types as the legacy `Message`, so the adapter
 * exercises only the AppMessage branch — but the DOM contract is
 * unchanged.
 */
export function MessageBubble({
    conversationId,
    message,
    projectKey,
    streaming = false,
    onRegenerate,
    onBranch,
    onEditSubmit,
    showCounterfactual = true,
    onOpenSource,
}: MessageBubbleProps): ReactNode {
    const isUser = message.role === 'user';
    const thinking = getReasoningSteps(message);
    const messageId = getMessageId(message);
    const textContent = getTextContent(message);
    const [editing, setEditing] = useState(false);

    if (isUser) {
        if (editing && onEditSubmit) {
            return (
                <UserMessageEditor
                    messageId={String(messageId)}
                    initialValue={textContent}
                    onCancel={() => setEditing(false)}
                    onSubmit={async (newText) => {
                        await onEditSubmit(newText);
                        setEditing(false);
                    }}
                />
            );
        }
        return (
            <div
                data-testid={`chat-message-${messageId}`}
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
                        position: 'relative',
                    }}
                >
                    {textContent}
                    {onEditSubmit && !streaming && (
                        <div style={{ position: 'absolute', top: -8, right: 4 }}>
                            <button
                                type="button"
                                className="btn icon sm ghost"
                                data-testid={`chat-message-${messageId}-edit`}
                                onClick={() => setEditing(true)}
                                aria-label="Edit your message"
                                style={{ padding: 4 }}
                            >
                                <Icon.Edit size={11} />
                            </button>
                        </div>
                    )}
                </div>
            </div>
        );
    }

    // `meta` carries the provider-meta tail (model, latency, token
    // count) used in the action-row footer. The legacy AppMessage
    // exposes this on `metadata`; the SDK UIMessage's `metadata` slot
    // has different semantics (generic typed metadata, not the
    // AskMyDocs MessageMetadata shape) so we read it ONLY for the
    // legacy branch. The streaming flow renders without provider meta
    // until the BE persists the message and TanStack invalidates →
    // refetches the legacy shape.
    const meta = isUiMessage(message) ? {} : (message.metadata ?? {});
    const citations = getCitations(message);

    // T3.5 — confidence + refusal_reason live at BOTH the top level
    // and in metadata for AppMessage; getRefusalReason / getConfidence
    // encapsulate the precedence rule (top-level wins) and the
    // SDK-shape branch (read from `data-refusal` / `data-confidence`
    // parts respectively).
    const refusalReason = getRefusalReason(message);
    const confidence = getConfidence(message);
    const isRefusal = refusalReason != null;
    const toolCalls = getToolCalls(message);
    const runnerUp = getRunnerUp(message);
    const counterfactual = getCounterfactual(message);

    return (
        <div
            data-testid={`chat-message-${messageId}`}
            data-role="assistant"
            data-refusal-reason={refusalReason ?? ''}
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
                {toolCalls.length > 0 && (
                    <div data-testid={`chat-message-${messageId}-tool-calls`}>
                        {toolCalls.map((toolCall) => (
                            <ToolCallBubble key={toolCall.id} toolCall={toolCall} />
                        ))}
                    </div>
                )}
                {isRefusal ? (
                    // For UIMessage refusals, the body is in the
                    // `data-refusal` payload (NO text-delta on the
                    // refusal path per W3.1 BE design). For AppMessage
                    // refusals, both `getRefusalBody` and `getTextContent`
                    // resolve to the same `m.content`. Either way,
                    // prefer the dedicated helper.
                    <RefusalNotice body={getRefusalBody(message) ?? textContent} reason={refusalReason ?? 'unknown'} />
                ) : (
                    <div
                        data-testid={`chat-message-${messageId}-body`}
                        style={{ fontSize: 13.5, color: 'var(--fg-1)' }}
                    >
                        <Markdown source={textContent} project={projectKey ?? undefined} />
                        {streaming && <span className="caret" />}
                    </div>
                )}
                {/*
                  * Citations are skipped on the refusal path — the BE
                  * always sends an empty citations array there, but
                  * guarding here protects against a future regression
                  * that surfaces stale citations under a refusal body.
                  */}
                {!streaming && !isRefusal && citations.length > 0 && (
                    <CitationsPopover citations={citations} onOpenSource={onOpenSource} />
                )}
                {!streaming && (
                    <RetrievalRunnerUpPanel rows={runnerUp} />
                )}
                {!streaming && (
                    <CounterfactualPanel rows={counterfactual} enabled={showCounterfactual} />
                )}
                {!streaming && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: 2, marginTop: 10 }}>
                        <MessageActions
                            content={textContent}
                            onRegenerate={onRegenerate}
                            onBranch={onBranch}
                        />
                        {/*
                          * FeedbackButtons posts to
                          * /conversations/{conv}/messages/{id}/feedback (see
                          * `chatApi.rateMessage()`) which requires a numeric
                          * persisted id. SDK UIMessage carries a string id
                          * during the window between stream-finish and the
                          * TanStack invalidation that swaps the cached
                          * UIMessage for the persisted AppMessage. Hide the
                          * buttons in that transient state — they reappear
                          * once the refetch lands the canonical row.
                          */}
                        {typeof messageId === 'number' && !isUiMessage(message) && (
                            <FeedbackButtons
                                conversationId={conversationId}
                                messageId={messageId}
                                initialRating={message.rating}
                            />
                        )}
                        <span style={{ flex: 1 }} />
                        {/*
                          * T3.6 — confidence badge to the right of
                          * the action row. Renders nothing on legacy
                          * rows that have no signal; renders 'refused'
                          * tier (grey) when refusal_reason is set;
                          * otherwise renders the high/moderate/low
                          * tier per the score band.
                          */}
                        <ConfidenceBadge
                            confidence={confidence}
                            refusalReason={refusalReason}
                        />
                        {meta.model && (
                            <span
                                data-testid={`chat-message-${messageId}-meta`}
                                className="mono"
                                style={{
                                    fontSize: 10.5,
                                    color: 'var(--fg-3)',
                                    marginLeft: 8,
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: 6,
                                }}
                            >
                                <span data-testid={`chat-message-${messageId}-provider-model`}>
                                    {meta.provider ? `${meta.provider} · ` : ''}
                                    {meta.model}
                                </span>
                                {!isUiMessage(message) && message.created_at && (
                                    <span data-testid={`chat-message-${messageId}-timestamp`}>
                                        · {formatTimestamp(message.created_at)}
                                    </span>
                                )}
                                {meta.latency_ms !== undefined && (
                                    <span>· {(meta.latency_ms / 1000).toFixed(1)}s</span>
                                )}
                            </span>
                        )}
                        {/*
                          * v4.5/W7 Tier 1 #5 — per-turn token + USD cost
                          * meter. Reads cost rates via TanStack Query;
                          * renders nothing on user turns / legacy rows
                          * with no token telemetry. The TokenCostMeter
                          * also fetches the cost-rate table on first
                          * mount so subsequent bubbles share the cache.
                          */}
                        <TokenCostMeter
                            provider={meta.provider}
                            model={meta.model}
                            promptTokens={meta.prompt_tokens}
                            completionTokens={meta.completion_tokens}
                            totalTokens={meta.total_tokens}
                        />
                        {/* timestamp moved into provider/model meta block above */}

                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * v4.5/W7 — render a message timestamp in the format `HH:MM` for the
 * same day, `MMM D HH:MM` for older messages. ISO 8601 string in,
 * locale-aware string out. Defensive against malformed input — a bad
 * timestamp renders as `—` instead of throwing.
 */
export function formatTimestamp(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) {
        return '—';
    }
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    const hh = d.getHours().toString().padStart(2, '0');
    const mm = d.getMinutes().toString().padStart(2, '0');
    if (sameDay) {
        return `${hh}:${mm}`;
    }
    return `${d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} ${hh}:${mm}`;
}
