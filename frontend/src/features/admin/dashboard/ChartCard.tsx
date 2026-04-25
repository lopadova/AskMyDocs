import type { ReactNode } from 'react';

export type ChartState = 'loading' | 'ready' | 'error' | 'empty';

export interface ChartCardProps {
    slug: string;
    title: string;
    subtitle?: string;
    state: ChartState;
    children: ReactNode;
}

/**
 * Panel wrapper shared by every chart card. `data-testid` +
 * `data-state` anchor Playwright / Vitest assertions. The empty + error
 * branches render in-place so the layout never shifts.
 */
export function ChartCard({ slug, title, subtitle, state, children }: ChartCardProps) {
    return (
        <div
            data-testid={`chart-card-${slug}`}
            data-state={state}
            className="panel"
            style={{
                padding: '14px 16px 12px',
                display: 'flex',
                flexDirection: 'column',
                minHeight: 260,
                minWidth: 0,
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'baseline',
                    justifyContent: 'space-between',
                    marginBottom: 8,
                }}
            >
                <h3
                    style={{
                        fontSize: 13,
                        fontWeight: 600,
                        margin: 0,
                        letterSpacing: '-0.01em',
                        color: 'var(--fg-0)',
                    }}
                >
                    {title}
                </h3>
                {subtitle ? (
                    <span
                        style={{
                            fontSize: 10.5,
                            color: 'var(--fg-3)',
                            fontFamily: 'var(--font-mono)',
                            textTransform: 'uppercase',
                            letterSpacing: '0.05em',
                        }}
                    >
                        {subtitle}
                    </span>
                ) : null}
            </div>
            <div style={{ flex: 1, minHeight: 0, display: 'flex' }}>{children}</div>
        </div>
    );
}

export interface EmptyChartProps {
    slug: string;
    message?: string;
}

/**
 * Guard rendered when backend returns no data (R13-green branch
 * verified by the empty-state Playwright scenario). Renders a valid
 * SVG so the card keeps its height without layout jitter.
 */
export function EmptyChart({ slug, message = 'No data yet for this window' }: EmptyChartProps) {
    return (
        <svg
            data-testid={`${slug}-empty`}
            role="img"
            aria-label={message}
            width="100%"
            height="100%"
            viewBox="0 0 320 180"
            style={{ display: 'block' }}
        >
            <rect
                x="0"
                y="0"
                width="320"
                height="180"
                fill="transparent"
                stroke="var(--hairline)"
                strokeDasharray="4 6"
            />
            <text
                x="160"
                y="92"
                textAnchor="middle"
                fontSize="11"
                fill="var(--fg-3)"
                fontFamily="var(--font-mono)"
            >
                {message}
            </text>
        </svg>
    );
}

export function ChartFallback({ slug }: { slug: string }) {
    return (
        <div
            data-testid={`${slug}-loading`}
            style={{
                flex: 1,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                color: 'var(--fg-3)',
                fontSize: 12,
                fontFamily: 'var(--font-mono)',
            }}
        >
            <span className="shimmer">loading chart…</span>
        </div>
    );
}
