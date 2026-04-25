import { useState } from 'react';
import { useActivityLogs, type ActivityLogsQuery } from './logs.api';

/*
 * Phase H1 — admin Log Viewer, Activity tab.
 *
 * Reads /api/admin/logs/activity. Spatie's activity_log is a soft
 * dependency: when the table is absent the backend returns a
 * well-shaped empty page with `note: 'activitylog not installed'` so
 * this tab falls back to an explanatory empty state instead of an
 * error.
 */

export function ActivityTab() {
    const [subjectType, setSubjectType] = useState('');
    const [subjectId, setSubjectId] = useState('');
    const [causerId, setCauserId] = useState('');
    const [page, setPage] = useState(1);

    const query: ActivityLogsQuery = {
        subject_type: subjectType || undefined,
        subject_id: subjectId === '' ? undefined : Number(subjectId),
        causer_id: causerId === '' ? undefined : Number(causerId),
        page,
    };

    const q = useActivityLogs(query);
    const notInstalled = q.data?.note === 'activitylog not installed';

    // Copilot #12 fix: keep `data-state` inside the codebase-wide
    // `{idle, loading, ready, empty, error}` contract so E2E
    // assertions stay uniform. `not-installed` is a BUSINESS state,
    // not an async state — it's surfaced via a dedicated
    // `data-activitylog-installed="false"` attribute + the existing
    // `activity-not-installed` testid. `data-state` flips to `empty`
    // while the dedicated hint element carries the install copy.
    const state: 'loading' | 'ready' | 'empty' | 'error' = q.isLoading
        ? 'loading'
        : q.isError
          ? 'error'
          : notInstalled
            ? 'empty'
            : (q.data?.data.length ?? 0) === 0
              ? 'empty'
              : 'ready';

    const rows = q.data?.data ?? [];
    const meta = q.data?.meta;

    return (
        <div
            data-testid="activity-logs"
            data-state={state}
            data-activitylog-installed={notInstalled ? 'false' : 'true'}
            style={{ display: 'flex', flexDirection: 'column', gap: 12, padding: '12px 0' }}
        >
            {notInstalled ? (
                <div
                    data-testid="activity-not-installed"
                    style={{
                        padding: 16,
                        border: '1px dashed var(--hairline)',
                        borderRadius: 8,
                        fontSize: 13,
                        color: 'var(--fg-2)',
                    }}
                >
                    <div style={{ marginBottom: 6, fontWeight: 600 }}>
                        Activity log not installed
                    </div>
                    <div>
                        Run <code>composer require spatie/laravel-activitylog</code> and{' '}
                        <code>php artisan migrate</code> to enable this tab.
                    </div>
                </div>
            ) : null}

            {!notInstalled ? (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                    <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                        <span style={labelStyle}>Subject type</span>
                        <input
                            data-testid="activity-filter-subject-type"
                            value={subjectType}
                            onChange={(e) => setSubjectType(e.target.value)}
                            placeholder="App\\Models\\User"
                            style={inputStyle}
                        />
                    </label>
                    <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                        <span style={labelStyle}>Subject id</span>
                        <input
                            data-testid="activity-filter-subject-id"
                            type="number"
                            value={subjectId}
                            onChange={(e) => setSubjectId(e.target.value)}
                            style={inputStyle}
                        />
                    </label>
                    <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                        <span style={labelStyle}>Causer id</span>
                        <input
                            data-testid="activity-filter-causer-id"
                            type="number"
                            value={causerId}
                            onChange={(e) => setCauserId(e.target.value)}
                            style={inputStyle}
                        />
                    </label>
                </div>
            ) : null}

            {state === 'loading' ? (
                <div data-testid="activity-loading" style={{ padding: 12, color: 'var(--fg-3)' }}>
                    Loading activity log…
                </div>
            ) : null}
            {state === 'error' ? (
                <div
                    data-testid="activity-error"
                    style={{ padding: 12, color: 'var(--danger-fg, #b91c1c)' }}
                >
                    Failed to load activity log.
                </div>
            ) : null}
            {state === 'empty' ? (
                <div data-testid="activity-empty" style={{ padding: 12, color: 'var(--fg-3)' }}>
                    No activity rows recorded yet.
                </div>
            ) : null}

            {state === 'ready' ? (
                <table
                    data-testid="activity-table"
                    style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}
                >
                    <thead>
                        <tr style={{ borderBottom: '1px solid var(--hairline)' }}>
                            <Th>#</Th>
                            <Th>Log</Th>
                            <Th>Description</Th>
                            <Th>Subject</Th>
                            <Th>Event</Th>
                            <Th>When</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.id}
                                data-testid={`activity-row-${row.id}`}
                                style={{ borderBottom: '1px solid var(--hairline)' }}
                            >
                                <Td>{row.id}</Td>
                                <Td>{row.log_name ?? '—'}</Td>
                                <Td>{row.description}</Td>
                                <Td>
                                    {row.subject_type ? row.subject_type.split('\\').pop() : '—'}
                                    {row.subject_id ? `#${row.subject_id}` : ''}
                                </Td>
                                <Td>{row.event ?? '—'}</Td>
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
                    testidPrefix="activity"
                />
            ) : null}
        </div>
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
            style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 12 }}
        >
            {/* Copilot #14 fix: testid parity with ChatLogsTab (R11). */}
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
function formatDate(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}
