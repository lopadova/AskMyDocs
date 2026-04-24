import type { AdminTopProjectRow } from '../admin.api';
import type { ChartState } from './ChartCard';

export interface TopProjectsCardProps {
    rows: AdminTopProjectRow[];
    state: ChartState;
}

export function TopProjectsCard({ rows, state }: TopProjectsCardProps) {
    const hasData = rows.length > 0;
    const resolvedState: ChartState = state === 'ready' && !hasData ? 'empty' : state;
    const max = Math.max(1, ...rows.map((r) => r.count));

    return (
        <div
            data-testid="top-projects-card"
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
                Top projects (7d)
            </h3>
            {resolvedState === 'empty' ? (
                <div
                    data-testid="top-projects-empty"
                    style={{ color: 'var(--fg-3)', fontSize: 12, fontFamily: 'var(--font-mono)' }}
                >
                    No chats yet for any project.
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
                        gap: 8,
                    }}
                >
                    {rows.map((row) => {
                        const pct = (row.count / max) * 100;
                        return (
                            <li
                                key={row.project_key}
                                data-testid={`top-project-${row.project_key}`}
                                style={{ fontSize: 12 }}
                            >
                                <div
                                    style={{
                                        display: 'flex',
                                        justifyContent: 'space-between',
                                        marginBottom: 4,
                                    }}
                                >
                                    <span style={{ color: 'var(--fg-1)' }}>{row.project_key}</span>
                                    <span style={{ color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>
                                        {row.count}
                                    </span>
                                </div>
                                <div
                                    style={{
                                        height: 4,
                                        borderRadius: 2,
                                        background: 'var(--bg-2)',
                                        overflow: 'hidden',
                                    }}
                                >
                                    <div
                                        style={{
                                            width: `${pct}%`,
                                            height: '100%',
                                            background: 'linear-gradient(90deg, #8b5cf6, #22d3ee)',
                                        }}
                                    />
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
