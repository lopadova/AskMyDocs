import { type ReactNode } from 'react';
import { Icon } from '../../../components/Icons';
import type { ChatFolder } from '../chat-folders.api';
import type { Conversation, ConversationImportance } from '../chat.api';
import { SessionRowMenu } from './SessionRowMenu';

const IMPORTANCE_LABEL: Record<ConversationImportance, string> = {
    critical: 'Critical',
    high: 'High',
    normal: 'Normal',
};

export interface SessionRowProps {
    conversation: Conversation;
    active: boolean;
    folders: ChatFolder[];
    onSelect: (id: number) => void;
    onPinnedChange: (id: number, pinned: boolean) => void;
    onArchivedChange: (id: number, archived: boolean) => void;
    onImportanceChange: (id: number, importance: ConversationImportance) => void;
    onFolderChange: (id: number, folderId: number | null) => void;
    onRename: (conversation: Conversation) => void;
    onDelete: (id: number) => void;
}

/**
 * One chat session in the Sessions sidebar.
 *
 * Stateless and fully controlled (R29): every action is a callback, so
 * the mutations live in one place in the sidebar and this component
 * stays trivial to test.
 *
 * The row is a `<button>` (select) with the actions menu as a SIBLING,
 * not a child — nesting interactive elements inside a button is invalid
 * HTML and makes the menu unreachable by keyboard.
 *
 * `data-importance` / `data-pinned` / `data-active` are the observable
 * state (R11): a colour alone is neither assertable nor accessible.
 */
export function SessionRow({
    conversation,
    active,
    folders,
    onSelect,
    onPinnedChange,
    onArchivedChange,
    onImportanceChange,
    onFolderChange,
    onRename,
    onDelete,
}: SessionRowProps): ReactNode {
    const title = conversation.title ?? 'Untitled chat';
    const isPinned = conversation.pinned_at !== null;

    return (
        <div
            className="chat-sessions-row"
            data-testid={`chat-sessions-row-${conversation.id}`}
            data-active={active ? 'true' : 'false'}
            data-pinned={isPinned ? 'true' : 'false'}
            data-importance={conversation.importance}
            data-archived={conversation.archived_at !== null ? 'true' : 'false'}
        >
            <button
                type="button"
                className="chat-sessions-row-select conv-row"
                data-testid={`chat-sessions-row-${conversation.id}-open`}
                aria-current={active ? 'true' : undefined}
                onClick={() => onSelect(conversation.id)}
                title={title}
            >
                <span className="conv-row-icon" aria-hidden="true">
                    {isPinned ? <Icon.Pin size={13} /> : <Icon.Chat size={13} />}
                </span>
                <span className="conv-row-copy">
                    <span className="conv-row-title">{title}</span>
                    <span className="conv-row-meta">
                        <span className="conv-row-project">
                            {conversation.project_key ?? 'All projects'}
                        </span>
                        {conversation.importance !== 'normal' && (
                            <span
                                className="chat-sessions-importance-badge"
                                data-level={conversation.importance}
                            >
                                {IMPORTANCE_LABEL[conversation.importance]}
                            </span>
                        )}
                        <time dateTime={conversation.updated_at}>
                            {humaniseDate(conversation.updated_at)}
                        </time>
                    </span>
                </span>
            </button>

            <SessionRowMenu
                conversation={conversation}
                folders={folders}
                onPinnedChange={(pinned) => onPinnedChange(conversation.id, pinned)}
                onArchivedChange={(archived) => onArchivedChange(conversation.id, archived)}
                onImportanceChange={(importance) => onImportanceChange(conversation.id, importance)}
                onFolderChange={(folderId) => onFolderChange(conversation.id, folderId)}
                onRename={() => onRename(conversation)}
                onDelete={() => onDelete(conversation.id)}
            />
        </div>
    );
}

/** Compact relative age, mirroring the /chat sidebar's formatting. */
export function humaniseDate(iso: string): string {
    const then = new Date(iso).getTime();
    if (!Number.isFinite(then)) {
        return '';
    }
    const diffMin = Math.max(0, Math.round((Date.now() - then) / 60_000));
    if (diffMin < 1) {
        return 'just now';
    }
    if (diffMin < 60) {
        return `${diffMin}m`;
    }
    const diffH = Math.round(diffMin / 60);
    if (diffH < 24) {
        return `${diffH}h`;
    }
    return `${Math.round(diffH / 24)}d`;
}
