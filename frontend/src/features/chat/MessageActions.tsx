import { useState, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';

export interface MessageActionsProps {
    content: string;
    onRegenerate?: () => void;
    onBranch?: () => void;
    /**
     * v4.5/W7 Tier 1 #4 — inline edit handler for user messages. The
     * caller wires the pencil button to lifting the message into an
     * inline textarea (handled at the MessageBubble level so the
     * actions row stays presentational).
     */
    onEdit?: () => void;
}

/**
 * Inline action row for messages: copy, edit (user only), regenerate,
 * branch (assistant only).
 *
 * v4.5/W7 Tier 1: regenerate + branch + edit are now first-class
 * features wired through MessageBubble (see ChatView for the actual
 * SDK hook integration).
 */
export function MessageActions({ content, onRegenerate, onBranch, onEdit }: MessageActionsProps): ReactNode {
    const [copied, setCopied] = useState(false);

    const onCopy = async () => {
        try {
            await navigator.clipboard?.writeText(content);
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
            {onEdit && (
                <button
                    type="button"
                    className="btn icon sm ghost"
                    data-testid="chat-message-edit"
                    onClick={onEdit}
                    aria-label="Edit message"
                >
                    <Icon.Edit size={12} />
                </button>
            )}
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
