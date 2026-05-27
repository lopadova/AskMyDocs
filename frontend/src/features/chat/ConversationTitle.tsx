import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '../../components/Icons';
import { chatApi, type Conversation } from './chat.api';

export interface ConversationTitleProps {
    conversationId: number;
    title: string;
}

/**
 * Chat header title with an inline rename affordance (ChatGPT-style):
 * the title text + a pencil button; clicking the pencil swaps to an input
 * with Save / Cancel. Save PATCHes `/conversations/{id}` via
 * `chatApi.renameConversation` and updates the `['conversations']` cache so
 * the sidebar row reflects the new name immediately.
 *
 * R11: stable testids — `chat-title`, `chat-title-rename`, `chat-title-input`,
 * `chat-title-save`, `chat-title-cancel`, `chat-title-error`.
 */
export function ConversationTitle({ conversationId, title }: ConversationTitleProps): ReactNode {
    const qc = useQueryClient();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(title);
    const inputRef = useRef<HTMLInputElement>(null);

    // Keep the draft aligned with the latest server title when NOT editing
    // (e.g. auto-generated title lands after the first turn). While editing,
    // never clobber what the user is typing.
    useEffect(() => {
        if (!editing) {
            setDraft(title);
        }
    }, [title, editing]);

    useEffect(() => {
        if (editing) {
            inputRef.current?.focus();
            inputRef.current?.select();
        }
    }, [editing]);

    const mutation = useMutation<Conversation, Error, string>({
        mutationFn: (next) => chatApi.renameConversation(conversationId, next),
        onSuccess: (updated) => {
            qc.setQueryData<Conversation[]>(['conversations'], (old) =>
                old?.map((c) => (c.id === conversationId ? { ...c, title: updated.title } : c)) ?? old,
            );
            setEditing(false);
        },
    });

    const save = () => {
        const next = draft.trim();
        if (next === '' || next === title) {
            setEditing(false);
            return;
        }
        mutation.mutate(next);
    };

    if (editing) {
        return (
            <form
                data-testid="chat-title-edit"
                onSubmit={(e) => {
                    e.preventDefault();
                    save();
                }}
                style={{ display: 'flex', gap: 6, alignItems: 'center', minWidth: 0 }}
            >
                <input
                    ref={inputRef}
                    type="text"
                    data-testid="chat-title-input"
                    aria-label="Conversation title"
                    className="input"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Escape') {
                            setEditing(false);
                        }
                    }}
                    disabled={mutation.isPending}
                    style={{ height: 26, fontSize: 13.5, minWidth: 220, maxWidth: 420 }}
                />
                <button
                    type="submit"
                    className="btn icon sm ghost"
                    data-testid="chat-title-save"
                    aria-label="Save title"
                    disabled={mutation.isPending}
                >
                    <Icon.Check size={12} />
                </button>
                <button
                    type="button"
                    className="btn icon sm ghost"
                    data-testid="chat-title-cancel"
                    aria-label="Cancel rename"
                    onClick={() => setEditing(false)}
                    disabled={mutation.isPending}
                >
                    <Icon.Close size={12} />
                </button>
                {mutation.isError && (
                    <span
                        role="alert"
                        data-testid="chat-title-error"
                        style={{ fontSize: 11, color: 'var(--err)' }}
                    >
                        Rename failed.
                    </span>
                )}
            </form>
        );
    }

    return (
        <div style={{ display: 'flex', gap: 6, alignItems: 'center', minWidth: 0 }}>
            <span
                data-testid="chat-title"
                style={{
                    fontSize: 13.5,
                    fontWeight: 500,
                    color: 'var(--fg-0)',
                    whiteSpace: 'nowrap',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    maxWidth: 360,
                }}
            >
                {title}
            </span>
            <button
                type="button"
                className="btn icon sm ghost"
                data-testid="chat-title-rename"
                aria-label="Rename conversation"
                onClick={() => setEditing(true)}
                style={{ padding: 3, flex: '0 0 auto' }}
            >
                <Icon.Edit size={12} />
            </button>
        </div>
    );
}
