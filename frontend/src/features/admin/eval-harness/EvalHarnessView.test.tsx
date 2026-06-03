import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup } from '@testing-library/react';
import type { AxiosResponse } from 'axios';

/*
 * v4.4/W3 Copilot iter 2 finding #2 — EvalHarnessView bootstrap
 * config fetch.
 *
 * Mocks the host axios module so each scenario controls how the
 * `GET /api/admin/eval-harness/bootstrap-config` endpoint resolves
 * (loading / ready / error) and asserts the host wrapper exposes
 * the corresponding `data-state` for E2E + observability.
 *
 * R16: each test name promises a behaviour the body actually drives.
 */

type GetReply =
    | { kind: 'ok'; data: unknown }
    | { kind: 'fail'; status: number; data?: unknown }
    | { kind: 'pending' };

const apiMock: { getResponses: Record<string, GetReply> } = {
    getResponses: {},
};

vi.mock('../../../lib/api', () => {
    return {
        api: {
            get: vi.fn(async (url: string) => {
                const reply = apiMock.getResponses[url] ?? apiMock.getResponses['*'];
                if (!reply) {
                    const error = new Error('No mock for ' + url) as Error & { isAxiosError: true; response?: { status: number } };
                    error.isAxiosError = true;
                    error.response = { status: 404 };
                    throw error;
                }
                if (reply.kind === 'pending') {
                    // Never resolves — caller is testing the
                    // pre-resolve loading frame.
                    return new Promise(() => {});
                }
                if (reply.kind === 'fail') {
                    const error = new Error('mock failure ' + reply.status) as Error & { isAxiosError: true; response?: { status: number; data?: unknown } };
                    error.isAxiosError = true;
                    error.response = { status: reply.status, data: reply.data ?? null };
                    throw error;
                }
                const response = {
                    data: reply.data,
                    status: 200,
                    statusText: 'OK',
                    headers: {},
                    config: {},
                } as unknown as AxiosResponse<unknown>;
                return response;
            }),
            // The cross-mount delegates package API calls through
            // `api.request`; we never expect those calls in the
            // EvalHarnessView shell test (the inner SPA is mocked
            // away by the never-resolving / fast-resolving branch
            // below), but we still register the property so the
            // imported `api` shape stays compatible with consumers.
            request: vi.fn(async () => ({
                data: { schema_version: 'eval-harness.report-api.v1.reports', items: [], total: 0 },
                status: 200,
                statusText: 'OK',
                headers: {},
                config: {},
            }) as unknown as AxiosResponse<unknown>),
        },
        ensureCsrfCookie: vi.fn(async () => {}),
        resetCsrf: vi.fn(),
    };
});

import { EvalHarnessView } from './EvalHarnessView';
import { __resetApiResourceCacheForTests } from './cross-mount/hooks/useApiResource';

beforeEach(() => {
    apiMock.getResponses = {};
    __resetApiResourceCacheForTests();
    /*
     * The cross-mount's internal `BrowserRouter` is wired with
     * `basename="/app/admin/eval-harness"` (matching the host
     * TanStack route mount path). React Router v6 renders NOTHING
     * when the current URL is outside the basename, so the test
     * must put the URL bar inside that prefix BEFORE render —
     * otherwise the SPA mounts but its routes never resolve and
     * the assertion `<admin-eval-harness-app>` never appears.
     */
    window.history.replaceState({}, '', '/app/admin/eval-harness');
});

afterEach(() => {
    cleanup();
});

describe('EvalHarnessView (cross-mount shell)', () => {
    it('renders the loading state while the bootstrap-config fetch is pending (no SPA mounted yet)', async () => {
        apiMock.getResponses['/api/admin/eval-harness/bootstrap-config'] = { kind: 'pending' };

        render(<EvalHarnessView />);

        const host = await screen.findByTestId('admin-eval-harness-host');
        expect(host).toHaveAttribute('data-state', 'loading');
        expect(host).toHaveAttribute('data-mount', 'cross-mount');

        // The loading shimmer is rendered ONLY while config is null.
        expect(screen.getByTestId('admin-eval-harness-loading')).toBeInTheDocument();
        // The cross-mount SPA must NOT mount until the bootstrap
        // config arrives — otherwise the SPA renders against
        // wrong/empty config and the user sees a flash of broken UI
        // before the proper data lands.
        expect(screen.queryByTestId('admin-eval-harness-app')).not.toBeInTheDocument();
    });

    it('mounts the cross-mount SPA only when BOTH config and the data probe resolve (data-state=ready)', async () => {
        apiMock.getResponses['/api/admin/eval-harness/bootstrap-config'] = {
            kind: 'ok',
            data: {
                ui_version: '0.1.0',
                metric_labels: { macro_f1: 'Macro F1' },
                tenant_header: 'X-Eval-Harness-Tenant',
                polling: { live_batches_seconds: 7 },
                locale: 'en',
                shortcuts: { commandPalette: 'mod+k' },
            },
        };
        // The data probe must answer for the SPA to mount.
        apiMock.getResponses['/admin/eval-harness/api/reports'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.reports', items: [], total: 0 },
        };

        render(<EvalHarnessView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-eval-harness-host')).toHaveAttribute('data-state', 'ready');
        });
        expect(screen.queryByTestId('admin-eval-harness-loading')).not.toBeInTheDocument();
        expect(await screen.findByTestId('admin-eval-harness-app')).toBeInTheDocument();
    });

    it('shows a clean unavailable landing (NOT the SPA) when the data API probe fails — safe with the flag on OR off', async () => {
        // Config resolves (host endpoint), but the eval data API is unwired /
        // disabled — its routes 404 (flag off) or 500 (flag on, blade shadow).
        apiMock.getResponses['/api/admin/eval-harness/bootstrap-config'] = {
            kind: 'ok',
            data: {
                ui_version: '0.1.0',
                metric_labels: {},
                tenant_header: null,
                polling: {},
                locale: 'en',
                shortcuts: { commandPalette: 'mod+k' },
            },
        };
        apiMock.getResponses['/admin/eval-harness/api/reports'] = { kind: 'fail', status: 404 };

        render(<EvalHarnessView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-eval-harness-host')).toHaveAttribute('data-state', 'unavailable');
        });
        // Clean landing instead of the SPA + its error-panel storm.
        expect(screen.getByTestId('admin-eval-harness-unavailable')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-eval-harness-app')).not.toBeInTheDocument();
    });
});
