import { useState, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';

export interface MessageActionsProps {
    content: string;
    onRegenerate?: () => void;
    onBranch?: () => void;
}

/**
 * Inline action row for messages: copy, regenerate (assistant only),
 * branch (assistant only).
 *
 * v4.5/W7 Tier 1: regenerate + branch are wired through MessageBubble.
 * User-message editing is handled directly in MessageBubble (the edit
 * button lives on the bubble itself, not in this shared actions row).
 */
export function MessageActions({ content, onRegenerate, onBranch }: MessageActionsProps): ReactNode {
    const [copied, setCopied] = useState(false);

    const onCopy = async () => {
        // Guard: if the Clipboard API is unavailable, `navigator.clipboard?.writeText`
        // resolves to `undefined` (no throw) — never set copied=true in that case.
        if (!navigator.clipboard?.writeText) {
            return;
        }
        try {
            await navigator.clipboard.writeText(content);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div data-testid="chat-message-actions" style={{ display: 'inline-flex', alignItems: 'center', gap: 2 }}>
            <button
                type="button"
                className="btn icon sm ghost"
                data-testid="chat-message-copy"
                data-state={copied ? 'copied' : 'idle'}
                onClick={onCopy}
                aria-label="Copy message"
            >
                {copied ? <Icon.Check size={12} /> : <Icon.Copy size={12} />}
            </button>
            {onRegenerate && (
                <button
                    type="button"
                    className="btn icon sm ghost"
                    data-testid="chat-message-regenerate"
                    onClick={onRegenerate}
                    aria-label="Regenerate answer"
                >
                    <Icon.Play size={12} />
                </button>
            )}
            {onBranch && (
                <button
                    type="button"
                    className="btn icon sm ghost"
                    data-testid="chat-message-branch"
                    onClick={onBranch}
                    aria-label="Branch from this reply"
                >
                    <Icon.Branch size={12} />
                </button>
            )}
        </div>
    );
}
