import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AppBootstrapConfig } from './utils/bootstrap';
import type { AxiosRequestConfig, AxiosResponse } from 'axios';

/*
 * v4.4/W3 — cross-mount main-entry tests.
 *
 * Mocks the host `frontend/src/lib/api.ts` axios instance so each
 * scenario controls the package's `/admin/eval-harness/api/*`
 * response payloads without hitting the backend. The mock is mutable
 * per-test so failure-path scenarios can inject 4xx/5xx.
 *
 * R16: each test name promises a behaviour and the body drives THAT
 * behaviour to a strict assertion — name and body match, no trivially
 * passing trues.
 */

type MockReply =
    | { kind: 'ok'; data: unknown; status?: number }
    | { kind: 'fail'; status: number; data?: unknown };

const apiMock: { responses: Record<string, MockReply>; calls: string[] } = {
    responses: {},
    calls: [],
};

vi.mock('../../../../lib/api', () => {
    return {
        api: {
            request: vi.fn(async (config: AxiosRequestConfig) => {
                const url = typeof config.url === 'string' ? config.url : '';
                apiMock.calls.push(url);
                const reply = apiMock.responses[normalisePath(url)] ?? apiMock.responses['*'];
                if (!reply) {
                    const error = new Error('No mock for ' + url) as Error & { response?: { status: number; data?: unknown }; isAxiosError: true };
                    error.isAxiosError = true;
                    error.response = { status: 404, data: null };
                    throw error;
                }
                if (reply.kind === 'fail') {
                    const error = new Error('mock failure ' + reply.status) as Error & { response?: { status: number; data?: unknown }; isAxiosError: true };
                    error.isAxiosError = true;
                    error.response = { status: reply.status, data: reply.data ?? null };
                    throw error;
                }
                // The mocked axios doesn't enforce the
                // `InternalAxiosRequestConfig` shape — the cross-mount
                // service code only reads `data` / `status`, never the
                // `config` echo. Cast through unknown so the test
                // doesn't drag in axios's internal header-class.
                const response = {
                    data: reply.data,
                    status: reply.status ?? 200,
                    statusText: 'OK',
                    headers: {},
                    config,
                } as unknown as AxiosResponse<unknown>;
                return response;
            }),
        },
        ensureCsrfCookie: vi.fn(async () => {}),
        resetCsrf: vi.fn(),
    };
});

import EvalHarnessUiApp from './main-entry';
import { __resetApiResourceCacheForTests } from './hooks/useApiResource';

function normalisePath(url: string): string {
    // Drop the query-string for matching purposes — keeps the mock
    // table independent of pagination / limit / id query values.
    const queryStart = url.indexOf('?');
    return queryStart === -1 ? url : url.slice(0, queryStart);
}

function makeConfig(overrides: Partial<AppBootstrapConfig> = {}): AppBootstrapConfig {
    return {
        ui_version: '0.1.0',
        metric_labels: {},
        tenant_header: 'X-Eval-Harness-Tenant',
        polling: {},
        locale: 'en',
        shortcuts: { commandPalette: 'mod+k' },
        ...overrides,
    };
}

beforeEach(() => {
    apiMock.responses = {};
    apiMock.calls = [];
    // Wipe the module-level useApiResource cache between scenarios —
    // without this, a test that primed the cache with a happy-path
    // payload would leak that into the next test's failure-path
    // assertion (the cache hit short-circuits the new mock).
    __resetApiResourceCacheForTests();
    // The cross-mount uses a real BrowserRouter; isolate each test by
    // resetting the URL bar to root so the dashboard route is hit.
    window.history.replaceState({}, '', '/');
});

afterEach(() => {
    cleanup();
});

