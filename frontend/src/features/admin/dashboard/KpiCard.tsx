import type { ReactNode } from 'react';
import { Icon, type IconName } from '../../../components/Icons';

export type KpiState = 'loading' | 'ready' | 'error' | 'empty';

export interface KpiCardProps {
    slug: string;
    icon: IconName;
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    state: KpiState;
}

/**
 * Single KPI tile. `data-state` drives both CSS (shimmer on loading)
 * and Playwright assertions — every KPI rendered on the dashboard
 * must reach `ready` before the scenario passes (`empty` is the
 * zero-data green branch, `error` is the 500 branch).
 */
export function KpiCard({ slug, icon, label, value, hint, state }: KpiCardProps) {
    const IconCmp = Icon[icon];
    return (
        <div
            data-testid={`kpi-card-${slug}`}
            data-state={state}
            className="panel"
            style={{
                padding: '14px 16px 12px',
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
                minWidth: 0,
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    color: 'var(--fg-3)',
                    fontSize: 11.5,
                    fontFamily: 'var(--font-mono)',
                    textTransform: 'uppercase',
                    letterSpacing: '0.05em',
                }}
            >
                <IconCmp size={13} />
                <span>{label}</span>
            </div>
            <div
                style={{
                    fontSize: 22,
                    fontWeight: 600,
                    letterSpacing: '-0.02em',
                    color: 'var(--fg-0)',
                    minHeight: 26,
                }}
            >
                {state === 'loading' ? <span className="shimmer">…</span> : value}
            </div>
            {hint ? (
                <div style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>{hint}</div>
            ) : null}
        </div>
    );
}
