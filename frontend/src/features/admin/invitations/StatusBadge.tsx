import type { CSSProperties, ReactNode } from 'react';
import { humanize } from './format';

/*
 * Small status pill for invite enums (code state, referral/reward/waitlist
 * status, abuse severity/action). Tone is derived from the value but can be
 * overridden — danger is reserved for genuinely-blocking states so the table
 * reads calm (a green-heavy ledger, red only where it matters).
 */

export type BadgeTone = 'ok' | 'pending' | 'muted' | 'warn' | 'danger';

const TONE_BY_VALUE: Record<string, BadgeTone> = {
    // positive / terminal-good
    active: 'ok',
    granted: 'ok',
    qualified: 'ok',
    rewarded: 'ok',
    accepted: 'ok',
    converted: 'ok',
    redeemed: 'ok',
    // in-flight
    pending: 'pending',
    waiting: 'pending',
    draft: 'pending',
    invited: 'pending',
    // spent / closed (not an error, just done)
    exhausted: 'muted',
    expired: 'muted',
    reversed: 'muted',
    removed: 'muted',
    ended: 'muted',
    paused: 'muted',
    none: 'muted',
    // attention
    warn: 'warn',
    flag: 'warn',
    throttle: 'warn',
    // hard-stop
    revoked: 'danger',
    block: 'danger',
};

const TONE_STYLE: Record<BadgeTone, CSSProperties> = {
    ok: { background: 'rgba(16,185,129,0.16)', color: '#34d399', borderColor: 'rgba(16,185,129,0.4)' },
    pending: { background: 'rgba(59,130,246,0.16)', color: '#60a5fa', borderColor: 'rgba(59,130,246,0.4)' },
    muted: { background: 'rgba(148,163,184,0.14)', color: 'var(--fg-2)', borderColor: 'rgba(148,163,184,0.3)' },
    warn: { background: 'rgba(245,158,11,0.16)', color: '#fbbf24', borderColor: 'rgba(245,158,11,0.4)' },
    danger: { background: 'rgba(239,68,68,0.16)', color: '#f87171', borderColor: 'rgba(239,68,68,0.45)' },
};

export function toneFor(value: string): BadgeTone {
    return TONE_BY_VALUE[value] ?? 'muted';
}

export interface StatusBadgeProps {
    value: string;
    tone?: BadgeTone;
    testid?: string;
    children?: ReactNode;
}

export function StatusBadge({ value, tone, testid, children }: StatusBadgeProps) {
    const resolved = tone ?? toneFor(value);
    const style = TONE_STYLE[resolved];
    return (
        <span
            data-testid={testid}
            data-tone={resolved}
            style={{
                display: 'inline-block',
                padding: '2px 8px',
                borderRadius: 999,
                border: `1px solid ${style.borderColor}`,
                background: style.background,
                color: style.color,
                fontSize: 11,
                fontWeight: 600,
                letterSpacing: '0.02em',
                textTransform: 'capitalize',
                whiteSpace: 'nowrap',
            }}
        >
            {children ?? humanize(value)}
        </span>
    );
}
