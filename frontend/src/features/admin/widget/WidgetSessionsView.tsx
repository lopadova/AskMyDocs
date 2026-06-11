import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../../lib/api';

/**
 * WidgetSession row as returned by the admin list API.
 */
interface WidgetSessionRow {
    id: number;
    public_session_id: string;
    widget_key: { id: number; label: string; public_key: string } | null;
    status: string;
    skill: string | null;
    mission: string | null;
    origin: string | null;
    steps_count: number;
    created_at: string;
    updated_at: string;
}

interface WidgetSessionsResponse {
    data: WidgetSessionRow[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

interface SessionDetailStep {
    id: number;
    kind: string;
    tool: string | null;
    args_json: Record<string, unknown> | null;
    tokens_in: number | null;
    tokens_out: number | null;
    latency_ms: number | null;
    created_at: string;
}

interface SessionDetail {
    id: number;
    public_session_id: string;
    widget_key: { id: number; label: string; public_key: string } | null;
    status: string;
    skill: string | null;
    mission: string | null;
    page_url: string | null;
    origin: string | null;
    summary: string | null;
    blocked_reason: string | null;
    meta: Record<string, unknown> | null;
    steps: SessionDetailStep[];
    created_at: string;
    updated_at: string;
}

const STATUS_COLORS: Record<string, { bg: string; fg: string }> = {
    active: { bg: '#e6f9e6', fg: '#0a7a0a' },
    completed: { bg: '#e0e7ff', fg: '#3730a3' },
    blocked: { bg: '#fde8e8', fg: '#c41e1e' },
    aborted: { bg: '#fef3c7', fg: '#92400e' },
    error: { bg: '#fde8e8', fg: '#c41e1e' },
    waiting_user: { bg: '#fef3c7', fg: '#92400e' },
    waiting_tool: { bg: '#e0e7ff', fg: '#3730a3' },
};

/**
 * M6.3 — Widget session inspector admin view.
 *
 * List sessions with filters, click to expand detail with steps.
 * R11 testids, R15 a11y, R14 states.
 */
export function WidgetSessionsView() {
    // #36 — niente più stato morto `_filterKeyId` (mai impostabile dalla UI).
    // Il filtro per key resta supportato dal BE e potrà essere ricablato a una
    // dropdown dedicata; finché non c'è il controllo, non portiamo stato inerte.
    const [filterStatus, setFilterStatus] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(null);

    const sessions = useQuery({
        queryKey: ['admin-widget-sessions', filterStatus],
        queryFn: async () => {
            const params = new URLSearchParams();
            if (filterStatus) params.set('status', filterStatus);
            const qs = params.toString();
            const { data } = await api.get<WidgetSessionsResponse>(`/api/admin/widget-sessions${qs ? `?${qs}` : ''}`);
            return data;
        },
    });

    const detail = useQuery({
        queryKey: ['admin-widget-session-detail', selectedId],
        queryFn: async () => {
            if (selectedId === null) return null;
            const { data } = await api.get<{ data: SessionDetail }>(`/api/admin/widget-sessions/${selectedId}`);
            return data.data;
        },
        enabled: selectedId !== null,
    });

    return (
        <section data-testid="admin-widget-sessions-view" style={{ display: 'grid', gap: 14 }}>
            <header>
                <h1 style={{ margin: 0, fontSize: 22 }}>Widget Sessions</h1>
                <p style={{ marginTop: 6, color: 'var(--fg-2)' }}>
                    Inspect active and past KITT widget sessions within your tenant.
                </p>
            </header>

            {/* Filters */}
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                <select
                    data-testid="admin-widget-sessions-filter-status"
                    value={filterStatus}
                    onChange={(e) => setFilterStatus(e.target.value)}
                    aria-label="Filter by status"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    {/* #33 — il dominio reale ha 7 stati: waiting_tool/waiting_user
                        mancavano, rendendo non-filtrabili proprio le sessioni
                        "appese" che l'operatore deve cercare. */}
                    <option value="waiting_user">Waiting user</option>
                    <option value="waiting_tool">Waiting tool</option>
                    <option value="completed">Completed</option>
                    <option value="blocked">Blocked</option>
                    <option value="aborted">Aborted</option>
                    <option value="error">Error</option>
                </select>
            </div>

            {/* States (R14) */}
            {sessions.isLoading && (
                <div data-testid="admin-widget-sessions-loading" style={{ color: 'var(--fg-2)' }}>
                    Loading sessions…
                </div>
            )}
            {sessions.isError && (
                <div data-testid="admin-widget-sessions-error" style={{ color: 'var(--color-danger)' }} role="alert">
                    Failed to load sessions.
                </div>
            )}

            {/* Session list */}
            {sessions.data && sessions.data.data.length === 0 && (
                <div data-testid="admin-widget-sessions-empty" style={{ color: 'var(--fg-2)' }}>
                    No sessions found.
                </div>
            )}

            {sessions.data && sessions.data.data.length > 0 && (
                <>
                    <table data-testid="admin-widget-sessions-table" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                        <thead>
                            <tr style={{ borderBottom: '1px solid var(--hairline)', textAlign: 'left' }}>
                                <th>Key</th>
                                <th>Status</th>
                                <th>Skill</th>
                                <th>Mission</th>
                                <th>Origin</th>
                                <th>Steps</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sessions.data.data.map((s) => {
                                const sc = STATUS_COLORS[s.status] ?? { bg: '#f3f4f6', fg: '#374151' };
                                return (
                                    <tr
                                        key={s.id}
                                        data-testid={`admin-widget-sessions-row-${s.id}`}
                                        style={{
                                            borderBottom: '1px solid var(--hairline)',
                                            cursor: 'pointer',
                                            background: selectedId === s.id ? 'var(--bg-2)' : undefined,
                                        }}
                                        onClick={() => setSelectedId(selectedId === s.id ? null : s.id)}
                                    >
                                        <td>{s.widget_key?.label ?? '—'}</td>
                                        <td>
                                            <span
                                                data-testid={`admin-widget-sessions-status-${s.id}`}
                                                style={{
                                                    padding: '2px 8px',
                                                    borderRadius: 4,
                                                    fontSize: 11,
                                                    background: sc.bg,
                                                    color: sc.fg,
                                                }}
                                            >
                                                {s.status}
                                            </span>
                                        </td>
                                        <td>{s.skill ?? '—'}</td>
                                        <td>{s.mission ?? '—'}</td>
                                        <td style={{ fontSize: 11, maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                            {s.origin ?? '—'}
                                        </td>
                                        <td>{s.steps_count}</td>
                                        <td>{new Date(s.created_at).toLocaleDateString()}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    {sessions.data.meta.total > sessions.data.meta.per_page && (
                        <div data-testid="admin-widget-sessions-pagination" style={{ color: 'var(--fg-2)', fontSize: 12 }}>
                            Page {sessions.data.meta.current_page} of {sessions.data.meta.last_page} ({sessions.data.meta.total} total)
                        </div>
                    )}
                </>
            )}

            {/* Session detail panel */}
            {selectedId !== null && (
                <div data-testid="admin-widget-session-detail" style={{ border: '1px solid var(--hairline)', borderRadius: 6, padding: 14 }}>
                    {detail.isLoading && <span style={{ color: 'var(--fg-2)' }}>Loading detail…</span>}
                    {detail.isError && <span style={{ color: 'var(--color-danger)' }} role="alert">Failed to load detail.</span>}
                    {detail.data && (
                        <>
                            <h2 style={{ margin: 0, fontSize: 16 }}>
                                Session {detail.data.public_session_id.slice(0, 8)}…
                            </h2>
                            <dl style={{ display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '4px 12px', fontSize: 13, marginTop: 10 }}>
                                <dt>Status</dt>
                                <dd>{detail.data.status}</dd>
                                <dt>Key</dt>
                                <dd>{detail.data.widget_key?.label ?? '—'}</dd>
                                <dt>Mission</dt>
                                <dd>{detail.data.mission ?? '—'}</dd>
                                <dt>Page URL</dt>
                                <dd style={{ wordBreak: 'break-all' }}>{detail.data.page_url ?? '—'}</dd>
                                <dt>Summary</dt>
                                <dd>{detail.data.summary ?? '—'}</dd>
                                {detail.data.blocked_reason && (
                                    <>
                                        <dt>Blocked Reason</dt>
                                        <dd style={{ color: 'var(--color-danger)' }}>{detail.data.blocked_reason}</dd>
                                    </>
                                )}
                            </dl>

                            <h3 style={{ marginTop: 14, fontSize: 14 }}>Steps ({detail.data.steps.length})</h3>
                            {detail.data.steps.length === 0 ? (
                                <p style={{ color: 'var(--fg-2)' }}>No steps recorded.</p>
                            ) : (
                                <ol style={{ fontSize: 12, paddingLeft: 20 }}>
                                    {detail.data.steps.map((step) => (
                                        <li key={step.id} data-testid={`admin-widget-session-step-${step.id}`}>
                                            <strong>{step.kind}</strong>
                                            {step.tool && <span style={{ color: 'var(--fg-2)' }}> — {step.tool}</span>}
                                            {step.tokens_in !== null && step.tokens_out !== null && (
                                                <span style={{ color: 'var(--fg-2)' }}>
                                                    {' '}({step.tokens_in}→{step.tokens_out} tokens, {step.latency_ms}ms)
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </>
                    )}
                </div>
            )}
        </section>
    );
}