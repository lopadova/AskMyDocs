import type { WorkbenchTransport } from '@ui4/workbench-react';
import { useTeamStore } from '../../lib/team-store';

function readXsrfCookie(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const row = document.cookie
        .split(';')
        .map((value) => value.trim())
        .find((value) => value.startsWith('XSRF-TOKEN='));

    return row === undefined ? null : decodeURIComponent(row.slice('XSRF-TOKEN='.length));
}

/**
 * Builds headers at request time so a rotated Sanctum CSRF cookie and the
 * active tenant selection are never captured by a stale workbench mount.
 */
export function workbenchHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
        'X-Requested-With': 'XMLHttpRequest',
    };
    const csrf = readXsrfCookie();

    if (csrf !== null) {
        headers['X-XSRF-TOKEN'] = csrf;
    }

    const tenant = useTeamStore.getState().currentTeam;
    if (tenant !== null) {
        headers['X-Tenant-Id'] = tenant;
    }

    return headers;
}

/**
 * UI4 expects a native Response. Keeping the host bridge as fetch preserves
 * HTTP status codes rather than translating authorization failures to data.
 */
export const workbenchTransport: WorkbenchTransport = (url, init) => fetch(url, init);
