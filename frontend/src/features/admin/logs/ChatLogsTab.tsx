import { useState } from 'react';
import { useChatLog, useChatLogs, type ChatLogRow, type ChatLogsQuery } from './logs.api';

/*
 * Phase H1 — admin Log Viewer, Chat tab.
 *
 * Filter bar (project / model / min_latency / min_tokens / date range)
 * feeds the /api/admin/logs/chat query; the table below renders the
 * paginated result. Click a row to open the drawer — a second query
 * fetches the full ChatLog by id (question/answer are already on the
 * list payload but the drawer demonstrates the `show()` endpoint
 * contract).
 */

export function ChatLogsTab() {
    const [project, setProject] = useState('');
    const [model, setModel] = useState('');
    const [minLatency, setMinLatency] = useState('');
    const [minTokens, setMinTokens] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [page, setPage] = useState(1);
    const [drawerId, setDrawerId] = useState<number | null>(null);

    const query: ChatLogsQuery = {
        project: project || undefined,
        model: model || undefined,
        min_latency_ms: minLatency === '' ? undefined : Number(minLatency),
        min_tokens: minTokens === '' ? undefined : Number(minTokens),
        from: from || undefined,
        to: to || undefined,
        page,
    };

    const chats = useChatLogs(query);
    const state: 'loading' | 'ready' | 'empty' | 'error' = chats.isLoading
        ? 'loading'
        : chats.isError
          ? 'error'
          : (chats.data?.data.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    const rows = chats.data?.data ?? [];
    const meta = chats.data?.meta;

    return (
        <div
            data-testid="chat-logs"
            data-state={state}
            style={{ display: 'flex', flexDirection: 'column', gap: 12, padding: '12px 0' }}
        >
            <div
                data-testid="chat-logs-filters"
                style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}
            >
                <FilterInput
                    testid="chat-filter-project"
                    label="Project"
                    value={project}
                    onChange={setProject}
                    placeholder="hr-portal"
                />
                <FilterInput
                    testid="chat-filter-model"
                    label="Model"
                    value={model}
                    onChange={setModel}
                    placeholder="gpt-4o"
                />
                <FilterInput
                    testid="chat-filter-min-latency"
                    label="Min latency (ms)"
                    value={minLatency}
                    onChange={setMinLatency}
                    placeholder="500"
                    numeric
                />
                <FilterInput
                    testid="chat-filter-min-tokens"
                    label="Min tokens"
                    value={minTokens}
                    onChange={setMinTokens}
                    placeholder="100"
                    numeric
                />
                <FilterInput
                    testid="chat-filter-from"
                    label="From"
                    value={from}
                    onChange={setFrom}
                    placeholder="YYYY-MM-DD"
                />
                <FilterInput
                    testid="chat-filter-to"
                    label="To"
                    value={to}
                    onChange={setTo}
                    placeholder="YYYY-MM-DD"
                />
            </div>

            {state === 'error' ? (
                <div
                    data-testid="chat-logs-error"
                    style={{ padding: 12, color: 'var(--danger-fg, #b91c1c)', fontSize: 13 }}
                >
                    Failed to load chat logs. Try again.
                </div>
            ) : null}
            {state === 'loading' ? (
                <div
                    data-testid="chat-logs-loading"
                    style={{ padding: 12, color: 'var(--fg-3)', fontSize: 13 }}
                >
                    Loading chat logs…
                </div>
            ) : null}
            {state === 'empty' ? (
                <div
                    data-testid="chat-logs-empty"
                    style={{ padding: 12, color: 'var(--fg-3)', fontSize: 13 }}
                >
                    No chat logs match the current filters.
                </div>
            ) : null}

            {state === 'ready' ? (
                <table
                    data-testid="chat-logs-table"
                    style={{
                        width: '100%',
                        borderCollapse: 'collapse',
                        fontSize: 12.5,
                        textAlign: 'left',
                    }}
                >
                    <thead>
                        <tr style={{ borderBottom: '1px solid var(--hairline)' }}>
                            <Th>#</Th>
                            <Th>Project</Th>
                            <Th>Model</Th>
                            <Th>Question</Th>
                            <Th>Tokens</Th>
                            <Th>Latency</Th>
                            <Th>When</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.id}
                                data-testid={`chat-log-row-${row.id}`}
                                onClick={() => setDrawerId(row.id)}
                                style={{
                                    borderBottom: '1px solid var(--hairline)',
                                    cursor: 'pointer',
                                }}
                            >
                                <Td>{row.id}</Td>
                                <Td>{row.project_key ?? '—'}</Td>
                                <Td>{row.ai_model}</Td>
                                <Td>{truncate(row.question, 80)}</Td>
                                <Td>{row.total_tokens ?? '—'}</Td>
                                <Td>{row.latency_ms} ms</Td>
                                <Td>{formatDate(row.created_at)}</Td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : null}

            {meta && meta.last_page > 1 ? (
                <Pagination
                    meta={meta}
                    onPage={(next) => setPage(next)}
                    testidPrefix="chat-logs"
                />
            ) : null}

            {drawerId !== null ? (
                <ChatLogDrawer id={drawerId} onClose={() => setDrawerId(null)} />
            ) : null}
        </div>
    );
}

