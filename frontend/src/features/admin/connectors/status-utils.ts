import type { ConnectorEntry, ConnectorStatus } from './connectors.api';

/*
 * Pure helpers extracted so they can be unit-tested without React.
 *
 * `derivedStatus(entry)` collapses "no installation row" to a synthetic
 * `not_installed` state so the FE has a single switch surface. The BE
 * intentionally returns `installation: null` for connectors the active
 * tenant hasn't installed — the synthetic key keeps the UI code flat.
 *
 * `formatRelative(ts)` renders a coarse, locale-free relative
 * timestamp suitable for testid-driven assertions (E2E + Vitest).
 * Unlike `Intl.RelativeTimeFormat`, the output is deterministic across
 * locales — important because `playwright.config.ts` doesn't pin a
 * specific browser locale and CI runners drift.
 */

export type DerivedConnectorStatus = ConnectorStatus | 'not_installed';

export function derivedStatus(entry: ConnectorEntry): DerivedConnectorStatus {
    return entry.installation?.status ?? 'not_installed';
}

export interface StatusBadgeStyle {
    label: string;
    background: string;
    border: string;
    color: string;
    testid: string;
}

export function statusBadgeStyle(status: DerivedConnectorStatus): StatusBadgeStyle {
    switch (status) {
        case 'active':
            return {
                label: 'Active',
                background: 'rgba(16, 185, 129, 0.16)',
                border: 'rgba(16, 185, 129, 0.45)',
                color: '#34d399',
                testid: 'badge-active',
            };
        case 'pending':
            return {
                label: 'Authorising…',
                background: 'rgba(250, 204, 21, 0.16)',
                border: 'rgba(250, 204, 21, 0.45)',
                color: '#fbbf24',
                testid: 'badge-pending',
            };
        case 'errored':
            return {
                label: 'Errored',
                background: 'rgba(239, 68, 68, 0.16)',
                border: 'rgba(239, 68, 68, 0.45)',
                color: '#fca5a5',
                testid: 'badge-errored',
            };
        case 'disabled':
            return {
                label: 'Disabled',
                background: 'rgba(100, 116, 139, 0.16)',
                border: 'rgba(100, 116, 139, 0.45)',
                color: '#94a3b8',
                testid: 'badge-disabled',
            };
        case 'not_installed':
        default:
            return {
                label: 'Not installed',
                background: 'rgba(148, 163, 184, 0.10)',
                border: 'rgba(148, 163, 184, 0.30)',
                color: '#94a3b8',
                testid: 'badge-not-installed',
            };
    }
}

/**
 * Coarse, deterministic relative-time formatter.
 *
 * Returns 'just now' / 'N min ago' / 'N hr ago' / 'N day(s) ago'.
 * `null` input returns null so the caller can hide the timestamp.
 * `now` is injected so the same output is reproducible in unit tests.
 */
export function formatRelative(iso: string | null, now: Date = new Date()): string | null {
    if (iso === null) {
        return null;
    }
    const then = new Date(iso);
    if (Number.isNaN(then.getTime())) {
        return null;
    }
    const diffMs = now.getTime() - then.getTime();
    const seconds = Math.floor(diffMs / 1000);

    if (seconds < 60) {
        return 'just now';
    }
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
        return `${minutes} min ago`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `${hours} hr ago`;
    }
    const days = Math.floor(hours / 24);
    return `${days} day${days === 1 ? '' : 's'} ago`;
}

/**
 * Maps a HTTP status code to a user-facing message for the OAuth
 * callback failure path. Keeps the mapping centralised so the callback
 * component and any future retry surface share the same vocabulary.
 *
 * R14 — surface failures loudly. Each branch maps to a distinct
 * message; we never reduce to "Something went wrong" for cases we
 * can disambiguate.
 */
export function callbackErrorMessage(status: number, fallback?: string): string {
    if (status === 400) {
        return fallback ?? 'The provider rejected the authorisation. Try installing again.';
    }
    if (status === 401) {
        return 'You were signed out during the OAuth flow. Sign in and retry.';
    }
    if (status === 403) {
        return 'Your account is not allowed to manage connectors.';
    }
    if (status === 404) {
        return 'No pending install found. Start the connection from the Connectors page.';
    }
    if (status >= 500) {
        return 'The server failed while finalising the connection. Try again in a moment.';
    }
    return fallback ?? `Authorisation failed (HTTP ${status}).`;
}
