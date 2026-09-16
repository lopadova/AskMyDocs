import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '../../../components/Icons';
import { Button } from '../../../components/Button';
import { ProjectSelector } from '../ProjectSelector';
import {
    chatApi,
    type Conversation,
    type ConversationImportance,
    type ConversationOrganizePayload,
} from '../chat.api';
import { chatFoldersApi, CHAT_FOLDERS_QUERY_KEY, type ChatFolder } from '../chat-folders.api';
import { SessionRow } from './SessionRow';
import { NameDialog } from './NameDialog';

/**
 * Query keys.
 *
 * The archived list is NESTED under ['conversations'] rather than being
 * a sibling like ['conversations-archived']. That is load-bearing: the
 * chat engine already calls
 * `invalidateQueries({ queryKey: ['conversations'] })` after every
 * settled turn, and TanStack's prefix matching then covers BOTH lists.
 * Flatten it and the archived drawer silently goes stale.
 */
const ACTIVE_KEY = ['conversations'] as const;
const ARCHIVED_KEY = ['conversations', 'archived'] as const;

export interface SessionSidebarProps {
    /** Project scope for a brand-new session. */
    projectKey: string | null;
    projectScopeValue: string | null;
    teamProjectKeys: string[];
    onScopeChange: (next: string) => void;
    projectSelectorDisabled?: boolean;
    activeId: number | null;
    onSelect: (id: number | null) => void;
    /** Navigate to the reader-side Browse KB page. */
    onOpenKnowledgeBase: () => void;
}

