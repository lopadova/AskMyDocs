import { describe, it, expect } from 'vitest';
import {
    accountStatus,
    callbackErrorMessage,
    formatRelative,
    statusBadgeStyle,
} from './status-utils';
import type { ConnectorInstallationDto } from './connectors.api';

/*
 * Pure-helper tests. Exercise every branch of the status switch,
 * the relative-time formatter (including the boundary cases that
 * make the difference between "min" / "hr" / "day"), and the HTTP
 * status → user-facing message mapping.
 *
 * R16 — each test asserts the behaviour its name promises.
 *      "boundary at 60s" actually uses a 60s diff and asserts the
 *      switch to 'min ago', not "any non-empty string".
 */

describe('accountStatus', () => {
    it('returns "not_installed" when the installation is null/undefined', () => {
        expect(accountStatus(null)).toBe('not_installed');
        expect(accountStatus(undefined)).toBe('not_installed');
    });

    it('returns the installation.status when present', () => {
        const installation: ConnectorInstallationDto = {
            id: 1,
            label: 'support',
            project_key: 'acme-hr',
            status: 'active',
            last_sync_at: null,
            error: null,
            folders: { include: [] },
            date_window_days: null,
        };
        expect(accountStatus(installation)).toBe('active');
    });
});

describe('statusBadgeStyle', () => {
    it('maps every known status to a distinct testid', () => {
        const testids = new Set<string>();
        for (const status of [
            'active',
            'pending',
            'errored',
            'disabled',
            'not_installed',
        ] as const) {
            const badge = statusBadgeStyle(status);
            expect(badge.testid).not.toBe('');
            expect(badge.label.length).toBeGreaterThan(0);
            testids.add(badge.testid);
        }
        // Each status maps to a unique testid — important so E2E can
        // assert on `data-status-value` without collisions.
        expect(testids.size).toBe(5);
    });

    it('renders the "Authorising…" label for pending', () => {
        const badge = statusBadgeStyle('pending');
        expect(badge.label).toBe('Authorising…');
    });

    it('renders the "Not installed" label for not_installed', () => {
        const badge = statusBadgeStyle('not_installed');
        expect(badge.label).toBe('Not installed');
    });
});

describe('formatRelative', () => {
    const now = new Date('2026-05-11T12:00:00Z');

    it('returns null when the timestamp is null', () => {
        expect(formatRelative(null, now)).toBeNull();
    });

    it('returns null when the timestamp is invalid', () => {
        expect(formatRelative('not-a-date', now)).toBeNull();
    });

    it('returns "just now" for diffs under 60 seconds', () => {
        const t = new Date(now.getTime() - 30_000).toISOString();
        expect(formatRelative(t, now)).toBe('just now');
    });

    it('crosses to "min ago" at the 60-second boundary', () => {
        const t = new Date(now.getTime() - 60_000).toISOString();
        expect(formatRelative(t, now)).toBe('1 min ago');
    });

    it('crosses to "hr ago" at the 60-minute boundary', () => {
        const t = new Date(now.getTime() - 60 * 60 * 1000).toISOString();
        expect(formatRelative(t, now)).toBe('1 hr ago');
    });

    it('crosses to "day ago" at the 24-hour boundary', () => {
        const t = new Date(now.getTime() - 24 * 60 * 60 * 1000).toISOString();
        expect(formatRelative(t, now)).toBe('1 day ago');
    });

    it('pluralises days correctly', () => {
        const t = new Date(now.getTime() - 3 * 24 * 60 * 60 * 1000).toISOString();
        expect(formatRelative(t, now)).toBe('3 days ago');
    });
});

describe('callbackErrorMessage', () => {
    it('maps 400 to a retry-install hint', () => {
        expect(callbackErrorMessage(400)).toContain('Try installing again');
    });

    it('honours a fallback message on 400', () => {
        const result = callbackErrorMessage(400, 'state mismatch');
        expect(result).toBe('state mismatch');
    });

    it('maps 401 to a sign-in prompt', () => {
        expect(callbackErrorMessage(401)).toContain('signed out');
    });

    it('maps 403 to a permission hint', () => {
        expect(callbackErrorMessage(403)).toContain('not allowed');
    });

    it('maps 404 to a "start from the Connectors page" hint', () => {
        expect(callbackErrorMessage(404)).toContain('Start the connection from the Connectors page');
    });

    it('maps 5xx to a server-failure message', () => {
        expect(callbackErrorMessage(500)).toContain('server failed');
        expect(callbackErrorMessage(503)).toContain('server failed');
    });

    it('falls back to the HTTP code for unknown statuses', () => {
        expect(callbackErrorMessage(418)).toContain('418');
    });
});
