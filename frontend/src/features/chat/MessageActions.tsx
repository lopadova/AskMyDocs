import { useState, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';

export interface MessageActionsProps {
    content: string;
    onRegenerate?: () => void;
    onBranch?: () => void;
}

/**
 * Inline action row for assistant messages: copy, regenerate, branch.
 * Regenerate + branch are hooks the host wires up; they become
 * full-feature in later phases (PR9 adds branching UI).
 */
export function MessageActions({ content, onRegenerate, onBranch }: MessageActionsProps): ReactNode {
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
