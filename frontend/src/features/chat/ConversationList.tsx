import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '../../components/Icons';
import { Button } from '../../components/Button';
import { chatApi, type Conversation } from './chat.api';
import { useChatStore } from './chat.store';

export interface ConversationListProps {
    projectKey: string | null;
    onSelect: (id: number | null) => void;
    /** v8.8.3 — start an anonymous (non-persisted) chat. */
    onNewAnonymous: () => void;
}

/**
 * Sidebar: New chat button, search, two time-bucketed sections
 * (Today / Earlier). Empty state surfaces a testid-tagged message
 * so Playwright can assert the no-chats path.
 */
export function ConversationList({ projectKey, onSelect, onNewAnonymous }: ConversationListProps): ReactNode {
    const qc = useQueryClient();
    const [filter, setFilter] = useState('');
    const activeId = useChatStore((s) => s.activeConversationId);

    const { data, isLoading, isError } = useQuery<Conversation[]>({
        queryKey: ['conversations'],
        queryFn: chatApi.listConversations,
    });

    const createMutation = useMutation<Conversation, Error, void>({
        mutationFn: () => chatApi.createConversation(projectKey),
        onSuccess: (created) => {
            qc.setQueryData<Conversation[]>(['conversations'], (old) =>
                old ? [created, ...old] : [created],
            );
            onSelect(created.id);
        },
    });

    const conversations = data ?? [];
    const filtered = useMemo(() => {
        if (!filter.trim()) {
            return conversations;
        }
        const q = filter.toLowerCase();
        return conversations.filter((c) => (c.title ?? '').toLowerCase().includes(q));
    }, [conversations, filter]);

    const { today, recent, earlier } = splitByFreshness(filtered);

    const state = isLoading ? 'loading' : isError ? 'error' : conversations.length === 0 ? 'empty' : 'ready';

    return (
        <aside
            data-testid="chat-sidebar"
            data-state={state}
            aria-label="Conversations"
            className="chat-conversation-sidebar"
        >
            <div className="chat-conversation-sidebar-head">
                <div className="chat-conversation-sidebar-heading">
                    <span className="chat-conversation-sidebar-heading-icon" aria-hidden="true">
                        <Icon.Chat size={14} />
                    </span>
                    <span className="chat-conversation-sidebar-heading-copy">
                        <strong>Conversations</strong>
                        <small aria-live="polite">
                            {isLoading
                                ? 'Loading history…'
                                : filter.trim() !== ''
                                  ? `${filtered.length} ${filtered.length === 1 ? 'result' : 'results'}`
                                  : `${conversations.length} ${conversations.length === 1 ? 'chat' : 'chats'}`}
                        </small>
                    </span>
                </div>

                <div className="chat-conversation-actions">
                    <Button
                        variant="primary"
                        size="md"
                        className="chat-conversation-new"
                        data-testid="chat-new-conversation"
                        onClick={() => createMutation.mutate()}
                        busy={createMutation.isPending}
                        leadingIcon={<Icon.Plus size={14} />}
                    >
                        {createMutation.isPending ? 'Creating…' : 'New chat'}
                    </Button>
                    <Button
                        variant="secondary"
                        size="md"
                        className="chat-conversation-anonymous"
                        data-testid="chat-new-anonymous-chat"
                        onClick={onNewAnonymous}
                        title="Start a chat that is not saved"
                        leadingIcon={<Icon.Eye size={13} />}
                    >
                        Anonymous
                    </Button>
                </div>

                <label className="chat-conversation-search">
                    <span aria-hidden="true"><Icon.Search size={13} /></span>
                    <input
                        type="search"
                        data-testid="chat-sidebar-search"
                        aria-label="Search conversations"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        placeholder="Search conversations"
                    />
                    {filter !== '' && (
                        <button
                            type="button"
                            className="chat-conversation-search-clear"
                            aria-label="Clear conversation search"
                            onClick={() => setFilter('')}
                        >
                            <Icon.Close size={11} />
                        </button>
                    )}
                </label>
            </div>

            <div className="chat-conversation-list" data-testid="chat-conversation-list">
                {state === 'loading' && <SidebarSkeleton />}
                {state === 'empty' && (
                    <div
                        data-testid="chat-sidebar-empty"
                        className="chat-conversation-empty"
                    >
                        <span aria-hidden="true"><Icon.Chat size={18} /></span>
                        <strong>Your conversations will appear here</strong>
                        <small>Start a new chat to begin.</small>
                    </div>
                )}
                {state === 'error' && (
                    <div data-testid="chat-sidebar-error" role="alert" className="chat-conversation-error">
                        <Icon.Alert size={14} />
                        <span>Failed to load conversations.</span>
                    </div>
                )}
                {today.length > 0 && <SectionHeader label="Today" count={today.length} />}
                {today.map((c) => (
                    <ConversationRow key={c.id} c={c} active={c.id === activeId} onSelect={onSelect} />
                ))}
                {recent.length > 0 && <SectionHeader label="Previous 7 days" count={recent.length} />}
                {recent.map((c) => (
                    <ConversationRow key={c.id} c={c} active={c.id === activeId} onSelect={onSelect} />
                ))}
                {earlier.length > 0 && <SectionHeader label="Earlier" count={earlier.length} />}
                {earlier.map((c) => (
                    <ConversationRow key={c.id} c={c} active={c.id === activeId} onSelect={onSelect} />
                ))}
                {state === 'ready' && filter.trim() !== '' && filtered.length === 0 && (
                    <div
                        data-testid="chat-sidebar-no-results"
                        className="chat-conversation-empty is-search"
                    >
                        <span aria-hidden="true"><Icon.Search size={18} /></span>
                        <strong>No matching conversations</strong>
                        <small>Try another title or clear the search.</small>
                    </div>
                )}
            </div>

            {createMutation.isError && (
                <div
                    data-testid="chat-new-conversation-error"
                    role="alert"
                    className="chat-conversation-create-error"
                >
                    <Icon.Alert size={13} />
                    <span>Could not create a conversation.</span>
                </div>
            )}
        </aside>
    );
}

