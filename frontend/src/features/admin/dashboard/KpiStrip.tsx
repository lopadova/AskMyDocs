import { KpiCard, type KpiState } from './KpiCard';
import type { AdminKpiOverview } from '../admin.api';

export interface KpiStripProps {
    overview: AdminKpiOverview | null;
    state: KpiState;
}

function formatNumber(n: number): string {
    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toFixed(1)}M`;
    }
    if (n >= 1_000) {
        return `${(n / 1_000).toFixed(1)}k`;
    }
    return n.toString();
}

function formatMs(ms: number): string {
    if (ms <= 0) {
        return '—';
    }
    if (ms >= 1000) {
        return `${(ms / 1000).toFixed(2)}s`;
    }
    return `${ms} ms`;
}

/**
 * Six KPI tiles: docs, chunks, chats 24h (the chat metric is windowed
 * server-side so the label is approximate — the brief asked for
 * `chats 24h` but the backend groups by `days=7` by default; we show
 * `total_chats` with the current window from the hook).
 */
export function KpiStrip({ overview, state }: KpiStripProps) {
    const o = overview;
    return (
        <div
            data-testid="kpi-strip"
            data-state={state}
            style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))',
                gap: 10,
                marginBottom: 18,
            }}
        >
            <KpiCard
                slug="docs"
                icon="Book"
                label="Docs"
                value={o ? formatNumber(o.total_docs) : '—'}
                hint={o && o.canonical_coverage_pct > 0 ? `${o.canonical_coverage_pct}% canonical` : null}
                state={state}
            />
            <KpiCard
                slug="chunks"
                icon="Grid"
                label="Chunks"
                value={o ? formatNumber(o.total_chunks) : '—'}
                hint={o ? `${o.storage_used_mb.toFixed(1)} MB` : null}
                state={state}
            />
            <KpiCard
                slug="chats"
                icon="Chat"
                label="Chats (window)"
                value={o ? formatNumber(o.total_chats) : '—'}
                state={state}
            />
            <KpiCard
                slug="latency"
                icon="Clock"
                label="Avg latency"
                value={o ? formatMs(o.avg_latency_ms) : '—'}
                state={state}
            />
            <KpiCard
                slug="cache"
                icon="Sparkles"
                label="Cache hit"
                value={o ? `${o.cache_hit_rate.toFixed(1)}%` : '—'}
                state={state}
            />
            <KpiCard
                slug="coverage"
                icon="Shield"
                label="Canonical"
                value={o ? `${o.canonical_coverage_pct.toFixed(1)}%` : '—'}
                hint={o ? `${formatNumber(o.pending_jobs)} pending · ${formatNumber(o.failed_jobs)} failed` : null}
                state={state}
            />
        </div>
    );
}
