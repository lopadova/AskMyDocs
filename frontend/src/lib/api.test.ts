import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import type { AxiosRequestConfig } from 'axios';
import { api } from './api';
import { useTeamStore, type Team } from './team-store';

const TEAMS: Team[] = [
    { tenant_id: 'default', name: 'Default', projects: [] },
    { tenant_id: 'acme', name: 'Acme', projects: [] },
];

/*
 * Swap the axios adapter for a recorder: no network, and the test can
 * inspect exactly what the request interceptor produced.
 */
let captured: AxiosRequestConfig[] = [];
let respondWith: { status: number; data: unknown } = { status: 200, data: {} };
const originalAdapter = api.defaults.adapter;

beforeEach(() => {
    captured = [];
    respondWith = { status: 200, data: {} };
    api.defaults.adapter = (config) => {
        captured.push(config);
        const { status, data } = respondWith;
        if (status >= 400) {
            return Promise.reject(
                Object.assign(new Error(`Request failed with status code ${status}`), {
                    isAxiosError: true,
                    config,
                    response: { status, data, headers: {}, config, statusText: '' },
                }),
            );
        }
        return Promise.resolve({ status, data, headers: {}, config, statusText: 'OK' });
    };
    useTeamStore.setState({ teams: TEAMS, currentTeam: 'acme', userId: 1 });
});

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    useTeamStore.setState({ teams: [], currentTeam: null, userId: null });
});

describe('api X-Tenant-Id request interceptor', () => {
    it('stamps the active team on app API calls', async () => {
        await api.get('/api/admin/metrics/overview');
        expect(captured[0]?.headers?.['X-Tenant-Id']).toBe('acme');
    });

    it.each(['/api/auth/me', '/sanctum/csrf-cookie', '/testing/reset'])(
        'leaves %s tenant-free so a stale team can never lock the bootstrap out',
        async (url) => {
            await api.get(url).catch(() => undefined);
            expect(captured[0]?.headers?.['X-Tenant-Id']).toBeUndefined();
        },
    );

    it('sends no header before the first team sync', async () => {
        useTeamStore.setState({ teams: [], currentTeam: null, userId: null });
        await api.get('/api/admin/metrics/overview');
        expect(captured[0]?.headers?.['X-Tenant-Id']).toBeUndefined();
    });
});

describe('api tenant_forbidden response interceptor', () => {
    it('snaps back to the first team and still rejects (R14: caller sees the error)', async () => {
        respondWith = { status: 403, data: { error: 'tenant_forbidden' } };

        await expect(api.get('/api/admin/kb/tags')).rejects.toThrow();
        expect(useTeamStore.getState().currentTeam).toBe('default');
    });

    it('leaves the team untouched on a plain RBAC 403', async () => {
        respondWith = { status: 403, data: { message: 'Forbidden.' } };

        await expect(api.get('/api/admin/users')).rejects.toThrow();
        expect(useTeamStore.getState().currentTeam).toBe('acme');
    });
});