function ChatLogDrawer({ id, onClose }: { id: number; onClose: () => void }) {
    const q = useChatLog(id);
    const row: ChatLogRow | undefined = q.data?.data;

    return (
        <div
            data-testid="chat-log-drawer"
            role="dialog"
            aria-label="Chat log detail"
            style={{
                position: 'fixed',
                top: 0,
                right: 0,
                width: 520,
                height: '100vh',
                background: 'var(--bg-1)',
                borderLeft: '1px solid var(--hairline)',
                padding: 20,
                overflow: 'auto',
                zIndex: 50,
                boxShadow: '0 0 24px rgba(0,0,0,0.2)',
            }}
        >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <h2 style={{ fontSize: 14, fontWeight: 600, margin: 0 }}>Chat log #{id}</h2>
                <button
                    type="button"
                    data-testid="chat-log-drawer-close"
                    onClick={onClose}
                    style={{
                        background: 'transparent',
                        border: 'none',
                        fontSize: 18,
                        color: 'var(--fg-3)',
                        cursor: 'pointer',
                    }}
                >
                    ×
                </button>
            </div>

            {q.isLoading ? <div style={{ color: 'var(--fg-3)', padding: 10 }}>Loading…</div> : null}
            {q.isError ? (
                <div style={{ color: 'var(--danger-fg, #b91c1c)', padding: 10 }}>
                    Failed to load chat log.
                </div>
            ) : null}
            {row ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14, marginTop: 16 }}>
                    <DrawerField label="Provider / Model">
                        {row.ai_provider} — {row.ai_model}
                    </DrawerField>
                    <DrawerField label="Tokens (prompt / completion / total)">
                        {row.prompt_tokens ?? '—'} / {row.completion_tokens ?? '—'} /{' '}
                        {row.total_tokens ?? '—'}
                    </DrawerField>
                    <DrawerField label="Latency">{row.latency_ms} ms</DrawerField>
                    <DrawerField label="Question">
                        <pre
                            style={{
                                fontSize: 12,
                                whiteSpace: 'pre-wrap',
                                background: 'var(--bg-0)',
                                padding: 8,
                                borderRadius: 6,
                                border: '1px solid var(--hairline)',
                            }}
                        >
                            {row.question}
                        </pre>
                    </DrawerField>
                    <DrawerField label="Answer">
                        <pre
                            style={{
                                fontSize: 12,
                                whiteSpace: 'pre-wrap',
                                background: 'var(--bg-0)',
                                padding: 8,
                                borderRadius: 6,
                                border: '1px solid var(--hairline)',
                            }}
                        >
                            {row.answer}
                        </pre>
                    </DrawerField>
                    <DrawerField label="Citations">
                        <pre
                            style={{
                                fontSize: 11,
                                background: 'var(--bg-0)',
                                padding: 8,
                                borderRadius: 6,
                                border: '1px solid var(--hairline)',
                            }}
                        >
                            {JSON.stringify(row.sources ?? [], null, 2)}
                        </pre>
                    </DrawerField>
                </div>
            ) : null}
        </div>
    );
}

function DrawerField({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <div
                style={{
                    fontSize: 10.5,
                    color: 'var(--fg-3)',
                    textTransform: 'uppercase',
                    letterSpacing: '0.04em',
                    marginBottom: 4,
                }}
            >
                {label}
            </div>
            <div style={{ fontSize: 12.5, color: 'var(--fg-1)' }}>{children}</div>
        </div>
    );
}

function FilterInput({
    testid,
    label,
    value,
    onChange,
    placeholder,
    numeric,
}: {
    testid: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    numeric?: boolean;
}) {
    return (
        <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <span
                style={{
                    fontSize: 10.5,
                    color: 'var(--fg-3)',
                    textTransform: 'uppercase',
                    letterSpacing: '0.04em',
                }}
            >
                {label}
            </span>
            <input
                data-testid={testid}
                type={numeric ? 'number' : 'text'}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                style={{
                    padding: '6px 8px',
                    fontSize: 12.5,
                    background: 'var(--bg-0)',
                    border: '1px solid var(--hairline)',
                    borderRadius: 6,
                    color: 'var(--fg-1)',
                    minWidth: 120,
                }}
            />
        </label>
    );
}

function Pagination({
    meta,
    onPage,
    testidPrefix,
}: {
    meta: { current_page: number; last_page: number; total: number };
    onPage: (p: number) => void;
    testidPrefix: string;
}) {
    return (
        <div
            data-testid={`${testidPrefix}-pagination`}
            style={{
                display: 'flex',
                gap: 8,
                alignItems: 'center',
                fontSize: 12,
                color: 'var(--fg-3)',
            }}
        >
            <button
                type="button"
                data-testid={`${testidPrefix}-pagination-prev`}
                disabled={meta.current_page <= 1}
                onClick={() => onPage(meta.current_page - 1)}
            >
                ← Prev
            </button>
            <span>
                Page {meta.current_page} / {meta.last_page} ({meta.total} rows)
            </span>
            <button
                type="button"
                data-testid={`${testidPrefix}-pagination-next`}
                disabled={meta.current_page >= meta.last_page}
                onClick={() => onPage(meta.current_page + 1)}
            >
                Next →
            </button>
        </div>
    );
}

function Th({ children }: { children: React.ReactNode }) {
    return (
        <th
            style={{
                textAlign: 'left',
                padding: '6px 8px',
                fontSize: 11,
                color: 'var(--fg-3)',
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
                fontWeight: 500,
            }}
        >
            {children}
        </th>
    );
}

function Td({ children }: { children: React.ReactNode }) {
    return <td style={{ padding: '6px 8px', color: 'var(--fg-1)' }}>{children}</td>;
}

function truncate(s: string, max: number): string {
    if (s.length <= max) return s;
    return s.slice(0, max - 1) + '…';
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}
