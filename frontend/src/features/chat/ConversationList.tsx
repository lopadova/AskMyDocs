import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '../../components/Icons';
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

    const { today, earlier } = splitByFreshness(filtered);

    const state = isLoading ? 'loading' : isError ? 'error' : conversations.length === 0 ? 'empty' : 'ready';

    return (
        <aside
            data-testid="chat-sidebar"
            data-state={state}
            aria-label="Conversations"
            style={{
                width: 272,
                flex: '0 0 272px',
                borderRight: '1px solid var(--hairline)',
                background: 'var(--bg-1)',
                display: 'flex',
                flexDirection: 'column',
            }}
        >
            <div style={{ padding: 12, display: 'flex', flexDirection: 'column', gap: 8 }}>
                <button
                    type="button"
                    className="btn primary"
                    data-testid="chat-new-conversation"
                    onClick={() => createMutation.mutate()}
                    disabled={createMutation.isPending}
                    aria-busy={createMutation.isPending}
                    style={{ width: '100%', justifyContent: 'center' }}
                >
                    <Icon.Plus size={13} />
                    New chat
                </button>
                <button
                    type="button"
                    className="btn"
                    data-testid="chat-new-anonymous-chat"
                    onClick={onNewAnonymous}
                    style={{ width: '100%', justifyContent: 'center' }}
                >
                    <Icon.Eye size={13} />
                    New anonymous chat
                </button>
            </div>
            <div style={{ padding: '0 12px 10px' }}>
                <div style={{ position: 'relative' }}>
                    <Icon.Search
                        size={12}
                        style={{ position: 'absolute', left: 10, top: 9.5, color: 'var(--fg-3)' }}
                    />
                    <input
                        type="search"
                        data-testid="chat-sidebar-search"
                        aria-label="Search conversations"
                        className="input"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        placeholder="Search conversations"
                        style={{ paddingLeft: 30, height: 30, fontSize: 12, width: '100%' }}
                    />
                </div>
            </div>

            <div style={{ flex: 1, overflow: 'auto', padding: '4px 10px 10px' }}>
                {state === 'loading' && <SidebarSkeleton />}
                {state === 'empty' && (
                    <div
                        data-testid="chat-sidebar-empty"
                        style={{ fontSize: 12, color: 'var(--fg-3)', padding: '10px 6px', lineHeight: 1.6 }}
                    >
                        No conversations yet. Click <strong>New chat</strong> to start.
                    </div>
                )}
                {state === 'error' && (
                    <div data-testid="chat-sidebar-error" role="alert" style={{ padding: 10, fontSize: 12, color: 'var(--err)' }}>
                        Failed to load conversations.
                    </div>
                )}
                {today.length > 0 && <SectionHeader label="Today" />}
                {today.map((c) => (
                    <ConversationRow key={c.id} c={c} active={c.id === activeId} onSelect={onSelect} />
                ))}
                {earlier.length > 0 && <SectionHeader label="Earlier" />}
                {earlier.map((c) => (
                    <ConversationRow key={c.id} c={c} active={c.id === activeId} onSelect={onSelect} />
                ))}
                {state === 'ready' && filter.trim() !== '' && filtered.length === 0 && (
                    <div
                        data-testid="chat-sidebar-no-results"
                        style={{ fontSize: 12, color: 'var(--fg-3)', padding: '10px 6px', lineHeight: 1.6 }}
                    >
                        No conversations match “<strong>{filter.trim()}</strong>”.
                    </div>
                )}
            </div>

            {createMutation.isError && (
                <div
                    data-testid="chat-new-conversation-error"
                    role="alert"
                    style={{ padding: 10, fontSize: 12, color: 'var(--err)' }}
                >
                    Could not create a conversation.
                </div>
            )}
        </aside>
    );
}

function SidebarSkeleton(): ReactNode {
    // Placeholder rows shown while the conversation list loads, so the
    // sidebar shows shape instead of a blank panel (perceived speed).
    return (
        <div data-testid="chat-sidebar-loading" aria-hidden="true" style={{ padding: '6px 0' }}>
            {[0, 1, 2, 3, 4].map((i) => (
                <div key={i} style={{ padding: '8px 10px', marginBottom: 2 }}>
                    <div className="shimmer" style={{ height: 11, borderRadius: 4, width: `${72 - i * 7}%` }} />
                    <div className="shimmer" style={{ height: 8, borderRadius: 4, width: '42%', marginTop: 6 }} />
                </div>
            ))}
        </div>
    );
}

function SectionHeader({ label }: { label: string }): ReactNode {
    return (
        <div
            style={{
                fontSize: 10,
                color: 'var(--fg-3)',
                textTransform: 'uppercase',
                letterSpacing: '.08em',
                padding: '10px 6px 4px',
                fontFamily: 'var(--font-mono)',
            }}
        >
            {label}
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
        >
            <div style={{ flex: 1, minWidth: 0 }}>
                <div
                    style={{
                        fontSize: 12.5,
                        color: 'var(--fg-0)',
                        fontWeight: active ? 500 : 400,
                        whiteSpace: 'nowrap',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                    }}
                >
                    {c.title ?? 'Untitled chat'}
                </div>
                <div
                    style={{
                        fontSize: 10.5,
                        color: 'var(--fg-3)',
                        fontFamily: 'var(--font-mono)',
                        marginTop: 1,
                    }}
                >
                    {c.project_key ?? 'any'} · {humaniseDate(c.updated_at)}
                </div>
            </div>
        </button>
    );
}

function splitByFreshness(list: Conversation[]): { today: Conversation[]; earlier: Conversation[] } {
    const today: Conversation[] = [];
    const earlier: Conversation[] = [];
    const now = Date.now();
    for (const c of list) {
        const updated = new Date(c.updated_at).getTime();
        if (now - updated < 24 * 60 * 60 * 1000) {
            today.push(c);
            continue;
        }
        earlier.push(c);
    }
    return { today, earlier };
}

function humaniseDate(iso: string): string {
    const then = new Date(iso).getTime();
    const diffMin = Math.max(0, Math.round((Date.now() - then) / 60_000));
    if (diffMin < 1) {
        return 'just now';
    }
    if (diffMin < 60) {
        return `${diffMin}m ago`;
    }
    const diffH = Math.round(diffMin / 60);
    if (diffH < 24) {
        return `${diffH}h ago`;
    }
    const diffD = Math.round(diffH / 24);
    return `${diffD}d ago`;
}
