import { useEffect, useState, type ReactNode } from 'react';
import { chatApi } from './chat.api';
import { Icon } from '../../components/Icons';
import { Button } from '../../components/Button';

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
        // Also reset loading so a fetch that was in-flight when
        // isStreaming flipped doesn't leave the bar stuck in a blank
        // loading state.
        if (conversationId === null || turnId === 0 || isStreaming) {
            setSuggestions([]);
            setLoading(false);
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
                className="chat-suggested-followups-shell"
            >
                <div className="chat-suggested-followups" />
            </div>
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
            className="chat-suggested-followups-shell"
        >
            <div className="chat-suggested-followups">
                {suggestions.map((prompt, i) => (
                    <Button
                        key={`${turnId}-${i}`}
                        variant="secondary"
                        size="sm"
                        leadingIcon={<Icon.Sparkles />}
                        data-testid={`chat-suggested-followup-${i}`}
                        className="chat-suggested-followup"
                        onClick={() => onPick(prompt)}
                        aria-label={`Suggested follow-up: ${prompt}`}
                        title={prompt}
                    >
                        {prompt}
                    </Button>
                ))}
            </div>
        </div>
    );
}
