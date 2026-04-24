import { Fragment, useState } from 'react';
import { useAuditLogs, type AuditLogsQuery } from './logs.api';

/*
 * Phase H1 — admin Log Viewer, Canonical Audit tab.
 *
 * Filter bar (project / event_type / actor) feeds the
 * /api/admin/logs/canonical-audit paginated read. Each row expands
 * inline to show before_json / after_json / metadata_json — the
 * forensic detail that makes the kb_canonical_audit trail useful.
 */

const EVENT_TYPES = [
    '',
    'promoted',
    'updated',
    'deprecated',
    'superseded',
    'rejected_injection_used',
    'graph_rebuild',
];

export function AuditTab() {
    const [project, setProject] = useState('');
    const [eventType, setEventType] = useState('');
    const [actor, setActor] = useState('');
    const [page, setPage] = useState(1);
    const [expandedId, setExpandedId] = useState<number | null>(null);

    const query: AuditLogsQuery = {
        project: project || undefined,
        event_type: eventType || undefined,
        actor: actor || undefined,
        page,
    };

    const auditQ = useAuditLogs(query);
    const state: 'loading' | 'ready' | 'empty' | 'error' = auditQ.isLoading
        ? 'loading'
        : auditQ.isError
          ? 'error'
          : (auditQ.data?.data.length ?? 0) === 0
            ? 'empty'
            : 'ready';

    const rows = auditQ.data?.data ?? [];
    const meta = auditQ.data?.meta;

    return (
        <div
            data-testid="audit-logs"
            data-state={state}
            style={{ display: 'flex', flexDirection: 'column', gap: 12, padding: '12px 0' }}
        >
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <span style={labelStyle}>Project</span>
                    <input
                        data-testid="audit-filter-project"
                        value={project}
                        onChange={(e) => setProject(e.target.value)}
                        placeholder="hr-portal"
                        style={inputStyle}
                    />
                </label>
                <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <span style={labelStyle}>Event type</span>
                    <select
                        data-testid="audit-filter-event-type"
                        value={eventType}
                        onChange={(e) => setEventType(e.target.value)}
                        style={inputStyle}
                    >
                        {EVENT_TYPES.map((et) => (
                            <option key={et} value={et}>
                                {et || 'any'}
                            </option>
                        ))}
                    </select>
                </label>
                <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <span style={labelStyle}>Actor</span>
                    <input
                        data-testid="audit-filter-actor"
                        value={actor}
                        onChange={(e) => setActor(e.target.value)}
                        placeholder="system"
                        style={inputStyle}
                    />
                </label>
            </div>

            {state === 'loading' ? (
                <div data-testid="audit-loading" style={{ padding: 12, color: 'var(--fg-3)' }}>
                    Loading audit trail…
                </div>
            ) : null}
            {state === 'error' ? (
                <div
                    data-testid="audit-error"
                    style={{ padding: 12, color: 'var(--danger-fg, #b91c1c)' }}
                >
                    Failed to load audit trail.
                </div>
            ) : null}
            {state === 'empty' ? (
                <div data-testid="audit-empty" style={{ padding: 12, color: 'var(--fg-3)' }}>
                    No audit rows match the current filters.
                </div>
            ) : null}

            {state === 'ready' ? (
                <table
                    data-testid="audit-table"
                    style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}
                >
                    <thead>
                        <tr style={{ borderBottom: '1px solid var(--hairline)' }}>
                            <Th>#</Th>
                            <Th>Project</Th>
                            <Th>Event</Th>
                            <Th>Actor</Th>
                            <Th>Slug / doc_id</Th>
                            <Th>When</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            // Copilot #9 fix: the row expansion renders
                            // two sibling <tr> elements. A bare `<>`
                            // fragment can't carry `key`, so React's
                            // reconciler was matching on the first <tr>
                            // only and warning about missing keys on the
                            // second. Named Fragment takes the key.
                            <Fragment key={row.id}>
                                <tr
                                    data-testid={`audit-row-${row.id}`}
                                    onClick={() =>
                                        setExpandedId(expandedId === row.id ? null : row.id)
                                    }
                                    style={{
                                        borderBottom: '1px solid var(--hairline)',
                                        cursor: 'pointer',
                                    }}
                                >
                                    <Td>{row.id}</Td>
                                    <Td>{row.project_key}</Td>
                                    <Td>{row.event_type}</Td>
                                    <Td>{row.actor}</Td>
                                    <Td>
                                        {row.slug ?? '—'}
                                        {row.doc_id ? ` (${row.doc_id})` : ''}
                                    </Td>
                                    <Td>{formatDate(row.created_at)}</Td>
                                </tr>
                                {expandedId === row.id ? (
                                    <tr
                                        data-testid={`audit-row-${row.id}-expanded`}
                                        style={{ background: 'var(--bg-0)' }}
                                    >
                                        <td
                                            colSpan={6}
                                            style={{
                                                padding: 10,
                                                fontSize: 11.5,
                                                fontFamily: 'var(--font-mono)',
                                            }}
                                        >
                                            <JsonBlock label="before_json" value={row.before_json} />
                                            <JsonBlock label="after_json" value={row.after_json} />
                                            <JsonBlock
                                                label="metadata_json"
                                                value={row.metadata_json}
                                            />
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
                    testidPrefix="audit"
                />
            ) : null}
        </div>
    );
}

function JsonBlock({ label, value }: { label: string; value: unknown }) {
    if (value === null || value === undefined) return null;
    return (
        <div style={{ marginBottom: 8 }}>
            <div
                style={{
                    fontSize: 10,
                    color: 'var(--fg-3)',
                    textTransform: 'uppercase',
                    letterSpacing: '0.04em',
                    marginBottom: 2,
                }}
            >
                {label}
            </div>
            <pre
                style={{
                    margin: 0,
                    padding: 6,
                    border: '1px solid var(--hairline)',
                    borderRadius: 4,
                    background: 'var(--bg-1)',
                    color: 'var(--fg-1)',
                    overflow: 'auto',
                }}
            >
                {JSON.stringify(value, null, 2)}
            </pre>
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
            {/* Copilot #13 fix: testid parity with ChatLogsTab (R11). */}
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
