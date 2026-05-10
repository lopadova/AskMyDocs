import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup, within } from '@testing-library/react';
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

type MockResult =
    | { kind: 'resolve'; value: unknown }
    | { kind: 'reject'; error: Error }
    | { kind: 'pending' };

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
            if (result.kind === 'pending') {
                // Never resolves — caller is testing the pre-resolve
                // frame. The test must not await on this fetch.
                return new Promise(() => {});
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

    it('does NOT render literal "Disabled" / "0" before /status resolves — loading placeholder used instead (Copilot iter 1: R14)', async () => {
        // `pending` makes the mocked adminFetch return a never-resolving
        // promise, so the Overview is stuck in its pre-resolve frame
        // for the duration of the assertion window.
        apiMock.responses['status'] = { kind: 'pending' };

        render(<PiiRedactorAdminApp config={makeConfig()} />);

        const overview = await screen.findByTestId('admin-pii-redactor-overview');
        expect(overview).toHaveAttribute('data-state', 'loading');
        expect(overview).toHaveAttribute('aria-busy', 'true');

        // Pre-resolve frame: the Engine / Detectors cards must NOT
        // claim a definitive 'Disabled' / '0' value. Both were the
        // bug Copilot iter 1 caught.
        expect(overview).not.toHaveTextContent('Disabled');
        // Scoped to the overview section — the sidebar nav also has a
        // 'Detectors' button, so an unscoped getByText would match
        // multiple elements.
        const detectorsLabel = within(overview).getByText('Detectors');
        const detectorsCard = detectorsLabel.closest('.pra-panel');
        expect(detectorsCard).not.toBeNull();
        expect(detectorsCard?.textContent ?? '').not.toMatch(/\b0\b/);

        // All four cards show the same loading placeholder so the UI
        // is internally consistent across the grid.
        const placeholders = overview.querySelectorAll('strong');
        expect(placeholders.length).toBe(4);
        placeholders.forEach((node) => expect(node.textContent).toBe('—'));
    });

    it('renders the "unavailable" placeholder on every Overview card when /status fails (Copilot iter 1: R14)', async () => {
        apiMock.responses['status'] = {
            kind: 'reject',
            error: new AdminApiError('Not found.', 404, { message: 'Not found.' }),
        };

        render(<PiiRedactorAdminApp config={makeConfig()} />);

        // Wait for the error path to settle — the top-level alert
        // rendering proves the fetch rejected.
        await screen.findByTestId('admin-pii-redactor-status-error');

        const overview = screen.getByTestId('admin-pii-redactor-overview');
        expect(overview).toHaveAttribute('data-state', 'error');
        // Every card shows the 'unavailable' placeholder — no card
        // claims a definitive 'Disabled' / '0' value when the fetch
        // failed.
        expect(overview).not.toHaveTextContent('Disabled');
        const placeholders = overview.querySelectorAll('strong');
        expect(placeholders.length).toBe(4);
        placeholders.forEach((node) => expect(node.textContent).toBe('unavailable'));
    });

    it('exposes an aria-label on the Ctrl K shortcut button so screen readers announce its purpose (Copilot iter 1: R15)', async () => {
        apiMock.responses['status'] = {
            kind: 'resolve',
            value: { package: {}, strategies: ['mask'], snapshot: {} },
        };
        render(<PiiRedactorAdminApp config={makeConfig()} />);

        const shortcut = await screen.findByTestId('admin-pii-redactor-shortcut-playground');
        // accessibleName must describe the action, not be the keyboard
        // hint alone — `title` attribute is not a reliable accessible
        // name across screen-reader engines.
        expect(shortcut).toHaveAttribute('aria-label', 'Open playground');
        expect(shortcut).toHaveAttribute('aria-keyshortcuts', 'Control+K');
        // The button is now reachable BY accessible name (R15).
        const byName = screen.getByRole('button', { name: 'Open playground' });
        expect(byName).toBe(shortcut);
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
