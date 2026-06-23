import { useMemo, useState } from 'react';
import { AdminShell } from '../shell/AdminShell';
import { useConnectors } from '../connectors/connectors-hooks';
import { useQueueDepths, useSyncRuns } from './ingestion-hooks';
import type { SyncRunStatus } from './ingestion.api';

/*
 * v8.21 (Ciclo 2) — "Ingestion & Sync" admin screen.
 *
 * Two read-only panels over the host-side observability surfaces:
 *   1. Queue depths (connector-sync / kb-ingest / default), polled.
 *   2. Per-account sync history — pick an installed account, see its runs.
 *
 * R11/R29 testids, R14 explicit empty/loading/error, R15 labelled controls.
 */

interface AccountOption {
    installationId: number;
    connectorKey: string;
    label: string;
}

function statusColor(status: SyncRunStatus): { bg: string; border: string; fg: string } {
    switch (status) {
        case 'success':
            return { bg: 'rgba(16,185,129,0.16)', border: 'rgba(16,185,129,0.45)', fg: '#34d399' };
        case 'running':
            return { bg: 'rgba(250,204,21,0.16)', border: 'rgba(250,204,21,0.45)', fg: '#fbbf24' };
        case 'partial':
            return { bg: 'rgba(249,115,22,0.16)', border: 'rgba(249,115,22,0.45)', fg: '#fb923c' };
        case 'failed':
            return { bg: 'rgba(239,68,68,0.16)', border: 'rgba(239,68,68,0.45)', fg: '#fca5a5' };
        default:
            return { bg: 'rgba(148,163,184,0.12)', border: 'rgba(148,163,184,0.30)', fg: '#94a3b8' };
    }
}

