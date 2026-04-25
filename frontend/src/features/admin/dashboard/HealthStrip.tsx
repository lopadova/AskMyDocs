import type { AdminHealth, HealthStatus } from '../admin.api';

export interface HealthStripProps {
    health: AdminHealth | null;
    state: 'loading' | 'ready' | 'error';
}

type Concern = {
    key: keyof Omit<AdminHealth, 'checked_at'>;
    slug: string;
    label: string;
};

const CONCERNS: Concern[] = [
    { key: 'db_ok', slug: 'db', label: 'DB' },
    { key: 'pgvector_ok', slug: 'pgvector', label: 'pgvector' },
    { key: 'queue_ok', slug: 'queue', label: 'Queue' },
    { key: 'kb_disk_ok', slug: 'kb-disk', label: 'KB disk' },
    { key: 'embedding_provider_ok', slug: 'embeddings', label: 'Embeddings' },
    { key: 'chat_provider_ok', slug: 'chat', label: 'Chat AI' },
];

function rollup(statuses: HealthStatus[]): HealthStatus {
    if (statuses.some((s) => s === 'down')) {
        return 'down';
    }
    if (statuses.some((s) => s === 'degraded')) {
        return 'degraded';
    }
    return 'ok';
}

function chipColor(status: HealthStatus): { bg: string; border: string; fg: string } {
    if (status === 'down') {
        return { bg: 'rgba(239, 68, 68, 0.16)', border: 'rgba(239, 68, 68, 0.35)', fg: '#fca5a5' };
    }
    if (status === 'degraded') {
        return { bg: 'rgba(234, 179, 8, 0.16)', border: 'rgba(234, 179, 8, 0.35)', fg: '#fde68a' };
    }
    return { bg: 'rgba(34, 197, 94, 0.14)', border: 'rgba(34, 197, 94, 0.32)', fg: '#86efac' };
}

/**
 * Compact, always-visible health strip. `data-state` on the container
 * rolls up every concern (down > degraded > ok); each concern chip
 * carries its own `data-state` for finer-grained Playwright assertions.
 */
export function HealthStrip({ health, state }: HealthStripProps) {
    if (state === 'loading' || state === 'error' || !health) {
        return (
            <div
                data-testid="dashboard-health"
                data-state={state === 'error' ? 'down' : 'loading'}
                className="panel"
                style={{
                    padding: '10px 14px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    marginBottom: 18,
                    color: 'var(--fg-3)',
                    fontSize: 12,
                    fontFamily: 'var(--font-mono)',
                }}
            >
                {state === 'error' ? 'health probe failed' : <span className="shimmer">checking health…</span>}
            </div>
        );
    }

    const all = CONCERNS.map((c) => health[c.key]);
    const overall = rollup(all);

    return (
        <div
            data-testid="dashboard-health"
            data-state={overall}
            className="panel"
            style={{
                padding: '10px 14px',
                display: 'flex',
                flexWrap: 'wrap',
                alignItems: 'center',
                gap: 10,
                marginBottom: 18,
            }}
        >
            <span
                style={{
                    fontSize: 10.5,
                    color: 'var(--fg-3)',
                    fontFamily: 'var(--font-mono)',
                    textTransform: 'uppercase',
                    letterSpacing: '0.05em',
                }}
            >
                Health
            </span>
            {CONCERNS.map((c) => {
                const status = health[c.key];
                const c1 = chipColor(status);
                return (
                    <span
                        key={c.slug}
                        data-testid={`health-${c.slug}`}
                        data-state={status}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6,
                            padding: '3px 9px',
                            borderRadius: 999,
                            border: `1px solid ${c1.border}`,
                            background: c1.bg,
                            color: c1.fg,
                            fontSize: 11.5,
                            fontFamily: 'var(--font-mono)',
                        }}
                    >
                        <span
                            aria-hidden
                            style={{
                                width: 6,
                                height: 6,
                                borderRadius: '50%',
                                background: c1.fg,
                            }}
                        />
                        {c.label}
                    </span>
                );
            })}
            <span
                style={{
                    marginLeft: 'auto',
                    fontSize: 10.5,
                    color: 'var(--fg-3)',
                    fontFamily: 'var(--font-mono)',
                }}
            >
                checked {new Date(health.checked_at).toLocaleTimeString()}
            </span>
        </div>
    );
}
