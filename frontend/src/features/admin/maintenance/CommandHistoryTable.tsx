import { Fragment, useState } from 'react';
import { useCommandHistory, type HistoryRow } from './maintenance.api';

/*
 * Paginated, filterable audit history table. Rows expand inline to
 * show stdout_head / error_message.
 */
export function CommandHistoryTable() {
    const [page, setPage] = useState(1);
    const [cmdFilter, setCmdFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});

    const q = useCommandHistory({
        command: cmdFilter || undefined,
        status: statusFilter || undefined,
        page,
    });

    const state: 'loading' | 'empty' | 'ready' | 'error' = q.isLoading
        ? 'loading'
        : q.isError
          ? 'error'
          : (q.data?.data.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    return (
        <div data-testid="command-history" data-state={state} style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <span style={labelStyle}>Command</span>
                    <input
                        data-testid="history-filter-command"
                        value={cmdFilter}
                        onChange={(e) => {
                            setCmdFilter(e.target.value);
                            setPage(1);
                        }}
                        style={inputStyle}
                    />
                </label>
                <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <span style={labelStyle}>Status</span>
                    <select
                        data-testid="history-filter-status"
                        value={statusFilter}
                        onChange={(e) => {
                            setStatusFilter(e.target.value);
                            setPage(1);
                        }}
                        style={inputStyle}
                    >
                        <option value="">any</option>
                        <option value="started">started</option>
                        <option value="completed">completed</option>
                        <option value="failed">failed</option>
                        <option value="rejected">rejected</option>
                    </select>
                </label>
            </div>

            {state === 'loading' ? (
                <div style={{ fontSize: 12, color: 'var(--fg-3)' }}>Loading…</div>
            ) : null}
            {state === 'empty' ? (
                <div style={{ fontSize: 12, color: 'var(--fg-3)' }}>No matching history rows.</div>
            ) : null}
            {state === 'error' ? (
                <div
                    data-testid="command-history-error"
                    style={{ fontSize: 12, color: 'var(--danger-fg, #b91c1c)' }}
                >
                    Failed to load command history.
                </div>
            ) : null}

            {state === 'ready' ? (
                <div style={{ border: '1px solid var(--hairline)', borderRadius: 8, overflow: 'hidden' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                        <thead style={{ background: 'var(--bg-1)' }}>
                            <tr>
                                <th style={thStyle}>Time</th>
                                <th style={thStyle}>Command</th>
                                <th style={thStyle}>Status</th>
                                <th style={thStyle}>Exit</th>
                                <th style={thStyle}>Duration</th>
                                <th style={thStyle}></th>
                            </tr>
                        </thead>
                        <tbody>
                            {q.data!.data.map((row: HistoryRow) => {
                                const isOpen = Boolean(expanded[row.id]);
                                const ms =
                                    row.started_at && row.completed_at
                                        ? new Date(row.completed_at).getTime() - new Date(row.started_at).getTime()
                                        : null;
                                return (
                                    // Copilot #4 fix: key on the Fragment,
                                    // not on the inner <tr>. React requires
                                    // the array element to carry the key;
                                    // on the inner <tr> alone React warns
                                    // + reconciles rows unstably.
                                    <Fragment key={row.id}>
                                        <tr
                                            data-testid={`command-history-row-${row.id}`}
                                            data-status={row.status}
                                        >
                                            <td style={tdStyle}>{row.started_at ?? '—'}</td>
                                            <td style={{ ...tdStyle, fontFamily: 'var(--font-mono)' }}>{row.command}</td>
                                            <td style={tdStyle}>{row.status}</td>
                                            <td style={tdStyle}>{row.exit_code ?? '—'}</td>
                                            <td style={tdStyle}>{ms !== null ? `${ms} ms` : '—'}</td>
                                            <td style={tdStyle}>
                                                <button
                                                    type="button"
                                                    onClick={() => setExpanded({ ...expanded, [row.id]: !isOpen })}
                                                    style={{
                                                        padding: '3px 8px',
                                                        fontSize: 11,
                                                        background: 'transparent',
                                                        border: '1px solid var(--hairline)',
                                                        borderRadius: 4,
                                                        cursor: 'pointer',
                                                    }}
                                                >
                                                    {isOpen ? 'Hide' : 'Details'}
                                                </button>
                                            </td>
                                        </tr>
                                        {isOpen ? (
                                            <tr>
                                                <td colSpan={6} style={{ ...tdStyle, background: 'var(--bg-0)' }}>
                                                    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                                                        <div style={{ fontSize: 11, color: 'var(--fg-3)' }}>
                                                            args: <code>{JSON.stringify(row.args_json)}</code>
                                                        </div>
                                                        {row.error_message ? (
                                                            <div
                                                                style={{
                                                                    fontSize: 11,
                                                                    color: 'var(--danger-fg, #b91c1c)',
                                                                }}
                                                            >
                                                                {row.error_message}
                                                            </div>
                                                        ) : null}
                                                        {row.stdout_head ? (
                                                            <pre
                                                                style={{
                                                                    fontSize: 11,
                                                                    padding: 6,
                                                                    background: 'var(--bg-1)',
                                                                    border: '1px solid var(--hairline)',
                                                                    borderRadius: 4,
                                                                    margin: 0,
                                                                    maxHeight: 200,
                                                                    overflow: 'auto',
                                                                }}
                                                            >
                                                                {row.stdout_head}
                                                            </pre>
                                                        ) : null}
                                                    </div>
                                                </td>
                                            </tr>
                                        ) : null}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {q.data && q.data.meta.last_page > 1 ? (
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 12 }}>
                    <button
                        type="button"
                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                        disabled={page <= 1}
                        style={paginationBtnStyle}
                    >
                        ‹
                    </button>
                    <span>
                        {q.data.meta.current_page} / {q.data.meta.last_page}
                    </span>
                    <button
                        type="button"
                        onClick={() => setPage((p) => Math.min(q.data!.meta.last_page, p + 1))}
                        disabled={page >= q.data.meta.last_page}
                        style={paginationBtnStyle}
                    >
                        ›
                    </button>
                </div>
            ) : null}
        </div>
    );
}

const labelStyle: React.CSSProperties = {
    fontSize: 10.5,
    color: 'var(--fg-3)',
    textTransform: 'uppercase',
    letterSpacing: '0.04em',
};
const inputStyle: React.CSSProperties = {
    padding: '6px 8px',
    fontSize: 12.5,
    background: 'var(--bg-0)',
    border: '1px solid var(--hairline)',
    borderRadius: 6,
    color: 'var(--fg-1)',
    minWidth: 140,
};
const thStyle: React.CSSProperties = {
    textAlign: 'left',
    padding: '6px 10px',
    borderBottom: '1px solid var(--hairline)',
    fontSize: 11,
    textTransform: 'uppercase',
    letterSpacing: '0.04em',
    color: 'var(--fg-3)',
};
const tdStyle: React.CSSProperties = {
    padding: '6px 10px',
    borderBottom: '1px solid var(--hairline)',
};
const paginationBtnStyle: React.CSSProperties = {
    padding: '4px 10px',
    background: 'transparent',
    border: '1px solid var(--hairline)',
    borderRadius: 4,
    cursor: 'pointer',
};
