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

    it('mounts the cross-mount SPA after the bootstrap-config fetch resolves with data-state=ready', async () => {
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

        render(<EvalHarnessView />);

        // Initial render is loading; after the fetch promise
        // resolves, the wrapper flips to data-state="ready" and the
        // SPA shell mounts.
        await waitFor(() => {
            expect(screen.getByTestId('admin-eval-harness-host')).toHaveAttribute('data-state', 'ready');
        });
        expect(screen.queryByTestId('admin-eval-harness-loading')).not.toBeInTheDocument();
        expect(await screen.findByTestId('admin-eval-harness-app')).toBeInTheDocument();
    });

    it('flips data-state to error when bootstrap-config fails AND still mounts the SPA in degraded mode (R7 / R14)', async () => {
        apiMock.getResponses['/api/admin/eval-harness/bootstrap-config'] = {
            kind: 'fail',
            status: 503,
            data: { message: 'config service unavailable' },
        };

        render(<EvalHarnessView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-eval-harness-host')).toHaveAttribute('data-state', 'error');
        });
        // The cross-mount STILL mounts so the package's own
        // `<ErrorPanel />` surfaces underlying API failures (R7 /
        // R14: failures should be loud — the shell going completely
        // blank would hide the real error from the operator).
        expect(await screen.findByTestId('admin-eval-harness-app')).toBeInTheDocument();
    });
});
