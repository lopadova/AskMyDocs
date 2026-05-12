import { useEffect, useState, type ReactNode } from 'react';
import { chatApi } from './chat.api';
import { Icon } from '../../components/Icons';

export interface SuggestedFollowupsProps {
    conversationId: number | null;
    /**
     * Increments by 1 every time the most recent assistant turn
     * settles (i.e. `onFinish` fires). The component refetches
     * suggestions ONLY when `turnId` changes — never on every render.
     */
    turnId: number;
    /**
     * True while the SDK is mid-turn. Suppresses the pill bar so it
     * doesn't render stale suggestions while a fresh answer is
     * streaming.
     */
    isStreaming: boolean;
    /**
     * Click handler. The parent dispatches `sendMessage({ text: prompt })`.
     */
    onPick: (prompt: string) => void;
}

/**
 * v4.5/W7 Tier 2 #10 — three-pill suggested follow-up bar that
 * renders above the composer after each assistant turn. Best-effort:
 * the BE returns `{suggestions: []}` on any provider failure, so the
 * row simply doesn't render.
 *
 * Design: stateless component, owns only the fetched list + loading
 * flag. The parent owns the actual sendMessage dispatch — keeps the
 * pill bar reusable in a future "starter prompts on empty thread"
 * surface.
 *
 * R11: every pill carries a stable `data-testid` keyed by index so
 * Playwright can click any of the three independently.
 *
 * R15: every pill is a `<button>` with full accessible name; the
 * container is `aria-label` on the wrapper so screen-readers announce
 * "Suggested follow-up: <prompt>".
 */
export function SuggestedFollowups({
    conversationId,
    turnId,
    isStreaming,
    onPick,
}: SuggestedFollowupsProps): ReactNode {
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        // Don't fetch while the SDK is mid-turn — the BE has nothing
        // useful to offer yet and the row would shift on the user.
        if (conversationId === null || turnId === 0 || isStreaming) {
            setSuggestions([]);
            return;
        }
        let cancelled = false;
        setLoading(true);
        chatApi.suggestedFollowups(conversationId)
            .then((s) => {
                if (cancelled) {
                    return;
                }
                setSuggestions(s.slice(0, 3));
            })
            .catch(() => {
                if (cancelled) {
                    return;
                }
                setSuggestions([]);
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [conversationId, turnId, isStreaming]);

    if (loading) {
        return (
            <div
                data-testid="chat-suggested-followups"
                data-state="loading"
                aria-label="Loading suggested follow-ups"
                style={{
                    display: 'flex',
                    gap: 8,
                    padding: '0 24px 8px',
                    flexWrap: 'wrap',
                    minHeight: 24,
                }}
            />
        );
    }

    if (suggestions.length === 0) {
        return null;
    }

    return (
        <div
            data-testid="chat-suggested-followups"
            data-state="ready"
            aria-label="Suggested follow-up questions"
            style={{
                display: 'flex',
                gap: 8,
                padding: '0 24px 10px',
                flexWrap: 'wrap',
            }}
        >
            {suggestions.map((prompt, i) => (
                <button
                    key={`${turnId}-${i}`}
                    type="button"
                    data-testid={`chat-suggested-followup-${i}`}
                    className="btn sm"
                    onClick={() => onPick(prompt)}
                    style={{
                        fontSize: 12,
                        color: 'var(--fg-1)',
                        background: 'var(--bg-2)',
                        border: '1px solid var(--panel-border)',
                        borderRadius: 99,
                        padding: '4px 10px',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 5,
                        cursor: 'pointer',
                    }}
                    aria-label={`Suggested follow-up: ${prompt}`}
                >
                    <Icon.Sparkles size={11} style={{ color: 'var(--fg-3)' }} />
                    <span>{prompt}</span>
                </button>
            ))}
        </div>
    );
}
