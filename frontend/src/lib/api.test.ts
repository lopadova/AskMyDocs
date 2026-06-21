import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import type { AxiosRequestConfig } from 'axios';
import { api } from './api';
import { useTeamStore, type Team } from './team-store';

const TEAMS: Team[] = [
    { tenant_id: 'default', hash: 'def0def0def0', name: 'Default', projects: [] },
    { tenant_id: 'acme', hash: 'acme00acme00', name: 'Acme', projects: [] },
];

/*
 * Swap the axios adapter for a recorder: no network, and the test can
 * inspect exactly what the request interceptor produced.
 */
let captured: AxiosRequestConfig[] = [];
let respondWith: { status: number; data: unknown } = { status: 200, data: {} };
const originalAdapter = api.defaults.adapter;
// Saved descriptor so `window.location` can be fully restored in afterEach.
const originalLocationDescriptor = Object.getOwnPropertyDescriptor(window, 'location');

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

    it('omits the header on the `default` tenant so package mounts (AI Act) fall back instead of 404ing', async () => {
        // `default` is the host's no-multi-tenancy sentinel: ResolveTenant
        // resolves the same context with or without the header, but the AI Act
        // package middleware 404s on `default` (never a `tenants` row). Omitting
        // the header lets that mount take its documented "no header" fallback.
        useTeamStore.setState({ teams: TEAMS, currentTeam: 'default', userId: 1 });
        await api.get('/api/admin/ai-act-compliance/risk-register');
        expect(captured[0]?.headers?.['X-Tenant-Id']).toBeUndefined();

        // A first-party admin call on `default` is likewise header-free — host
        // R30 scoping still resolves to `default` via ResolveTenant's fallback.
        captured = [];
        await api.get('/api/admin/metrics/overview');
        expect(captured[0]?.headers?.['X-Tenant-Id']).toBeUndefined();
    });
});

describe('api tenant_forbidden response interceptor', () => {
    let assignSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        // Stub window.location so the interceptor's navigate call is
        // interceptable and doesn't trigger a real JSDOM navigation.
        assignSpy = vi.fn();
        Object.defineProperty(window, 'location', {
            value: { assign: assignSpy },
            writable: true,
            configurable: true,
        });
    });

    afterEach(() => {
        // Fully restore the original descriptor so sibling test files
        // see the real window.location (R16: no leaked global state).
        if (originalLocationDescriptor) {
            Object.defineProperty(window, 'location', originalLocationDescriptor);
        }
    });

    it('snaps back to the first team, navigates to /app, and still rejects (R14: caller sees the error)', async () => {
        respondWith = { status: 403, data: { error: 'tenant_forbidden' } };

        await expect(api.get('/api/admin/kb/tags')).rejects.toThrow();
        expect(useTeamStore.getState().currentTeam).toBe('default');
        expect(assignSpy).toHaveBeenCalledWith('/app');
    });

    it('leaves the team untouched and does NOT navigate on a plain RBAC 403', async () => {
        respondWith = { status: 403, data: { message: 'Forbidden.' } };

        await expect(api.get('/api/admin/users')).rejects.toThrow();
        expect(useTeamStore.getState().currentTeam).toBe('acme');
        expect(assignSpy).not.toHaveBeenCalled();
    });
});