export function SessionSidebar({
    projectKey,
    projectScopeValue,
    teamProjectKeys,
    onScopeChange,
    projectSelectorDisabled = false,
    activeId,
    onSelect,
    onOpenKnowledgeBase,
}: SessionSidebarProps): ReactNode {
    const qc = useQueryClient();
    const [search, setSearch] = useState('');
    const [showArchived, setShowArchived] = useState(false);
    const [collapsedFolders, setCollapsedFolders] = useState<Record<number, boolean>>({});
    const [folderDialog, setFolderDialog] = useState<{ mode: 'create' } | { mode: 'rename'; folder: ChatFolder } | null>(null);
    const [renaming, setRenaming] = useState<Conversation | null>(null);
    // Folder deletion asks first. It is irreversible, and what it does to
    // the filed sessions (unfile, NOT delete) is not obvious from a bin
    // icon — ChatFolderController's docblock says the UI must state it.
    const [confirmingFolderDelete, setConfirmingFolderDelete] = useState<number | null>(null);

    const sessionsQuery = useQuery<Conversation[]>({
        queryKey: ACTIVE_KEY,
        queryFn: () => chatApi.listConversations(),
    });

    // Fetched only while the drawer is open: an archive is by definition
    // the list nobody is looking at.
    const archivedQuery = useQuery<Conversation[]>({
        queryKey: ARCHIVED_KEY,
        queryFn: () => chatApi.listConversations('archived'),
        enabled: showArchived,
    });

    const foldersQuery = useQuery<ChatFolder[]>({
        queryKey: CHAT_FOLDERS_QUERY_KEY,
        queryFn: () => chatFoldersApi.list(),
    });

    const refreshLists = async (): Promise<void> => {
        // Prefix invalidation reaches the archived list too (see above).
        await qc.invalidateQueries({ queryKey: ACTIVE_KEY });
    };

    const organize = useMutation<Conversation, Error, { id: number; payload: ConversationOrganizePayload }>({
        mutationFn: ({ id, payload }) => chatApi.organizeConversation(id, payload),
        onSuccess: () => void refreshLists(),
    });

    const createSession = useMutation<Conversation, Error, void>({
        mutationFn: () => chatApi.createConversation(projectKey),
        onSuccess: (created) => {
            // R25: dedupe by id before prepending, so a row already in the
            // cache (from a prior refetch race) cannot render twice.
            qc.setQueryData<Conversation[]>(ACTIVE_KEY, (old) => [
                created,
                ...(old ?? []).filter((c) => c.id !== created.id),
            ]);
            onSelect(created.id);
        },
    });

    const deleteSession = useMutation<void, Error, number>({
        mutationFn: (id) => chatApi.deleteConversation(id),
        onSuccess: (_result, id) => {
            if (id === activeId) {
                onSelect(null);
            }
            void refreshLists();
        },
    });

    const deleteFolder = useMutation<void, Error, number>({
        mutationFn: (id) => chatFoldersApi.remove(id),
        onSuccess: () => {
            // Both caches: every session filed under it just became
            // unfiled (the BE FK is nullOnDelete).
            void qc.invalidateQueries({ queryKey: CHAT_FOLDERS_QUERY_KEY });
            void refreshLists();
        },
    });

    const sessions = sessionsQuery.data ?? [];
    const folders = foldersQuery.data ?? [];

    // Search, grouping and ordering are all client-side: the server
    // already returns this user's sessions in display order, and a list
    // in the tens does not need a round-trip per keystroke.
    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (q === '') {
            return sessions;
        }
        return sessions.filter((c) => (c.title ?? '').toLowerCase().includes(q));
    }, [sessions, search]);

    const pinned = visible.filter((c) => c.pinned_at !== null);
    const unpinned = visible.filter((c) => c.pinned_at === null);
    const unfiled = unpinned.filter((c) => c.chat_folder_id === null);
    const byFolder = (folderId: number): Conversation[] =>
        unpinned.filter((c) => c.chat_folder_id === folderId);

    /**
     * How many sessions a folder deletion would unfile.
     *
     * Counted from the FULL list, not from the rendered rows: those are
     * `unpinned` and search-filtered, so a folder holding one pinned and
     * two unpinned sessions would have promised "2 sessions" while three
     * moved out — and an active search term could shrink it further.
     * Stating the blast radius is the whole reason the prompt exists.
     */
    const folderMemberCount = (folderId: number): number =>
        sessions.filter((c) => c.chat_folder_id === folderId).length;

    const state = sessionsQuery.isLoading
        ? 'loading'
        : sessionsQuery.isError
          ? 'error'
          : sessions.length === 0
            ? 'empty'
            : 'ready';

    const rowHandlers = {
        folders,
        onSelect,
        onPinnedChange: (id: number, pinned: boolean) => organize.mutate({ id, payload: { pinned } }),
        onArchivedChange: (id: number, archived: boolean) =>
            organize.mutate({ id, payload: { archived } }),
        onImportanceChange: (id: number, importance: ConversationImportance) =>
            organize.mutate({ id, payload: { importance } }),
        onFolderChange: (id: number, chat_folder_id: number | null) =>
            organize.mutate({ id, payload: { chat_folder_id } }),
        onRename: (conversation: Conversation) => setRenaming(conversation),
        onDelete: (id: number) => deleteSession.mutate(id),
    };

    return (
        <aside
            data-testid="chat-sessions-sidebar"
            data-state={state}
            data-count={sessions.length}
            aria-label="Chat sessions"
            className="chat-sessions-sidebar chat-conversation-sidebar"
        >
            {/* Knowledge base sits ABOVE the sessions: it is the shared
                material every session draws on, not one of them. */}
            <button
                type="button"
                className="chat-sessions-kb-entry"
                data-testid="chat-sessions-kb-entry"
                onClick={onOpenKnowledgeBase}
            >
                <span className="chat-sessions-kb-icon" aria-hidden="true">
                    <Icon.Book size={15} />
                </span>
                <span className="chat-sessions-kb-copy">
                    <span className="chat-sessions-kb-title">Knowledge base</span>
                    <span className="chat-sessions-kb-hint">Browse documents</span>
                </span>
                <Icon.Chevron size={11} />
            </button>

            <div className="chat-sessions-scope">
                {/* A decorative caption, not a <label>: ProjectSelector's
                    <select> already carries aria-label="Project scope", and
                    a <label> without htmlFor is the R15 anti-pattern. */}
                <span className="chat-sessions-scope-label" aria-hidden="true">
                    Project
                </span>
                <ProjectSelector
                    value={projectScopeValue}
                    projects={teamProjectKeys}
                    allowAll
                    disabled={projectSelectorDisabled}
                    onChange={onScopeChange}
                />
            </div>

            <div className="chat-sessions-actions">
                <Button
                    variant="primary"
                    size="sm"
                    data-testid="chat-sessions-new"
                    busy={createSession.isPending}
                    onClick={() => createSession.mutate()}
                    leadingIcon={<Icon.Plus size={13} />}
                >
                    New session
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    data-testid="chat-sessions-new-folder"
                    onClick={() => setFolderDialog({ mode: 'create' })}
                    leadingIcon={<Icon.Folder size={13} />}
                >
                    New folder
                </Button>
            </div>

            {createSession.isError && (
                <p className="chat-sessions-error" role="alert" data-testid="chat-sessions-new-error">
                    {createSession.error.message}
                </p>
            )}
            {organize.isError && (
                <p className="chat-sessions-error" role="alert" data-testid="chat-sessions-organize-error">
                    {organize.error.message}
                </p>
            )}
            {/* Both deletes used to fail silently: a 403 or a 500 left the row
                in place with no explanation (R11/R14). */}
            {deleteSession.isError && (
                <p className="chat-sessions-error" role="alert" data-testid="chat-sessions-delete-error">
                    The session could not be deleted. {deleteSession.error.message}
                </p>
            )}
            {deleteFolder.isError && (
                <p
                    className="chat-sessions-error"
                    role="alert"
                    data-testid="chat-sessions-folder-delete-error"
                >
                    The folder could not be deleted. {deleteFolder.error.message}
                </p>
            )}

            <div className="chat-sessions-search">
                <label className="sr-only" htmlFor="chat-sessions-search-input">
                    Search sessions
                </label>
                <input
                    id="chat-sessions-search-input"
                    type="search"
                    data-testid="chat-sessions-search"
                    placeholder="Search sessions"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </div>

            {state === 'loading' && (
                <p data-testid="chat-sessions-loading" className="chat-sessions-hint">
                    Loading sessions…
                </p>
            )}
            {state === 'error' && (
                <p data-testid="chat-sessions-error" role="alert" className="chat-sessions-hint">
                    Sessions could not be loaded.
                </p>
            )}
            {state === 'empty' && (
                <p data-testid="chat-sessions-empty" className="chat-sessions-hint">
                    No sessions yet. Start one to see it here.
                </p>
            )}
            {state === 'ready' && visible.length === 0 && (
                <p data-testid="chat-sessions-no-results" className="chat-sessions-hint">
                    No session matches “{search}”.
                </p>
            )}

            {pinned.length > 0 && (
                <section className="chat-sessions-group" data-testid="chat-sessions-group-pinned">
                    <h3 className="chat-sessions-group-label">Pinned</h3>
                    {pinned.map((c) => (
                        <SessionRow key={c.id} conversation={c} active={c.id === activeId} {...rowHandlers} />
                    ))}
                </section>
            )}

            {folders.map((folder) => {
                const rows = byFolder(folder.id);
                const collapsed = collapsedFolders[folder.id] === true;
                const panelId = `chat-sessions-folder-panel-${folder.id}`;

                return (
                    <section
                        key={folder.id}
                        className="chat-sessions-group"
                        data-testid={`chat-sessions-folder-${folder.id}`}
                        data-expanded={collapsed ? 'false' : 'true'}
                        data-count={rows.length}
                    >
                        <div className="chat-sessions-folder-head">
                            <button
                                type="button"
                                className="chat-sessions-folder-toggle"
                                data-testid={`chat-sessions-folder-${folder.id}-toggle`}
                                aria-expanded={!collapsed}
                                aria-controls={panelId}
                                onClick={() =>
                                    setCollapsedFolders((prev) => ({ ...prev, [folder.id]: !collapsed }))
                                }
                            >
                                <Icon.ChevronDown
                                    size={11}
                                    style={{ transform: collapsed ? 'rotate(-90deg)' : undefined }}
                                />
                                <Icon.Folder size={13} />
                                <span className="chat-sessions-group-label">{folder.name}</span>
                                <span className="chat-sessions-folder-count">{rows.length}</span>
                            </button>
                            <Button
                                variant="quiet"
                                size="sm"
                                iconOnly
                                data-testid={`chat-sessions-folder-${folder.id}-rename`}
                                aria-label={`Rename folder ${folder.name}`}
                                onClick={() => setFolderDialog({ mode: 'rename', folder })}
                            >
                                <Icon.Edit size={12} />
                            </Button>
                            <Button
                                variant="quiet"
                                size="sm"
                                iconOnly
                                data-testid={`chat-sessions-folder-${folder.id}-delete`}
                                aria-label={`Delete folder ${folder.name}`}
                                onClick={() => setConfirmingFolderDelete(folder.id)}
                            >
                                <Icon.Trash size={12} />
                            </Button>
                        </div>
                        {confirmingFolderDelete === folder.id && (
                            <div
                                className="chat-sessions-folder-confirm"
                                role="alertdialog"
                                aria-label={`Delete folder ${folder.name}?`}
                                data-testid={`chat-sessions-folder-${folder.id}-delete-confirm-prompt`}
                            >
                                <p>
                                    Delete “{folder.name}”?{' '}
                                    {folderMemberCount(folder.id) === 1
                                        ? 'Its session is'
                                        : `Its ${folderMemberCount(folder.id)} sessions are`}{' '}
                                    moved out of the folder, not deleted.
                                </p>
                                <div className="chat-sessions-folder-confirm-actions">
                                    <Button
                                        variant="danger"
                                        size="sm"
                                        data-testid={`chat-sessions-folder-${folder.id}-delete-confirm`}
                                        onClick={() => {
                                            setConfirmingFolderDelete(null);
                                            deleteFolder.mutate(folder.id);
                                        }}
                                    >
                                        Delete folder
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        data-testid={`chat-sessions-folder-${folder.id}-delete-cancel`}
                                        onClick={() => setConfirmingFolderDelete(null)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </div>
                        )}
                        <div id={panelId} hidden={collapsed}>
                            {rows.length === 0 ? (
                                <p className="chat-sessions-hint is-nested">Empty folder.</p>
                            ) : (
                                rows.map((c) => (
                                    <SessionRow
                                        key={c.id}
                                        conversation={c}
                                        active={c.id === activeId}
                                        {...rowHandlers}
                                    />
                                ))
                            )}
                        </div>
                    </section>
                );
            })}

            {unfiled.length > 0 && (
                <section className="chat-sessions-group" data-testid="chat-sessions-group-unfiled">
                    <h3 className="chat-sessions-group-label">
                        {folders.length > 0 ? 'Unfiled' : 'Sessions'}
                    </h3>
                    {unfiled.map((c) => (
                        <SessionRow key={c.id} conversation={c} active={c.id === activeId} {...rowHandlers} />
                    ))}
                </section>
            )}

            <section className="chat-sessions-group chat-sessions-archived">
                <button
                    type="button"
                    className="chat-sessions-folder-toggle"
                    data-testid="chat-sessions-archived-toggle"
                    aria-pressed={showArchived}
                    aria-controls="chat-sessions-archived-panel"
                    onClick={() => setShowArchived((v) => !v)}
                >
                    <Icon.Archive size={13} />
                    <span className="chat-sessions-group-label">Archived</span>
                </button>
                <div id="chat-sessions-archived-panel" hidden={!showArchived}>
                    {archivedQuery.isLoading && (
                        <p className="chat-sessions-hint is-nested" data-testid="chat-sessions-archived-loading">
                            Loading…
                        </p>
                    )}
                    {archivedQuery.isError && (
                        <p
                            className="chat-sessions-hint is-nested"
                            role="alert"
                            data-testid="chat-sessions-archived-error"
                        >
                            Archived sessions could not be loaded.
                        </p>
                    )}
                    {archivedQuery.data?.length === 0 && (
                        <p className="chat-sessions-hint is-nested" data-testid="chat-sessions-archived-empty">
                            Nothing archived.
                        </p>
                    )}
                    {(archivedQuery.data ?? []).map((c) => (
                        <SessionRow key={c.id} conversation={c} active={c.id === activeId} {...rowHandlers} />
                    ))}
                </div>
            </section>

            {/* One generic single-field dialog serves all three naming
                flows; only the submit function differs. */}
            {folderDialog?.mode === 'create' && (
                <NameDialog
                    testId="chat-sessions-folder-dialog"
                    title="New folder"
                    description="Group related sessions, for example by issue."
                    submitLabel="Create"
                    initialValue=""
                    onSubmit={async (name) => {
                        await chatFoldersApi.create(name);
                        await qc.invalidateQueries({ queryKey: CHAT_FOLDERS_QUERY_KEY });
                    }}
                    onClose={() => setFolderDialog(null)}
                />
            )}

            {folderDialog?.mode === 'rename' && (
                <NameDialog
                    testId="chat-sessions-folder-dialog"
                    title="Rename folder"
                    description="Sessions filed here keep their place."
                    submitLabel="Save"
                    initialValue={folderDialog.folder.name}
                    onSubmit={async (name) => {
                        await chatFoldersApi.rename(folderDialog.folder.id, name);
                        await qc.invalidateQueries({ queryKey: CHAT_FOLDERS_QUERY_KEY });
                    }}
                    onClose={() => setFolderDialog(null)}
                />
            )}

            {renaming !== null && (
                <NameDialog
                    testId="chat-sessions-rename-dialog"
                    title="Rename session"
                    description="Only the title changes; the transcript is untouched."
                    submitLabel="Save"
                    initialValue={renaming.title ?? ''}
                    onSubmit={async (title) => {
                        await chatApi.organizeConversation(renaming.id, { title });
                        await refreshLists();
                    }}
                    onClose={() => setRenaming(null)}
                />
            )}
        </aside>
    );
}
