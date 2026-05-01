import { useRef, useState, type ChangeEvent, type KeyboardEvent, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';
import { FilterBar } from './FilterBar';
import { MentionPopover } from './MentionPopover';
import { useChatStore } from './chat.store';
import { VoiceInput } from './VoiceInput';
import type { MentionResult } from './use-mention-search';
import type { FilterState } from './chat.api';

export interface ComposerProps {
    conversationId: number | null;
    projectLabel?: string;
    modelLabel?: string;
    onRequireConversation?: () => Promise<number | null>;
    /**
     * T2.7 — optional list of project keys to populate the filter
     * picker's Project tab. Caller fetches from
     * `/api/admin/projects/keys` (admin-only) and passes through.
     * Empty list is a valid state — the popover renders an inline
     * "No projects available" hint.
     */
    availableProjects?: string[];
    /** Tags available for the current project scope (slug + display label + optional color). */
    availableTags?: { slug: string; label: string; color?: string }[];
    /** Doc-id → title map for chip labels (mention pinning, T2.8 follow-up). */
    docLabels?: Record<number, string>;
    /**
     * v4.0/W3.2 — controlled filters. Lifted from Composer-local
     * state to ChatView so the streaming hook can read the live
     * value when it builds each turn's request body.
     */
    filters: FilterState;
    onFiltersChange: (next: FilterState | ((prev: FilterState) => FilterState)) => void;
    /**
     * Send handler. ChatView wraps `useChatStream().sendMessage()`
     * (with the conversation-creation flow if `conversationId` is
     * null) and passes it through. Returns once the request is in
     * flight; the streaming response surfaces via MessageThread's
     * messages prop.
     */
    onSend: (content: string) => void | Promise<void>;
    /**
     * Stop the current stream mid-flight. Renders the
     * `chat-composer-stop` button when `isStreaming` is true (the
     * Send button morphs into Stop). Coming from
     * `useChatStream().stop()`.
     */
    onStop?: () => void;
    /**
     * True while the SDK is mid-turn (`status === 'submitted' |
     * 'streaming'`). Disables the textarea + flips Send → Stop.
     */
    isStreaming: boolean;
    /**
     * Surface from `useChatStream().error`. Renders the
     * chat-composer-error inline when set.
     */
    error?: Error | null;
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
export function Composer({
    conversationId,
    projectLabel,
    modelLabel,
    onRequireConversation,
    availableProjects = [],
    availableTags = [],
    docLabels = {},
    filters,
    onFiltersChange,
    onSend,
    onStop,
    isStreaming,
    error,
}: ComposerProps): ReactNode {
    const draft = useChatStore((s) => s.draft);
    const setDraft = useChatStore((s) => s.setDraft);
    const appendToDraft = useChatStore((s) => s.appendToDraft);
    const clearDraft = useChatStore((s) => s.clearDraft);
    const [focused, setFocused] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    // T2.8 — @mention popover state. `mentionQuery` is the chars after
    // the most recent unmatched `@` (truncated to next whitespace). It
    // drives use-mention-search via MentionPopover. `mentionAnchor` is
    // the cursor index of the `@` character — used to splice the
    // textarea content when a doc is selected.
    const [mentionQuery, setMentionQuery] = useState<string | null>(null);
    const mentionAnchorRef = useRef<number | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);
    // Map of doc_id → title for chips. Updates when the user picks a
    // mention, so the FilterBar can show "Doc: HR Policy v2" instead of
    // "#42". Survives across turns inside the same composer mount.
    const [docLabelMap, setDocLabelMap] = useState<Record<number, string>>(docLabels);

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
        await onSend(content);
    };

    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            void send();
        }
    };

    const onChange = (e: ChangeEvent<HTMLTextAreaElement>) => {
        const value = e.target.value;
        setDraft(value);
        if (localError) {
            setLocalError(null);
        }
        // T2.8 — detect the most recent `@` BEFORE the cursor, take chars
        // up to next whitespace as the mention query. If the cursor isn't
        // currently inside an `@token`, close the popover.
        const cursor = e.target.selectionStart ?? value.length;
        const prefix = value.slice(0, cursor);
        const atIndex = prefix.lastIndexOf('@');
        if (atIndex === -1 || /\s/.test(prefix.slice(atIndex + 1))) {
            // Cursor isn't inside an @-token (no @ before, OR there's
            // whitespace between the @ and the cursor → user moved on).
            setMentionQuery(null);
            mentionAnchorRef.current = null;
            return;
        }
        const tokenAfterAt = prefix.slice(atIndex + 1);
        // The `@` must be at start-of-string OR follow whitespace —
        // otherwise it's an email address fragment, not a mention.
        const charBeforeAt = atIndex === 0 ? ' ' : value.charAt(atIndex - 1);
        if (!/\s/.test(charBeforeAt)) {
            setMentionQuery(null);
            mentionAnchorRef.current = null;
            return;
        }
        mentionAnchorRef.current = atIndex;
        setMentionQuery(tokenAfterAt);
    };

    /**
     * T2.8 — User picked a doc from the MentionPopover. Add its id to
     * `filters.doc_ids`, update the doc-label map for FilterBar chip
     * display, and replace the `@<query>` text in the textarea with
     * an empty string (the chip in the FilterBar IS the indicator).
     */
    const onMentionSelect = (doc: MentionResult) => {
        const anchor = mentionAnchorRef.current;
        if (anchor !== null) {
            // Splice out the @<query> token. Find where the token ends
            // (first whitespace after the anchor, or end of string).
            const after = draft.slice(anchor);
            const endRel = after.search(/\s/);
            const end = endRel === -1 ? draft.length : anchor + endRel;
            const next = draft.slice(0, anchor) + draft.slice(end);
            setDraft(next);
        }

        onFiltersChange((prev) => {
            const existing = prev.doc_ids ?? [];
            if (existing.includes(doc.id)) {
                return prev;
            }
            return { ...prev, doc_ids: [...existing, doc.id] };
        });
        setDocLabelMap((prev) => ({ ...prev, [doc.id]: doc.title }));
        setMentionQuery(null);
        mentionAnchorRef.current = null;

        // Restore textarea focus (mousedown's preventDefault stopped
        // blur, but we still want the cursor visible at the splice
        // point so the user can continue typing).
        textareaRef.current?.focus();
    };

    const serverError = error ? (error.message ?? 'Provider returned an error.') : null;

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
                {/*
                  * T2.7 — FilterBar renders ABOVE the legacy context-chip
                  * row. Together they form the "what's constraining this
                  * answer" surface. The legacy chips (project label,
                  * canonical-only, model) stay visible because they show
                  * conversation-level config the user can't directly
                  * change here; the FilterBar owns the per-turn filters.
                  */}
                <FilterBar
                    filters={filters}
                    onChange={onFiltersChange}
                    availableProjects={availableProjects}
                    availableTags={availableTags}
                    docLabels={docLabelMap}
                />
                <div style={{ display: 'flex', gap: 6, padding: '10px 12px 2px', flexWrap: 'wrap' }}>
                    {projectLabel && <ContextChip icon="Folder" label={projectLabel} />}
                    <ContextChip icon="Book" label="canonical only" />
                    {modelLabel && <ContextChip icon="Brain" label={modelLabel} />}
                    <span style={{ flex: 1 }} />
                    <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)', padding: 4 }}>
                        Shift+⏎ for new line
                    </span>
                </div>
                {/*
                  * T2.8 — wrapper provides positioning context for the
                  * MentionPopover, which uses position:absolute / bottom:100%
                  * to render ABOVE the textarea. The popover is conditional
                  * on `mentionQuery !== null`, so when no @-token is
                  * active under the cursor the popover doesn't even mount.
                  */}
                <div style={{ position: 'relative' }}>
                <textarea
                    name="message"
                    data-testid="chat-composer-input"
                    aria-label="Your message"
                    aria-invalid={Boolean(localError)}
                    aria-autocomplete={mentionQuery !== null ? 'list' : undefined}
                    aria-expanded={mentionQuery !== null}
                    aria-controls={mentionQuery !== null ? 'mention-popover' : undefined}
                    ref={textareaRef}
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
                {mentionQuery !== null && (
                    <MentionPopover
                        query={mentionQuery}
                        projectKeys={projectLabel ? [projectLabel] : undefined}
                        excludeIds={filters.doc_ids ?? []}
                        open={mentionQuery !== null}
                        onSelect={onMentionSelect}
                        onClose={() => {
                            setMentionQuery(null);
                            mentionAnchorRef.current = null;
                        }}
                    />
                )}
                </div>
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
                    {/*
                      * v4.0/W3.2 — Send / Stop morph: while a stream
                      * is in flight (`isStreaming`), render
                      * `chat-composer-stop` instead of
                      * `chat-composer-send` so the user can abort the
                      * stream without remembering a keyboard shortcut.
                      * The two buttons share the same primary slot so
                      * the layout stays stable; the testid changes so
                      * Playwright scenarios can target whichever is
                      * relevant. The send button stays as a `submit`
                      * button so Enter still works pre-stream;
                      * `chat-composer-stop` is `type="button"` so it
                      * doesn't accidentally fire form submit.
                      */}
                    {isStreaming ? (
                        <button
                            type="button"
                            className="btn primary sm"
                            data-testid="chat-composer-stop"
                            onClick={() => onStop?.()}
                            aria-label="Stop streaming"
                        >
                            <Icon.Close size={12} />
                            Stop
                        </button>
                    ) : (
                        <button
                            type="submit"
                            className="btn primary sm"
                            data-testid="chat-composer-send"
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
                    )}
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
