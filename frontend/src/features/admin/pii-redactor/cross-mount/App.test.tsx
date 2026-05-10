import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { PiiRedactorAdminConfig } from './types';

/*
 * v4.4/W2 — cross-mount App tests.
 *
 * Mocks `./adminApi` so each scenario controls the package's
 * /admin/pii-redactor/api/* response payloads without hitting the
 * backend. The mock is mutable per-test so failure-path scenarios
 * can throw `AdminApiError`.
 *
 * R16: each test name promises a behaviour and the body drives THAT
 * behaviour to a strict assertion — name and body match, no trivially
 * passing trues.
 */

type MockResult = { kind: 'resolve'; value: unknown } | { kind: 'reject'; error: Error };

const apiMock: { responses: Record<string, MockResult>; calls: Array<{ path: string; opts?: unknown }> } = {
    responses: {},
    calls: [],
};

vi.mock('./adminApi', async () => {
    const actual = await vi.importActual<typeof import('./adminApi')>('./adminApi');
    return {
        ...actual,
        adminFetch: vi.fn(async (_apiBase: string, path: string, options?: unknown) => {
            apiMock.calls.push({ path, opts: options });
            const result = apiMock.responses[normalisePath(path)] ?? apiMock.responses['*'];
            if (!result) {
                return null;
            }
            if (result.kind === 'reject') {
                throw result.error;
            }
            return result.value;
        }),
    };
});

import PiiRedactorAdminApp from './App';
import { AdminApiError } from './adminApi';

function normalisePath(path: string): string {
    const queryStart = path.indexOf('?');
    return queryStart === -1 ? path : path.slice(0, queryStart);
}

function makeConfig(overrides: Partial<PiiRedactorAdminConfig> = {}): PiiRedactorAdminConfig {
    return {
        apiBase: '/admin/pii-redactor/api',
        routePrefix: '/admin/pii-redactor',
        userDisplay: 'Test Operator',
        abilities: { view: true, detokenise: true, rawSamples: true },
        ...overrides,
    };
}

beforeEach(() => {
    apiMock.responses = {};
    apiMock.calls = [];
});

afterEach(() => {
    cleanup();
});

describe('PiiRedactorAdminApp (cross-mount)', () => {
    it('renders Overview by default with the Engine card sourced from the status snapshot', async () => {
        apiMock.responses['status'] = {
            kind: 'resolve',
            value: {
                package: {},
                strategies: ['mask', 'tokenise'],
                snapshot: {
                    enabled: true,
                    default_strategy: 'mask',
                    token_store: { driver: 'database' },
                    detectors: [{ name: 'email' }, { name: 'phone' }],
                },
            },
        };

        render(<PiiRedactorAdminApp config={makeConfig()} />);

        // Default page is overview — both the wrapper marker and the
        // overview testid prove it.
        const app = await screen.findByTestId('admin-pii-redactor-app');
        expect(app).toHaveAttribute('data-page', 'overview');
        expect(screen.getByTestId('admin-pii-redactor-overview')).toBeInTheDocument();

        // The Engine card pulls from snapshot.enabled — assertion
        // proves the status fetch resolved AND the Overview reads from
        // the resolved payload (not the loading defaults).
        await waitFor(() => {
            expect(screen.getByText('Engine')).toBeInTheDocument();
            expect(screen.getByText('Enabled')).toBeInTheDocument();
        });
        expect(screen.getByText('mask')).toBeInTheDocument();
        expect(screen.getByText('database')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
    });

    it('navigates from Overview to Playground when the sidebar Playground button is clicked', async () => {
        apiMock.responses['status'] = {
            kind: 'resolve',
            value: { package: {}, strategies: ['mask'], snapshot: { enabled: false } },
        };
        const user = userEvent.setup();
        render(<PiiRedactorAdminApp config={makeConfig()} />);

        const app = await screen.findByTestId('admin-pii-redactor-app');
        expect(app).toHaveAttribute('data-page', 'overview');

        const playgroundNav = screen.getByTestId('admin-pii-redactor-nav-playground');
        await user.click(playgroundNav);

        // After the click the wrapper flips data-page AND the
        // playground panel mounts. Both assertions matter — the
        // first proves the state transition fired, the second proves
        // PageView routes off the new state.
        expect(app).toHaveAttribute('data-page', 'playground');
        expect(screen.getByTestId('admin-pii-redactor-playground')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-pii-redactor-overview')).not.toBeInTheDocument();
    });

    it('surfaces an AdminApiError from /status as a top-level alert (R7/R14: failures are loud)', async () => {
        apiMock.responses['status'] = {
            kind: 'reject',
            error: new AdminApiError('PII Redactor admin is disabled.', 404, { message: 'PII Redactor admin is disabled.' }),
        };

        render(<PiiRedactorAdminApp config={makeConfig()} />);

        const alert = await screen.findByTestId('admin-pii-redactor-status-error');
        expect(alert).toHaveAttribute('role', 'alert');
        expect(alert).toHaveTextContent('PII Redactor admin is disabled.');
    });

    it('disables the raw-samples checkbox when the operator lacks the rawSamples ability (FE mirrors BE Gate)', async () => {
        apiMock.responses['status'] = {
            kind: 'resolve',
            value: { package: {}, strategies: ['mask'], snapshot: {} },
        };
        const user = userEvent.setup();
        render(
            <PiiRedactorAdminApp
                config={makeConfig({
                    abilities: { view: true, detokenise: true, rawSamples: false },
                })}
            />,
        );

        await user.click(await screen.findByTestId('admin-pii-redactor-nav-playground'));
        const rawCheckbox = screen.getByTestId('admin-pii-redactor-playground-raw') as HTMLInputElement;
        expect(rawCheckbox.disabled).toBe(true);
    });

    it('does NOT touch document.documentElement.dataset.theme — host owns the theme (cross-mount drops package toggle)', async () => {
        apiMock.responses['status'] = {
            kind: 'resolve',
            value: { package: {}, strategies: ['mask'], snapshot: {} },
        };
        const before = document.documentElement.dataset.theme ?? '';

        render(<PiiRedactorAdminApp config={makeConfig()} />);
        await screen.findByTestId('admin-pii-redactor-app');

        // R16: assertion proves the cross-mount port REMOVED the
        // package's `useEffect(() => { document.documentElement.dataset.theme = ... })`
        // — if a future regression re-introduces it, this test catches
        // the symptom (host theme attribute mutates) immediately.
        const after = document.documentElement.dataset.theme ?? '';
        expect(after).toBe(before);
    });
});
