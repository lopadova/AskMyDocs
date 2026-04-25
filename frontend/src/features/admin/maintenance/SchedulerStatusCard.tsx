import { useSchedulerStatus } from './maintenance.api';

/*
 * Read-only widget showing the static schedule declared in
 * bootstrap/app.php. No runtime scheduler introspection — the
 * controller just returns the config list. Operators wanting live
 * "next run" should check their cron output.
 */
export function SchedulerStatusCard() {
    const q = useSchedulerStatus();

    const state: 'loading' | 'ready' | 'error' = q.isLoading
        ? 'loading'
        : q.isError
          ? 'error'
          : 'ready';

    return (
        <div
            data-testid="scheduler-status"
            data-state={state}
            style={{
                border: '1px solid var(--hairline)',
                borderRadius: 8,
                padding: 14,
                background: 'var(--bg-1)',
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
            }}
        >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                <h3 style={{ fontSize: 13, margin: 0, fontWeight: 600 }}>Scheduled maintenance</h3>
                <span style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>daily</span>
            </div>
            {state === 'loading' ? (
                <div style={{ fontSize: 12, color: 'var(--fg-3)' }}>Loading…</div>
            ) : null}
            {state === 'error' ? (
                <div style={{ fontSize: 12, color: 'var(--danger-fg, #b91c1c)' }}>
                    Unable to load schedule.
                </div>
            ) : null}
            {state === 'ready' ? (
                <ul
                    style={{
                        margin: 0,
                        padding: 0,
                        listStyle: 'none',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 6,
                    }}
                >
                    {q.data!.data.map((row) => (
                        <li
                            key={row.command}
                            data-testid={`scheduler-row-${row.command}`}
                            style={{
                                display: 'grid',
                                gridTemplateColumns: '60px 1fr',
                                gap: 10,
                                fontSize: 12,
                            }}
                        >
                            <code style={{ color: 'var(--fg-3)' }}>{row.cron_time}</code>
                            <div>
                                <code
                                    style={{
                                        fontFamily: 'var(--font-mono)',
                                        fontSize: 11.5,
                                        color: 'var(--fg-0)',
                                    }}
                                >
                                    {row.command}
                                </code>
                                <div style={{ fontSize: 11, color: 'var(--fg-3)' }}>{row.description}</div>
                            </div>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
