import { Fragment, useState } from 'react';
import { useFailedJobs } from './logs.api';

/*
 * Phase H1 — admin Log Viewer, Failed Jobs tab.
 *
 * Reads /api/admin/logs/failed-jobs. READ-ONLY: no retry / forget
 * buttons here — those are write-path actions that ship in H2 under
 * the maintenance wizard, logged via AdminCommandAudit (H2 table).
 *
 * Rows expand inline to show the full exception trace. The payload's
 * parsed `display_name` (e.g. `App\Jobs\IngestDocumentJob`) is shown
 * in the table header for quick scanning.
 */

export function FailedJobsTab() {
    const [page, setPage] = useState(1);
    const [expandedId, setExpandedId] = useState<number | null>(null);

    const q = useFailedJobs({ page });
    const notInstalled = q.data?.note === 'failed_jobs table not installed';

    // Copilot #11 fix: keep `data-state` inside the project-wide
    // `{idle, loading, ready, empty, error}` contract — "not-installed"
    // is a business state, not an async state. Surfaced via a
    // dedicated `data-failed-jobs-installed` attribute so E2E can
    // assert the install hint separately.
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
            data-testid="failed-jobs"
            data-state={state}
            data-failed-jobs-installed={notInstalled ? 'false' : 'true'}
            style={{ display: 'flex', flexDirection: 'column', gap: 12, padding: '12px 0' }}
        >
            {state === 'loading' ? (
                <div data-testid="failed-jobs-loading" style={{ padding: 12, color: 'var(--fg-3)' }}>
                    Loading failed jobs…
                </div>
            ) : null}
            {state === 'error' ? (
                <div
                    data-testid="failed-jobs-error"
                    style={{ padding: 12, color: 'var(--danger-fg, #b91c1c)' }}
                >
                    Failed to load failed jobs.
                </div>
            ) : null}
            {notInstalled ? (
                <div
                    data-testid="failed-jobs-not-installed"
                    style={{
                        padding: 16,
                        border: '1px dashed var(--hairline)',
                        borderRadius: 8,
                        fontSize: 13,
                        color: 'var(--fg-2)',
                    }}
                >
                    failed_jobs table not migrated. Run{' '}
                    <code>php artisan queue:failed-table &amp;&amp; php artisan migrate</code>.
                </div>
            ) : null}
            {state === 'empty' ? (
                <div data-testid="failed-jobs-empty" style={{ padding: 12, color: 'var(--fg-3)' }}>
                    No failed jobs — queue is clean.
                </div>
            ) : null}

            {state === 'ready' ? (
                <table
                    data-testid="failed-jobs-table"
                    style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}
                >
                    <thead>
                        <tr style={{ borderBottom: '1px solid var(--hairline)' }}>
                            <Th>#</Th>
                            <Th>Queue</Th>
                            <Th>Job</Th>
                            <Th>Attempts</Th>
                            <Th>Failed at</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            // Copilot #10 fix: key must be on the
                            // Fragment itself when mapping to siblings.
                            <Fragment key={row.id}>
                                <tr
                                    data-testid={`failed-job-row-${row.id}`}
                                    onClick={() =>
                                        setExpandedId(expandedId === row.id ? null : row.id)
                                    }
                                    style={{
                                        borderBottom: '1px solid var(--hairline)',
                                        cursor: 'pointer',
                                    }}
                                >
                                    <Td>{row.id}</Td>
                                    <Td>{row.queue ?? '—'}</Td>
                                    <Td>{row.display_name ?? row.job_class ?? '—'}</Td>
                                    <Td>{row.attempts ?? '—'}</Td>
                                    <Td>{row.failed_at ?? '—'}</Td>
                                </tr>
                                {expandedId === row.id ? (
                                    <tr
                                        data-testid={`failed-job-row-${row.id}-expanded`}
                                        style={{ background: 'var(--bg-0)' }}
                                    >
                                        <td colSpan={5} style={{ padding: 10 }}>
                                            <div style={{ ...labelStyle, marginBottom: 4 }}>
                                                uuid
                                            </div>
                                            <code style={{ fontSize: 11 }}>{row.uuid ?? '—'}</code>
                                            <div style={{ ...labelStyle, margin: '10px 0 4px' }}>
                                                exception
                                            </div>
                                            <pre
                                                style={{
                                                    margin: 0,
                                                    padding: 10,
                                                    fontSize: 11,
                                                    fontFamily: 'var(--font-mono)',
                                                    background: 'var(--bg-1)',
                                                    border: '1px solid var(--hairline)',
                                                    borderRadius: 4,
                                                    maxHeight: 280,
                                                    overflow: 'auto',
                                                    whiteSpace: 'pre-wrap',
                                                }}
                                            >
                                                {row.exception ?? '(empty)'}
                                            </pre>
                                        </td>
                                    </tr>
                                ) : null}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            ) : null}

            {meta && meta.last_page > 1 ? (
                <Pagination
                    meta={meta}
                    onPage={(next) => setPage(next)}
                    testidPrefix="failed-jobs"
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
            {/*
              Copilot #2 fix: add stable testids on prev/next actionable
              buttons so Playwright + Vitest can key off them consistently
              with ChatLogsTab's pagination (R11).
            */}
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
