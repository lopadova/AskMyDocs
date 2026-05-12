import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';

export interface UserMessageEditorProps {
    messageId: string;
    initialValue: string;
    onSubmit: (newContent: string) => void | Promise<void>;
    onCancel: () => void;
}

/**
 * v4.5/W7 Tier 1 #4 — inline editor that replaces a user message bubble
 * with a textarea + Save / Cancel buttons. On Save the parent receives
 * the new content; the parent typically calls `chat.setMessages()` to
 * truncate the thread below the edited message, then `chat.regenerate()`
 * or `chat.sendMessage()` to re-run the turn.
 *
 * R11: stable testids — `chat-message-{id}-editor`, `-textarea`,
 * `-save`, `-cancel`.
 *
 * R15: textarea has an `aria-label`; the form is keyboard-reachable;
 * Cmd/Ctrl+Enter saves, Escape cancels (mirrors the composer's
 * shortcut semantics).
 */
export function UserMessageEditor({ messageId, initialValue, onSubmit, onCancel }: UserMessageEditorProps): ReactNode {
    const [value, setValue] = useState(initialValue);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);

    useEffect(() => {
        // Auto-focus + place cursor at the end for fast continuation
        // editing — the user clicked the pencil to make a small change.
        const el = textareaRef.current;
        if (el) {
            el.focus();
            el.setSelectionRange(el.value.length, el.value.length);
        }
    }, []);

    const handleSave = async () => {
        const next = value.trim();
        if (!next) {
            setError('Message cannot be empty.');
            return;
        }
        if (next === initialValue.trim()) {
            // No-op edit: just cancel.
            onCancel();
            return;
        }
        setSaving(true);
        setError(null);
        try {
            await onSubmit(next);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Failed to save edit.');
        } finally {
            setSaving(false);
        }
    };

    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            onCancel();
            return;
        }
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            void handleSave();
        }
    };

    return (
        <div
            data-testid={`chat-message-${messageId}-editor`}
            style={{
                display: 'flex',
                justifyContent: 'flex-end',
                marginBottom: 18,
            }}
        >
            <div
                style={{
                    maxWidth: '70%',
                    padding: '10px 14px',
                    background: 'var(--bg-3)',
                    border: '1px solid var(--panel-border-strong)',
                    borderRadius: '14px 14px 4px 14px',
                    fontSize: 13.5,
                    color: 'var(--fg-0)',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 8,
                    minWidth: 280,
                }}
            >
                <textarea
                    ref={textareaRef}
                    data-testid={`chat-message-${messageId}-editor-textarea`}
                    aria-label="Edit your message"
                    value={value}
                    onChange={(e) => {
                        setValue(e.target.value);
                        if (error) {
                            setError(null);
                        }
                    }}
                    onKeyDown={onKeyDown}
                    rows={Math.min(8, Math.max(2, value.split('\n').length))}
                    disabled={saving}
                    style={{
                        width: '100%',
                        padding: 0,
                        background: 'transparent',
                        border: 0,
                        outline: 'none',
                        color: 'var(--fg-0)',
                        fontSize: 13.5,
                        fontFamily: 'var(--font-sans)',
                        resize: 'vertical',
                        lineHeight: 1.55,
                    }}
                />
                {error && (
                    <div
                        data-testid={`chat-message-${messageId}-editor-error`}
                        role="alert"
                        style={{ fontSize: 11, color: 'var(--err)' }}
                    >
                        {error}
                    </div>
                )}
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 6 }}>
                    <button
                        type="button"
                        className="btn sm ghost"
                        data-testid={`chat-message-${messageId}-editor-cancel`}
                        onClick={onCancel}
                        disabled={saving}
                        aria-label="Cancel edit"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="btn sm primary"
                        data-testid={`chat-message-${messageId}-editor-save`}
                        onClick={handleSave}
                        disabled={saving || value.trim() === ''}
                        aria-label="Save edit and regenerate response"
                    >
                        {saving ? 'Saving…' : 'Save & resend'}
                    </button>
                </div>
            </div>
        </div>
    );
}
