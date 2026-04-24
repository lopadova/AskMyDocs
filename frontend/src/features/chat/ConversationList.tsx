import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '../../components/Icons';
import { chatApi, type Conversation } from './chat.api';
import { useChatStore } from './chat.store';

export interface ConversationListProps {
    projectKey: string | null;
    onSelect: (id: number | null) => void;
}

/**
 * Sidebar: New chat button, search, two time-bucketed sections
 * (Today / Earlier). Empty state surfaces a testid-tagged message
 * so Playwright can assert the no-chats path.
 */
export function ConversationList({ projectKey, onSelect }: ConversationListProps): ReactNode {
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
            <div style={{ padding: 12, display: 'flex', gap: 8 }}>
                <button
                    type="button"
                    className="btn primary"
                    data-testid="chat-new-conversation"
                    onClick={() => createMutation.mutate()}
                    disabled={createMutation.isPending}
                    aria-busy={createMutation.isPending}
                    style={{ flex: 1 }}
                >
                    <Icon.Plus size={13} />
                    New chat
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
            data-testid={`chat-conversation-${c.id}`}
            data-active={active ? 'true' : 'false'}
            onClick={() => onSelect(c.id)}
            style={{
                width: '100%',
                display: 'flex',
                gap: 9,
                padding: '8px 10px',
                background: active ? 'var(--bg-3)' : 'transparent',
                border: '1px solid ' + (active ? 'var(--panel-border)' : 'transparent'),
                borderRadius: 8,
                cursor: 'pointer',
                marginBottom: 2,
                textAlign: 'left',
            }}
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
