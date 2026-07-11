import { describe, expect, it } from 'vitest';
import {
    buildConnections,
    filterConnections,
    syncableIds,
    TENANT_DEFAULT_LABEL,
} from './connection-vm';
import type { ConnectorEntry, ConnectorInstallationDto, ConnectorStatus } from './connectors.api';

/*
 * Pure view-model tests for the flat Connections list (redesign). R16: the
 * sorting fixture is deliberately UNSORTED so an unsorted implementation fails,
 * and assertions compare exact order.
 */

function installation(
    id: number,
    label: string,
    status: ConnectorStatus = 'active',
    overrides: Partial<ConnectorInstallationDto> = {},
): ConnectorInstallationDto {
    return {
        id,
        label,
        project_key: null,
        status,
        last_sync_at: null,
        error: null,
        folders: { include: [] },
        date_window_days: null,
        ...overrides,
    };
}

function entry(
    key: string,
    displayName: string,
    installations: ConnectorInstallationDto[],
    authKind: 'oauth' | 'credential' = 'oauth',
): ConnectorEntry {
    return {
        key,
        display_name: displayName,
        icon_url: `/connectors/${key}.svg`,
        oauth_scopes: [],
        auth_kind: authKind,
        credential_form_schema: authKind === 'credential' ? [] : null,
        installations,
    };
}

describe('buildConnections', () => {
    it('flattens every installation across entries, sorted by source name then account', () => {
        // Deliberately unsorted input: Notion before Google Drive, and within
        // Google Drive "zeta" before "alpha" — a non-sorting implementation
        // would fail the exact-order assertion below.
        const rows = buildConnections([
            entry('notion', 'Notion', [installation(1, 'research')]),
            entry('google-drive', 'Google Drive', [
                installation(2, 'zeta'),
                installation(3, 'alpha'),
            ]),
        ]);

        expect(rows.map((r) => [r.sourceName, r.account])).toEqual([
            ['Google Drive', 'alpha'],
            ['Google Drive', 'zeta'],
            ['Notion', 'research'],
        ]);
        expect(rows.map((r) => r.id)).toEqual([3, 2, 1]);
    });

    it('maps the project binding: a bound key verbatim, unbound → tenant-default sentinel', () => {
        const rows = buildConnections([
            entry('imap', 'Email (IMAP)', [
                installation(1, 'Support', 'active', { project_key: 'acme-hr' }),
                installation(2, 'Sales', 'active'),
            ]),
        ]);

        const support = rows.find((r) => r.account === 'Support');
        const sales = rows.find((r) => r.account === 'Sales');
        expect(support?.projectLabel).toBe('acme-hr');
        expect(support?.boundToProject).toBe(true);
        expect(sales?.projectLabel).toBe(TENANT_DEFAULT_LABEL);
        expect(sales?.boundToProject).toBe(false);
    });

    it('marks credential connectors so IMAP-only actions can gate on it', () => {
        const rows = buildConnections([
            entry('imap', 'Email (IMAP)', [installation(1, 'a')], 'credential'),
            entry('notion', 'Notion', [installation(2, 'b')]),
        ]);
        expect(rows.find((r) => r.connectorKey === 'imap')?.isCredential).toBe(true);
        expect(rows.find((r) => r.connectorKey === 'notion')?.isCredential).toBe(false);
    });

    it('extracts the error message; blank/non-string messages fall back; healthy rows are null', () => {
        const rows = buildConnections([
            entry('imap', 'Email (IMAP)', [
                installation(1, 'a', 'errored', { error: { message: 'Login rejected (535).' } }),
                installation(2, 'b', 'errored', { error: { message: '   ' } }),
                installation(3, 'c', 'errored', { error: { code: 429 } }),
                installation(4, 'd', 'active'),
            ]),
        ]);

        expect(rows.find((r) => r.id === 1)?.errorMessage).toBe('Login rejected (535).');
        expect(rows.find((r) => r.id === 2)?.errorMessage).toBe('Connector reported an error.');
        expect(rows.find((r) => r.id === 3)?.errorMessage).toBe('Connector reported an error.');
        expect(rows.find((r) => r.id === 4)?.errorMessage).toBeNull();
    });

    it('formats last sync relative to the injected now; null stays null', () => {
        const now = new Date('2026-07-11T12:00:00Z');
        const rows = buildConnections(
            [
                entry('imap', 'Email (IMAP)', [
                    installation(1, 'a', 'active', { last_sync_at: '2026-07-11T10:00:00Z' }),
                    installation(2, 'b', 'active'),
                ]),
            ],
            now,
        );
        expect(rows.find((r) => r.id === 1)?.lastSync).toBe('2 hr ago');
        expect(rows.find((r) => r.id === 2)?.lastSync).toBeNull();
    });
});

describe('filterConnections', () => {
    const rows = buildConnections([
        entry('imap', 'Email (IMAP)', [
            installation(1, 'team@engineering.io', 'active', { project_key: 'engineering' }),
        ]),
        entry('google-drive', 'Google Drive', [installation(2, 'marketing-drive')]),
    ]);

    it('returns the list unchanged on a blank query', () => {
        expect(filterConnections(rows, '')).toBe(rows);
        expect(filterConnections(rows, '   ')).toBe(rows);
    });

    it('matches the account label case-insensitively', () => {
        expect(filterConnections(rows, 'TEAM@ENGINEERING').map((r) => r.id)).toEqual([1]);
    });

    it('matches the source display name', () => {
        expect(filterConnections(rows, 'google').map((r) => r.id)).toEqual([2]);
    });

    it('matches the project label', () => {
        expect(filterConnections(rows, 'engineering').map((r) => r.id)).toEqual([1]);
    });

    it('returns empty when nothing matches', () => {
        expect(filterConnections(rows, 'no-such-thing')).toEqual([]);
    });
});

describe('syncableIds', () => {
    it('returns only active + errored connections (never pending or disabled)', () => {
        const rows = buildConnections([
            entry('imap', 'Email (IMAP)', [
                installation(1, 'a', 'active'),
                installation(2, 'b', 'errored'),
                installation(3, 'c', 'disabled'),
                installation(4, 'd', 'pending'),
            ]),
        ]);
        expect(syncableIds(rows).sort()).toEqual([1, 2]);
    });
});