describe('EvalHarnessUiApp (cross-mount)', () => {
    it('renders the package shell with Eval Harness UI title and the Dashboard nav item', async () => {
        // Dashboard fans out to three GETs (reports, adversarial,
        // batches/live). Stub all three with empty payloads so the
        // page rolls into its empty-state branch deterministically.
        apiMock.responses['/admin/eval-harness/api/reports'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.reports', items: [], total: 0 },
        };
        apiMock.responses['/admin/eval-harness/api/adversarial/manifests'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.adversarial-manifests', items: [] },
        };
        apiMock.responses['/admin/eval-harness/api/batches/live'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.batches-live', items: [] },
        };

        render(<EvalHarnessUiApp config={makeConfig()} apiBase="/admin/eval-harness/api" routeBase="" />);

        // The shell mounts unconditionally — the Dashboard inner page
        // hydrates after the three fetches resolve. The cross-mount
        // wrapper is what proves the React tree is actually rendering
        // the package shell (vs an iframe placeholder).
        const app = await screen.findByTestId('admin-eval-harness-app');
        expect(app).toBeInTheDocument();

        // The header carries the package's static "Eval Harness UI"
        // title — proves the AppShell rendered with the version we
        // injected via the bootstrap config.
        expect(screen.getByRole('heading', { level: 1, name: 'Eval Harness UI' })).toBeInTheDocument();

        // Dashboard nav anchor is reachable by accessible name (R11 +
        // R15) and by stable testid (R29).
        const dashboardNav = screen.getByTestId('admin-eval-harness-nav-dashboard');
        expect(dashboardNav).toBeInTheDocument();
        expect(dashboardNav).toHaveTextContent('Dashboard');
    });

    it('navigates to /reports when the Reports nav link is clicked (BrowserRouter wired)', async () => {
        apiMock.responses['/admin/eval-harness/api/reports'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.reports', items: [], total: 0 },
        };
        apiMock.responses['/admin/eval-harness/api/adversarial/manifests'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.adversarial-manifests', items: [] },
        };
        apiMock.responses['/admin/eval-harness/api/batches/live'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.batches-live', items: [] },
        };

        const user = userEvent.setup();
        render(<EvalHarnessUiApp config={makeConfig()} apiBase="/admin/eval-harness/api" routeBase="" />);

        // Wait for the Dashboard to mount before navigating away from
        // it — clicking the nav before the first route hydrates would
        // race the initial Routes resolution.
        await screen.findByTestId('admin-eval-harness-dashboard');

        const reportsNav = screen.getByTestId('admin-eval-harness-nav-reports');
        await user.click(reportsNav);

        // After the click, the Reports page mounts (its own testid)
        // AND the Dashboard testid is gone. Both assertions matter:
        // the first proves the nav fired the route transition, the
        // second proves Routes truly swapped the inner page (rather
        // than rendering both simultaneously, which would be a layout
        // bug).
        await waitFor(() => {
            expect(screen.getByTestId('admin-eval-harness-reports')).toBeInTheDocument();
        });
        expect(screen.queryByTestId('admin-eval-harness-dashboard')).not.toBeInTheDocument();
    });

    it('surfaces a 404 from /reports as the package ErrorPanel (R7 / R14: failures are loud)', async () => {
        // 404 → the package's `classifyStatusError(404)` maps to the
        // `empty` kind with the standard "Resource not available
        // yet." message; the page renders <ErrorPanel /> which now
        // carries role="alert" + data-testid="ehu-error-panel".
        apiMock.responses['/admin/eval-harness/api/reports'] = {
            kind: 'fail',
            status: 404,
            data: { message: 'gone' },
        };
        apiMock.responses['/admin/eval-harness/api/adversarial/manifests'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.adversarial-manifests', items: [] },
        };
        apiMock.responses['/admin/eval-harness/api/batches/live'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.batches-live', items: [] },
        };

        render(<EvalHarnessUiApp config={makeConfig()} apiBase="/admin/eval-harness/api" routeBase="" />);

        // The dashboard renders an ErrorPanel inline when the reports
        // fetch fails — the panel must be discoverable via the alert
        // role (R15) so screen-readers announce the failure.
        const panel = await screen.findByTestId('ehu-error-panel');
        expect(panel).toBeInTheDocument();
        expect(panel).toHaveAttribute('role', 'alert');
        // The package's classifier emits the `empty` kind for 404 + a
        // human-readable message — both are part of the contract the
        // BE relies on, so the test asserts both.
        expect(panel).toHaveTextContent(/empty/i);
        expect(panel).toHaveTextContent('Resource not available yet.');
    });

    it('does NOT mutate the host document.documentElement.dataset.theme (host owns theme tokens)', async () => {
        apiMock.responses['/admin/eval-harness/api/reports'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.reports', items: [], total: 0 },
        };
        apiMock.responses['/admin/eval-harness/api/adversarial/manifests'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.adversarial-manifests', items: [] },
        };
        apiMock.responses['/admin/eval-harness/api/batches/live'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.batches-live', items: [] },
        };

        const before = document.documentElement.dataset.theme ?? '';

        render(<EvalHarnessUiApp config={makeConfig()} apiBase="/admin/eval-harness/api" routeBase="" />);
        await screen.findByTestId('admin-eval-harness-app');

        // R16 + R17: the cross-mount port intentionally DOES NOT
        // touch `<html>` data-attributes. If a future regression
        // re-introduces a `useEffect(() => { document.documentElement.dataset.theme = ... })`
        // copied from a sister package, this test catches it.
        const after = document.documentElement.dataset.theme ?? '';
        expect(after).toBe(before);
    });

    it('forwards the configured tenant header on every package API request (R30: tenant boundary preserved)', async () => {
        apiMock.responses['/admin/eval-harness/api/reports'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.reports', items: [], total: 0 },
        };
        apiMock.responses['/admin/eval-harness/api/adversarial/manifests'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.adversarial-manifests', items: [] },
        };
        apiMock.responses['/admin/eval-harness/api/batches/live'] = {
            kind: 'ok',
            data: { schema_version: 'eval-harness.report-api.v1.batches-live', items: [] },
        };

        render(
            <EvalHarnessUiApp
                config={makeConfig({ tenant_header: 'X-Eval-Harness-Tenant' })}
                apiBase="/admin/eval-harness/api"
                routeBase=""
            />,
        );

        // Wait for at least one fetch to land — the host axios mock
        // captured every call into apiMock.calls so we can introspect
        // the headers the cross-mount delegated.
        await waitFor(() => {
            expect(apiMock.calls.length).toBeGreaterThan(0);
        });

        // The cross-mount routes all 8 controller calls through the
        // host axios instance with the tenant header set when
        // `config.tenant_header` is truthy. This is what enforces R30
        // tenant isolation server-side — the BE
        // `EvalHarnessUiTenantHeader` middleware reads the header and
        // injects it into TenantContext::current(). If the cross-mount
        // dropped the tenant header on the floor, BE writes would
        // collide across tenants.
        const apiModule = (await import('../../../../lib/api')) as unknown as {
            api: { request: { mock: { calls: Array<[AxiosRequestConfig]> } } };
        };
        const recordedRequests = apiModule.api.request.mock.calls.map(([cfg]) => cfg);
        expect(recordedRequests.length).toBeGreaterThan(0);
        for (const cfg of recordedRequests) {
            const headers = (cfg.headers ?? {}) as Record<string, string>;
            expect(headers['X-Eval-Harness-Tenant']).toBe('active');
            expect(headers['Accept']).toBe('application/json');
        }
    });
});