function SidebarSkeleton(): ReactNode {
    // Placeholder rows shown while the conversation list loads, so the
    // sidebar shows shape instead of a blank panel (perceived speed).
    return (
        <div data-testid="chat-sidebar-loading" aria-hidden="true" className="chat-conversation-skeleton">
            {[0, 1, 2, 3, 4].map((i) => (
                <div key={i} className="chat-conversation-skeleton-row">
                    <span className="shimmer" />
                    <div>
                        <span className="shimmer" style={{ width: `${72 - i * 7}%` }} />
                        <span className="shimmer" />
                    </div>
                </div>
            ))}
        </div>
    );
}

function SectionHeader({ label, count }: { label: string; count: number }): ReactNode {
    return (
        <div className="chat-conversation-section-heading">
            <span>{label}</span>
            <span aria-label={`${count} ${count === 1 ? 'conversation' : 'conversations'}`}>{count}</span>
        </div>
    );
}

interface ConversationRowProps {
    c: Conversation;
    active: boolean;
    onSelect: (id: number) => void;
}

function ConversationRow({ c, active, onSelect }: ConversationRowProps): ReactNode {
    return (
        <button
            type="button"
            className="conv-row"
            data-testid={`chat-conversation-${c.id}`}
            data-active={active ? 'true' : 'false'}
            aria-current={active ? 'true' : undefined}
            onClick={() => onSelect(c.id)}
            title={c.title ?? 'Untitled chat'}
        >
            <span className="conv-row-icon" aria-hidden="true">
                <Icon.Chat size={13} />
            </span>
            <span className="conv-row-copy">
                <span className="conv-row-title">
                    {c.title ?? 'Untitled chat'}
                </span>
                <span className="conv-row-meta">
                    <span className="conv-row-project">
                        <span aria-hidden="true" />
                        {c.project_key ?? 'All projects'}
                    </span>
                    <time dateTime={c.updated_at}>{humaniseDate(c.updated_at)}</time>
                </span>
            </span>
            <span className="conv-row-chevron" aria-hidden="true">
                <Icon.Chevron size={11} />
            </span>
        </button>
    );
}

function splitByFreshness(list: Conversation[]): { today: Conversation[]; recent: Conversation[]; earlier: Conversation[] } {
    const today: Conversation[] = [];
    const recent: Conversation[] = [];
    const earlier: Conversation[] = [];
    const now = Date.now();
    for (const c of list) {
        const updated = new Date(c.updated_at).getTime();
        if (now - updated < 24 * 60 * 60 * 1000) {
            today.push(c);
            continue;
        }
        if (now - updated < 7 * 24 * 60 * 60 * 1000) {
            recent.push(c);
            continue;
        }
        earlier.push(c);
    }
    return { today, recent, earlier };
}

function humaniseDate(iso: string): string {
    const then = new Date(iso).getTime();
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
    const diffD = Math.round(diffH / 24);
    return `${diffD}d`;
}
