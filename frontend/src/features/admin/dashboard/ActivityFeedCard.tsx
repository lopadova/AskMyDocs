import type { AdminActivityRow } from '../admin.api';
import type { ChartState } from './ChartCard';

export interface ActivityFeedCardProps {
    rows: AdminActivityRow[];
    state: ChartState;
}

function formatRelative(iso: string): string {
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) {
        return iso;
    }
    const diff = (Date.now() - then) / 1000;
    if (diff < 60) {
        return `${Math.round(diff)}s ago`;
    }
    if (diff < 3600) {
        return `${Math.round(diff / 60)}m ago`;
    }
    if (diff < 86_400) {
        return `${Math.round(diff / 3600)}h ago`;
    }
    return `${Math.round(diff / 86_400)}d ago`;
}

export function ActivityFeedCard({ rows, state }: ActivityFeedCardProps) {
    const hasData = rows.length > 0;
    const resolvedState: ChartState = state === 'ready' && !hasData ? 'empty' : state;

    return (
        <div
            data-testid="activity-feed-card"
            data-state={resolvedState}
            className="panel"
            style={{
                padding: '14px 16px 12px',
                display: 'flex',
                flexDirection: 'column',
                minHeight: 260,
                minWidth: 0,
            }}
        >
            <h3
                style={{
                    fontSize: 13,
                    fontWeight: 600,
                    margin: '0 0 10px',
                    letterSpacing: '-0.01em',
                    color: 'var(--fg-0)',
                }}
            >
                Activity feed
            </h3>
            {resolvedState === 'empty' ? (
                <div
                    data-testid="activity-feed-empty"
                    style={{ color: 'var(--fg-3)', fontSize: 12, fontFamily: 'var(--font-mono)' }}
                >
                    No recent activity.
                </div>
            ) : resolvedState === 'loading' ? (
                <div style={{ color: 'var(--fg-3)', fontSize: 12 }}>
                    <span className="shimmer">loading…</span>
                </div>
            ) : (
                <ul
                    style={{
                        margin: 0,
                        padding: 0,
                        listStyle: 'none',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 6,
                        overflowY: 'auto',
                    }}
                >
                    {rows.map((row) => (
                        <li
                            key={`${row.source}-${row.id}`}
                            data-testid={`activity-${row.source}-${row.id}`}
                            style={{
                                display: 'grid',
                                gridTemplateColumns: '56px 1fr auto',
                                gap: 8,
                                alignItems: 'baseline',
                                fontSize: 12,
                                padding: '4px 0',
                                borderBottom: '1px solid var(--hairline)',
                            }}
                        >
                            <span
                                style={{
                                    fontFamily: 'var(--font-mono)',
                                    fontSize: 10.5,
                                    textTransform: 'uppercase',
                                    color: row.source === 'audit' ? '#a78bfa' : '#22d3ee',
                                }}
                            >
                                {row.source}
                            </span>
                            <span style={{ color: 'var(--fg-1)', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                <strong>{row.actor}</strong> {row.action}{' '}
                                <span style={{ color: 'var(--fg-2)' }}>{row.target}</span>
                                {row.project ? (
                                    <span
                                        style={{
                                            marginLeft: 8,
                                            color: 'var(--fg-3)',
                                            fontFamily: 'var(--font-mono)',
                                            fontSize: 10.5,
                                        }}
                                    >
                                        · {row.project}
                                    </span>
                                ) : null}
                            </span>
                            <span
                                style={{
                                    color: 'var(--fg-3)',
                                    fontFamily: 'var(--font-mono)',
                                    fontSize: 10.5,
                                }}
                            >
                                {formatRelative(row.created_at)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
