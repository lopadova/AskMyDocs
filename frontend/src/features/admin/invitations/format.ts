import { READ_ROW_CAP } from './invitations.api';

/*
 * Pure formatting + state helpers for the native Invitations admin. Kept
 * dependency-free and side-effect-free so they unit-test in isolation.
 */

export type ListState = 'loading' | 'error' | 'empty' | 'ready';

/**
 * Roll a TanStack query + its row count into the canonical four-state machine
 * the admin surfaces use for `data-state`. Precedence: error > loading >
 * empty > ready (R14 — the caller can always tell success from failure).
 */
export function deriveListState(q: { isLoading: boolean; isError: boolean }, rowCount: number): ListState {
    if (q.isError) return 'error';
    if (q.isLoading) return 'loading';
    if (rowCount === 0) return 'empty';
    return 'ready';
}

/** True when a read surface returned exactly the server cap (likely truncated). */
export function isCapped(rowCount: number): boolean {
    return rowCount >= READ_ROW_CAP;
}

/** A ratio in [0,1] → a one-decimal percentage. Guards NaN/Infinity (R14). */
export function formatPercent(ratio: number | null | undefined): string {
    if (ratio === null || ratio === undefined || !Number.isFinite(ratio)) return '—';
    return `${(ratio * 100).toFixed(1)}%`;
}

/** Compact integer formatting (1234 → "1.2K"). Guards non-finite (R14). */
export function formatCompact(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return '—';
    return new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}

/** Plain number, or "—" for non-finite. */
export function formatNumber(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return '—';
    return new Intl.NumberFormat('en').format(value);
}

/** K-factor / multiplier → 2 decimals with an ×, or "—". */
export function formatFactor(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return '—';
    return `${value.toFixed(2)}×`;
}

/**
 * Seconds → a coarse human duration (largest two units). `null` (the package's
 * sentinel for "no redemptions yet") renders as "—", never NaN.
 */
export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || !Number.isFinite(seconds) || seconds < 0) return '—';
    if (seconds < 60) return `${Math.round(seconds)}s`;
    const units: Array<[string, number]> = [
        ['d', 86400],
        ['h', 3600],
        ['m', 60],
    ];
    const parts: string[] = [];
    let rest = Math.floor(seconds);
    for (const [label, size] of units) {
        if (rest >= size) {
            parts.push(`${Math.floor(rest / size)}${label}`);
            rest %= size;
        }
        if (parts.length === 2) break;
    }
    return parts.length > 0 ? parts.join(' ') : '0m';
}

/** ISO timestamp → locale date-time, or "—" for empty/invalid. */
export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString();
}

/**
 * Privacy-preserving email mask for the waitlist (raw PII column). Shows the
 * first character of the local part + the full domain: `j•••@example.com`.
 * Never renders the full address in the admin table.
 */
export function maskEmail(email: string | null | undefined): string {
    if (!email) return '—';
    const at = email.indexOf('@');
    if (at <= 0) return '•••';
    const local = email.slice(0, at);
    const domain = email.slice(at + 1);
    const head = local.slice(0, 1);
    return `${head}•••@${domain}`;
}

/** Humanize a snake_case identifier for display (`self_referral` → "self referral"). */
export function humanize(token: string | null | undefined): string {
    if (!token) return '—';
    return token.replace(/_/g, ' ');
}
