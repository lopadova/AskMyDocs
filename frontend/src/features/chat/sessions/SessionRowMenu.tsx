import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Icon } from '../../../components/Icons';
import { Button } from '../../../components/Button';
import type { ChatFolder } from '../chat-folders.api';
import type { Conversation, ConversationImportance } from '../chat.api';

const IMPORTANCE_OPTIONS: ReadonlyArray<{ value: ConversationImportance; label: string }> = [
    { value: 'critical', label: 'Critical' },
    { value: 'high', label: 'High' },
    { value: 'normal', label: 'Normal' },
];

export interface SessionRowMenuProps {
    conversation: Conversation;
    folders: ChatFolder[];
    onPinnedChange: (pinned: boolean) => void;
    onArchivedChange: (archived: boolean) => void;
    onImportanceChange: (importance: ConversationImportance) => void;
    onFolderChange: (folderId: number | null) => void;
    onRename: () => void;
    onDelete: () => void;
}

/**
 * Per-session actions: pin, file, flag, rename, archive, delete.
 *
 * Hand-rolled rather than a dropdown library: the repo has no
 * dropdown-menu primitive, and the established chat pattern
 * (FilterPickerPopover, LiveSourcesControl) is Esc + click-outside with
 * real `<button>` children, so this stays consistent and adds no
 * dependency.
 *
 * A11y (R15): the container is a `role="menu"`, importance is a group of
 * `menuitemradio` carrying `aria-checked` so the current level is
 * announced rather than merely coloured, and every item is a focusable
 * button so Tab and Enter work without custom key handling.
 */
export function SessionRowMenu({
    conversation,
    folders,
    onPinnedChange,
    onArchivedChange,
    onImportanceChange,
    onFolderChange,
    onRename,
    onDelete,
}: SessionRowMenuProps): ReactNode {
    const [open, setOpen] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const wrapRef = useRef<HTMLDivElement>(null);

    // Esc + click-outside, capture phase so the trigger's own onClick
    // cannot swallow the outside click and leave the menu stuck open.
    useEffect(() => {
        if (!open) {
            return;
        }
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
                setConfirmingDelete(false);
            }
        };
        const onClick = (e: MouseEvent) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) {
                setOpen(false);
                setConfirmingDelete(false);
            }
        };
        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onClick, true);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onClick, true);
        };
    }, [open]);

    const id = conversation.id;
    const isPinned = conversation.pinned_at !== null;
    const isArchived = conversation.archived_at !== null;

    const act = (run: () => void) => {
        run();
        setOpen(false);
        setConfirmingDelete(false);
    };

    return (
        <div className="chat-sessions-row-menu-wrap" ref={wrapRef}>
            <Button
                variant="quiet"
                size="sm"
                iconOnly
                className="chat-sessions-row-menu-trigger"
                data-testid={`chat-sessions-row-${id}-menu`}
                aria-label={`Actions for ${conversation.title ?? 'untitled session'}`}
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={() => setOpen((v) => !v)}
            >
                <Icon.MoreH size={14} />
            </Button>

            {open && (
                <div
                    className="chat-sessions-row-menu"
                    role="menu"
                    data-testid={`chat-sessions-row-${id}-menu-panel`}
                    aria-label="Session actions"
                >
                    <button
                        type="button"
                        role="menuitem"
                        className="chat-sessions-menu-item"
                        data-testid={`chat-sessions-row-${id}-pin`}
                        onClick={() => act(() => onPinnedChange(!isPinned))}
                    >
                        <Icon.Pin size={13} />
                        {isPinned ? 'Unpin' : 'Pin to top'}
                    </button>

                    <button
                        type="button"
                        role="menuitem"
                        className="chat-sessions-menu-item"
                        data-testid={`chat-sessions-row-${id}-rename`}
                        onClick={() => act(onRename)}
                    >
                        <Icon.Edit size={13} />
                        Rename
                    </button>

                    <div className="chat-sessions-menu-group" role="group" aria-label="Importance">
                        <span className="chat-sessions-menu-group-label">Importance</span>
                        {IMPORTANCE_OPTIONS.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="menuitemradio"
                                aria-checked={conversation.importance === option.value}
                                className="chat-sessions-menu-item"
                                data-testid={`chat-sessions-row-${id}-importance-${option.value}`}
                                onClick={() => act(() => onImportanceChange(option.value))}
                            >
                                <Icon.Flag size={13} />
                                {option.label}
                            </button>
                        ))}
                    </div>

                    <div className="chat-sessions-menu-group" role="group" aria-label="Move to folder">
                        <span className="chat-sessions-menu-group-label">Folder</span>
                        <button
                            type="button"
                            role="menuitemradio"
                            aria-checked={conversation.chat_folder_id === null}
                            className="chat-sessions-menu-item"
                            data-testid={`chat-sessions-row-${id}-move-none`}
                            onClick={() => act(() => onFolderChange(null))}
                        >
                            No folder
                        </button>
                        {folders.map((folder) => (
                            <button
                                key={folder.id}
                                type="button"
                                role="menuitemradio"
                                aria-checked={conversation.chat_folder_id === folder.id}
                                className="chat-sessions-menu-item"
                                data-testid={`chat-sessions-row-${id}-move-${folder.id}`}
                                onClick={() => act(() => onFolderChange(folder.id))}
                            >
                                <Icon.Folder size={13} />
                                {folder.name}
                            </button>
                        ))}
                    </div>

                    <button
                        type="button"
                        role="menuitem"
                        className="chat-sessions-menu-item"
                        data-testid={`chat-sessions-row-${id}-archive`}
                        onClick={() => act(() => onArchivedChange(!isArchived))}
                    >
                        <Icon.Archive size={13} />
                        {isArchived ? 'Restore' : 'Archive'}
                    </button>

                    {/*
                      Delete is the only irreversible action here — archive
                      exists precisely so it rarely needs to be used — so it
                      asks twice instead of acting on the first click.
                    */}
                    {confirmingDelete ? (
                        <div className="chat-sessions-menu-confirm">
                            <span>Delete permanently?</span>
                            <button
                                type="button"
                                role="menuitem"
                                className="chat-sessions-menu-item is-danger"
                                data-testid={`chat-sessions-row-${id}-delete-confirm`}
                                onClick={() => act(onDelete)}
                            >
                                Delete
                            </button>
                            <button
                                type="button"
                                role="menuitem"
                                className="chat-sessions-menu-item"
                                data-testid={`chat-sessions-row-${id}-delete-cancel`}
                                onClick={() => setConfirmingDelete(false)}
                            >
                                Cancel
                            </button>
                        </div>
                    ) : (
                        <button
                            type="button"
                            role="menuitem"
                            className="chat-sessions-menu-item is-danger"
                            data-testid={`chat-sessions-row-${id}-delete`}
                            onClick={() => setConfirmingDelete(true)}
                        >
                            <Icon.Trash size={13} />
                            Delete
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