export function IngestionView() {
    const connectorsQuery = useConnectors();
    const queueQuery = useQueueDepths();

    const accounts = useMemo<AccountOption[]>(() => {
        const out: AccountOption[] = [];
        for (const entry of connectorsQuery.data ?? []) {
            for (const inst of entry.installations ?? []) {
                out.push({ installationId: inst.id, connectorKey: entry.key, label: inst.label });
            }
        }
        return out;
    }, [connectorsQuery.data]);

    const connectorsLoaded = !connectorsQuery.isLoading && !connectorsQuery.isError;

    const [selectedId, setSelectedId] = useState<number | null>(null);
    // Clamp to the CURRENT accounts: if the selected account was removed after a
    // connectors refetch, fall back to the first available one (or null) so the
    // runs query never keeps polling a stale installation id.
    const selectedStillExists =
        selectedId !== null && accounts.some((a) => a.installationId === selectedId);
    const effectiveId = (selectedStillExists ? selectedId : accounts[0]?.installationId) ?? null;
    const runsQuery = useSyncRuns(effectiveId);

    const state: 'loading' | 'ready' | 'error' = queueQuery.isLoading
        ? 'loading'
        : queueQuery.isError
          ? 'error'
          : 'ready';

    return (
        <AdminShell section="ingestion">
            <div
                data-testid="admin-ingestion"
                data-state={state}
                style={{ display: 'flex', flexDirection: 'column', gap: 16 }}
            >
                <div>
                    <h1 style={{ fontSize: 20, fontWeight: 600, margin: '0 0 2px', letterSpacing: '-0.02em', color: 'var(--fg-0)' }}>
                        Ingestion &amp; Sync
                    </h1>
                    <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                        Queue backlog and per-account connector sync history.
                    </p>
                </div>

                {/* ── Queue depths ─────────────────────────────────────────── */}
                <section aria-label="Queue depths">
                    {state === 'loading' && (
                        <div data-testid="admin-ingestion-queue-loading" role="status" aria-busy="true" style={panelStyle}>
                            Loading queues…
                        </div>
                    )}
                    {state === 'error' && (
                        <div data-testid="admin-ingestion-queue-error" role="alert" style={errorStyle}>
                            Could not load queue depths.{' '}
                            <button
                                type="button"
                                data-testid="admin-ingestion-queue-retry"
                                className="focus-ring"
                                onClick={() => queueQuery.refetch()}
                                style={retryStyle}
                            >
                                Retry
                            </button>
                        </div>
                    )}
                    {state === 'ready' && (queueQuery.data ?? []).length === 0 && (
                        <div data-testid="admin-ingestion-queues-empty" role="status" style={panelStyle}>
                            No queues reported — the queue driver may not expose depths.
                        </div>
                    )}
                    {state === 'ready' && (queueQuery.data ?? []).length > 0 && (
                        <div
                            data-testid="admin-ingestion-queues"
                            style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 10 }}
                        >
                            {(queueQuery.data ?? []).map((q) => (
                                <div
                                    key={q.role}
                                    data-testid={`ingestion-queue-${q.role}`}
                                    data-depth={q.depth ?? 'n/a'}
                                    style={{ padding: 12, borderRadius: 10, border: '1px solid var(--hairline)', background: 'var(--bg-1)' }}
                                >
                                    <div style={{ fontSize: 11, color: 'var(--fg-3)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                                        {q.role}
                                    </div>
                                    <div style={{ fontSize: 24, fontWeight: 600, color: 'var(--fg-0)' }}>
                                        {q.depth ?? '—'}
                                    </div>
                                    <div style={{ fontSize: 11, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>
                                        {q.name}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                {/* ── Per-account sync history ─────────────────────────────── */}
                <section aria-label="Sync history" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                        <label htmlFor="ingestion-account-select" style={{ fontSize: 12.5, color: 'var(--fg-2)' }}>
                            Account
                        </label>
                        <select
                            id="ingestion-account-select"
                            data-testid="ingestion-account-select"
                            value={effectiveId ?? ''}
                            onChange={(e) => setSelectedId(e.target.value === '' ? null : Number(e.target.value))}
                            disabled={accounts.length === 0}
                            style={selectStyle}
                        >
                            {connectorsQuery.isLoading && <option value="">Loading accounts…</option>}
                            {connectorsQuery.isError && <option value="">Couldn’t load accounts</option>}
                            {connectorsLoaded && accounts.length === 0 && (
                                <option value="">No connected accounts</option>
                            )}
                            {accounts.map((a) => (
                                <option key={a.installationId} value={a.installationId}>
                                    {a.connectorKey} · {a.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {connectorsQuery.isLoading ? (
                        <div data-testid="admin-ingestion-accounts-loading" role="status" aria-busy="true" style={panelStyle}>
                            Loading connectors…
                        </div>
                    ) : connectorsQuery.isError ? (
                        <div data-testid="admin-ingestion-accounts-error" role="alert" style={errorStyle}>
                            Could not load connectors.{' '}
                            <button
                                type="button"
                                data-testid="admin-ingestion-accounts-retry"
                                className="focus-ring"
                                onClick={() => connectorsQuery.refetch()}
                                style={retryStyle}
                            >
                                Retry
                            </button>
                        </div>
                    ) : accounts.length === 0 ? (
                        <div data-testid="admin-ingestion-no-accounts" role="status" style={panelStyle}>
                            No connectors are installed yet — connect one to see its sync history.
                        </div>
                    ) : runsQuery.isLoading ? (
                        <div data-testid="admin-ingestion-runs-loading" role="status" aria-busy="true" style={panelStyle}>
                            Loading sync runs…
                        </div>
                    ) : runsQuery.isError ? (
                        <div data-testid="admin-ingestion-runs-error" role="alert" style={errorStyle}>
                            Could not load sync runs.{' '}
                            <button
                                type="button"
                                data-testid="admin-ingestion-runs-retry"
                                className="focus-ring"
                                onClick={() => runsQuery.refetch()}
                                style={retryStyle}
                            >
                                Retry
                            </button>
                        </div>
                    ) : (runsQuery.data ?? []).length === 0 ? (
                        <div data-testid="admin-ingestion-runs-empty" role="status" style={panelStyle}>
                            No sync runs recorded for this account yet.
                        </div>
                    ) : (
                        <table data-testid="admin-ingestion-runs" style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
                            <thead>
                                <tr style={{ textAlign: 'left', color: 'var(--fg-3)' }}>
                                    <th style={thStyle}>Status</th>
                                    <th style={thStyle}>Discovered</th>
                                    <th style={thStyle}>Failed</th>
                                    <th style={thStyle}>Duration</th>
                                    <th style={thStyle}>Started</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(runsQuery.data ?? []).map((run) => {
                                    const c = statusColor(run.status);
                                    return (
                                        <tr key={run.id} data-testid={`ingestion-run-${run.id}`} data-status={run.status}>
                                            <td style={tdStyle}>
                                                <span style={{ padding: '2px 8px', borderRadius: 99, fontSize: 11, background: c.bg, border: `1px solid ${c.border}`, color: c.fg }}>
                                                    {run.status}
                                                </span>
                                            </td>
                                            <td style={tdStyle}>{run.items_discovered}</td>
                                            <td style={tdStyle}>{run.items_failed}</td>
                                            <td style={tdStyle}>{run.duration_ms !== null ? `${run.duration_ms} ms` : '—'}</td>
                                            <td style={tdStyle}>{run.started_at ?? '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </section>
            </div>
        </AdminShell>
    );
}

const panelStyle: React.CSSProperties = {
    padding: 24,
    textAlign: 'center',
    color: 'var(--fg-3)',
    border: '1px dashed var(--hairline)',
    borderRadius: 10,
};

const errorStyle: React.CSSProperties = {
    padding: 16,
    background: 'rgba(239, 68, 68, 0.08)',
    border: '1px solid rgba(239, 68, 68, 0.30)',
    borderRadius: 10,
    color: '#fca5a5',
    fontSize: 13,
};

const retryStyle: React.CSSProperties = {
    marginLeft: 8,
    padding: '4px 10px',
    fontSize: 12,
    background: 'transparent',
    color: '#fca5a5',
    border: '1px solid rgba(239, 68, 68, 0.45)',
    borderRadius: 6,
    cursor: 'pointer',
};

const selectStyle: React.CSSProperties = {
    padding: '5px 8px',
    borderRadius: 6,
    border: '1px solid var(--hairline)',
    background: 'var(--bg-2)',
    color: 'var(--fg-0)',
    fontSize: 12.5,
};

const thStyle: React.CSSProperties = { padding: '6px 8px', borderBottom: '1px solid var(--hairline)', fontWeight: 500 };
const tdStyle: React.CSSProperties = { padding: '6px 8px', borderBottom: '1px solid var(--hairline)', color: 'var(--fg-1)' };
