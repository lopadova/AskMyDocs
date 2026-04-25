import { useState, type ChangeEvent, type KeyboardEvent, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';
import { useChatMutation } from './use-chat-mutation';
import { useChatStore } from './chat.store';
import { VoiceInput } from './VoiceInput';

export interface ComposerProps {
    conversationId: number | null;
    projectLabel?: string;
    modelLabel?: string;
    onRequireConversation?: () => Promise<number | null>;
}

/**
 * Message composer. Client-side required-field check (with a
 * `data-testid="message-error"` surface for 422-style "required"
 * violations); server errors surface via the mutation state.
 *
 * R11: every button carries a testid; the textarea is
 * `chat-composer-input`, the form is `chat-composer`, the submit is
 * `chat-composer-send`, and the inline error is `chat-composer-error`.
 */
export function Composer({ conversationId, projectLabel, modelLabel, onRequireConversation }: ComposerProps): ReactNode {
    const draft = useChatStore((s) => s.draft);
    const setDraft = useChatStore((s) => s.setDraft);
    const appendToDraft = useChatStore((s) => s.appendToDraft);
    const clearDraft = useChatStore((s) => s.clearDraft);
    const [focused, setFocused] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const mutation = useChatMutation();

    const send = async () => {
        const trimmed = draft.trim();
        if (!trimmed) {
            setLocalError('Message is required.');
            return;
        }
        let targetId = conversationId;
        if (targetId === null && onRequireConversation) {
            targetId = await onRequireConversation();
        }
        if (targetId === null) {
            setLocalError('Could not start a new conversation.');
            return;
        }
        setLocalError(null);
        const content = trimmed;
        clearDraft();
        mutation.mutate({ conversationId: targetId, content });
    };

    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            void send();
        }
    };

    const onChange = (e: ChangeEvent<HTMLTextAreaElement>) => {
        setDraft(e.target.value);
        if (localError) {
            setLocalError(null);
        }
    };

    const serverError = mutation.isError ? (mutation.error?.message ?? 'Provider returned an error.') : null;

    return (
        <div style={{ padding: '12px 24px 18px' }}>
            <form
                data-testid="chat-composer"
                aria-label="Message composer"
                onSubmit={(e) => {
                    e.preventDefault();
                    void send();
                }}
                className={`glow-frame ${focused ? 'on' : ''}`}
                style={{
                    background: 'var(--panel-solid)',
                    border: '1px solid var(--panel-border-strong)',
                    borderRadius: 14,
                    boxShadow: focused ? 'var(--glow)' : 'var(--shadow)',
                    transition: 'box-shadow .25s',
                }}
            >
                <div style={{ display: 'flex', gap: 6, padding: '10px 12px 2px', flexWrap: 'wrap' }}>
                    {projectLabel && <ContextChip icon="Folder" label={projectLabel} />}
                    <ContextChip icon="Book" label="canonical only" />
                    {modelLabel && <ContextChip icon="Brain" label={modelLabel} />}
                    <span style={{ flex: 1 }} />
                    <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)', padding: 4 }}>
                        Shift+⏎ for new line
                    </span>
                </div>
                <textarea
                    name="message"
                    data-testid="chat-composer-input"
                    aria-label="Your message"
                    aria-invalid={Boolean(localError)}
                    value={draft}
                    onChange={onChange}
                    onFocus={() => setFocused(true)}
                    onBlur={() => setFocused(false)}
                    onKeyDown={onKeyDown}
                    placeholder="Ask anything grounded in your knowledge base…"
                    rows={2}
                    style={{
                        width: '100%',
                        padding: '6px 14px 10px',
                        background: 'transparent',
                        border: 0,
                        outline: 'none',
                        color: 'var(--fg-0)',
                        fontSize: 14,
                        fontFamily: 'var(--font-sans)',
                        resize: 'none',
                        lineHeight: 1.5,
                    }}
                />
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 12px 10px' }}>
                    <button
                        type="button"
                        className="btn icon sm ghost"
                        data-testid="chat-composer-attach"
                        aria-label="Attach file"
                    >
                        <Icon.Plus size={13} />
                    </button>
                    <VoiceInput onTranscript={(t) => appendToDraft((draft ? ' ' : '') + t)} />
                    <span style={{ flex: 1 }} />
                    <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                        {draft.length > 0 ? `${draft.length} chars` : ''}
                    </span>
                    <button
                        type="submit"
                        className="btn primary sm"
                        data-testid="chat-composer-send"
                        disabled={mutation.isPending}
                        aria-busy={mutation.isPending}
                        style={{ opacity: mutation.isPending ? 0.5 : 1 }}
                    >
                        <Icon.Send size={12} />
                        Send
                        <span
                            className="kbd"
                            style={{
                                background: 'rgba(10,10,20,.2)',
                                color: '#0a0a14',
                                borderColor: 'rgba(10,10,20,.15)',
                            }}
                        >
                            ⏎
                        </span>
                    </button>
                </div>
            </form>
            {localError && (
                <div
                    data-testid="message-error"
                    role="alert"
                    style={{ marginTop: 8, fontSize: 12, color: 'var(--err)' }}
                >
                    {localError}
                </div>
            )}
            {serverError && (
                <div
                    data-testid="chat-composer-error"
                    role="alert"
                    style={{ marginTop: 8, fontSize: 12, color: 'var(--err)' }}
                >
                    {serverError}
                </div>
            )}
        </div>
    );
}

function ContextChip({ icon, label }: { icon: 'Folder' | 'Book' | 'Brain'; label: string }): ReactNode {
    const Ico = Icon[icon];
    return (
        <span
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 5,
                padding: '3px 8px',
                background: 'var(--bg-3)',
                border: '1px solid var(--panel-border)',
                borderRadius: 99,
                fontSize: 11,
                color: 'var(--fg-1)',
            }}
        >
            <Ico size={11} style={{ color: 'var(--fg-2)' }} />
            <span>{label}</span>
        </span>
    );
}
