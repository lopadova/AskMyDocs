import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { CatalogueEntry, CatalogueResponse } from './maintenance.api';

/*
 * Phase H2 — MaintenanceView unit tests. Same stubbing pattern as
 * LogsView.test.tsx: every child + hook is mocked so the view can
 * mount in jsdom. The catalogue hook is the only one that matters
 * for the scenarios below — history + scheduler are surfaced as
 * sibling panels but don't drive routing.
 */

type CatalogueMock = {
    data: CatalogueResponse | undefined;
    isLoading: boolean;
    isError: boolean;
};

const catalogueMock: CatalogueMock = {
    data: undefined,
    isLoading: false,
    isError: false,
};

vi.mock('./maintenance.api', () => ({
    useCommandCatalogue: () => catalogueMock,
    useCommandHistory: () => ({ data: undefined, isLoading: false, isError: false }),
    useSchedulerStatus: () => ({ data: undefined, isLoading: false, isError: false }),
}));

// Flatten AdminShell so we don't render the rail's navigation during
// unit tests. Same trick as LogsView.test.tsx.
vi.mock('../shell/AdminShell', () => ({
    AdminShell: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="admin-shell">{children}</div>
    ),
}));

// Stub the heavy sub-components so we can assert on MaintenanceView
// routing without recursing into their own rendering.
vi.mock('./CommandWizard', () => ({
    CommandWizard: () => <div data-testid="command-wizard-stub">wizard</div>,
}));
vi.mock('./CommandHistoryTable', () => ({
    CommandHistoryTable: () => <div data-testid="command-history-stub">history</div>,
}));
vi.mock('./SchedulerStatusCard', () => ({
    SchedulerStatusCard: () => <div data-testid="scheduler-status-stub">scheduler</div>,
}));

import { MaintenanceView } from './MaintenanceView';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

function mkEntry(overrides: Partial<CatalogueEntry> = {}): CatalogueEntry {
    return {
        description: 'test cmd',
        destructive: false,
        args_schema: {},
        requires_permission: 'commands.run',
        ...overrides,
    };
}

beforeEach(() => {
    catalogueMock.data = undefined;
    catalogueMock.isLoading = false;
    catalogueMock.isError = false;
});

describe('MaintenanceView', () => {
    it('renders the loading state while the catalogue query is in flight', () => {
        catalogueMock.isLoading = true;
        wrap(<MaintenanceView />);
        expect(screen.getByTestId('maintenance-view')).toBeInTheDocument();
        expect(screen.getByTestId('maintenance-panel-commands')).toHaveAttribute(
            'data-state',
            'loading',
        );
    });

    it('renders a card per catalogue entry grouped by category', () => {
        catalogueMock.data = {
            data: {
                'kb:validate-canonical': mkEntry(),
                'kb:prune-deleted': mkEntry({
                    destructive: true,
                    requires_permission: 'commands.destructive',
                }),
                'queue:retry': mkEntry(),
            },
        };
        wrap(<MaintenanceView />);

        // One card per command — testid matches config/admin.php keys.
        expect(screen.getByTestId('maintenance-card-kb:validate-canonical')).toBeInTheDocument();
        expect(screen.getByTestId('maintenance-card-kb:prune-deleted')).toBeInTheDocument();
        expect(screen.getByTestId('maintenance-card-queue:retry')).toBeInTheDocument();

        // Categories render even when a single command falls into them.
        expect(screen.getByTestId('maintenance-category-kb-content')).toBeInTheDocument();
        expect(screen.getByTestId('maintenance-category-pruning')).toBeInTheDocument();
        expect(screen.getByTestId('maintenance-category-queue')).toBeInTheDocument();
    });

    it('only renders commands the caller actually received in the catalogue payload', () => {
        // Backend filters by permission, so a viewer would get an
        // empty catalogue. A plain admin without commands.destructive
        // would get only the non-destructive subset. We simulate the
        // "no destructive commands returned" case.
        catalogueMock.data = {
            data: {
                'kb:validate-canonical': mkEntry(),
                'kb:rebuild-graph': mkEntry(),
            },
        };
        wrap(<MaintenanceView />);

        expect(screen.getByTestId('maintenance-card-kb:validate-canonical')).toBeInTheDocument();
        expect(screen.getByTestId('maintenance-card-kb:rebuild-graph')).toBeInTheDocument();

        // Destructive command not in the payload → not rendered.
        expect(screen.queryByTestId('maintenance-card-kb:prune-deleted')).not.toBeInTheDocument();
        expect(screen.queryByTestId('maintenance-category-pruning')).not.toBeInTheDocument();
    });

    it('surfaces the error state when the catalogue query fails', () => {
        catalogueMock.isError = true;
        wrap(<MaintenanceView />);
        expect(screen.getByTestId('maintenance-panel-commands')).toHaveAttribute(
            'data-state',
            'error',
        );
        expect(screen.getByTestId('maintenance-catalogue-error')).toBeInTheDocument();
    });

    it('switches to the history tab and renders the history panel', async () => {
        catalogueMock.data = { data: {} };
        wrap(<MaintenanceView />);

        await act(async () => {
            await userEvent.click(screen.getByTestId('maintenance-tab-history'));
        });
        expect(screen.getByTestId('maintenance-panel-history')).toBeInTheDocument();
        expect(screen.getByTestId('command-history-stub')).toBeInTheDocument();
    });

    it('opens the CommandWizard when a card run button is clicked', async () => {
        catalogueMock.data = {
            data: { 'kb:validate-canonical': mkEntry() },
        };
        wrap(<MaintenanceView />);

        await act(async () => {
            await userEvent.click(
                screen.getByTestId('maintenance-card-kb:validate-canonical-run'),
            );
        });
        expect(screen.getByTestId('command-wizard-stub')).toBeInTheDocument();
    });
});
