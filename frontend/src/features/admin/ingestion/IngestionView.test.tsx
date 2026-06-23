import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ConnectorEntry } from '../connectors/connectors.api';
import type { QueueDepth, SyncRunDto } from './ingestion.api';

/*
 * v8.21 (Ciclo 2) — IngestionView unit tests. Mocks the queue/sync-run hooks
 * + the connectors list so loading / error / ready / empty states assert
 * without a backend. R16: each test drives the state it claims.
 */

interface QueryMock<T> {
    data: T | undefined;
    isLoading: boolean;
    isError: boolean;
}

const queueMock: QueryMock<QueueDepth[]> = { data: undefined, isLoading: false, isError: false };
const runsMock: QueryMock<SyncRunDto[]> = { data: undefined, isLoading: false, isError: false };
const connectorsMock: QueryMock<ConnectorEntry[]> = { data: [], isLoading: false, isError: false };

vi.mock('./ingestion-hooks', () => ({
    useQueueDepths: () => ({ ...queueMock, refetch: vi.fn() }),
    useSyncRuns: () => ({ ...runsMock, refetch: vi.fn() }),
}));

vi.mock('../connectors/connectors-hooks', () => ({
    useConnectors: () => ({ ...connectorsMock, refetch: vi.fn() }),
}));

vi.mock('../shell/AdminShell', () => ({
    AdminShell: ({ children }: { children: React.ReactNode }) => <div data-testid="admin-shell">{children}</div>,
}));

import { IngestionView } from './IngestionView';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
    queueMock.data = undefined;
    queueMock.isLoading = false;
    queueMock.isError = false;
    runsMock.data = undefined;
    runsMock.isLoading = false;
    runsMock.isError = false;
    connectorsMock.data = [];
    connectorsMock.isLoading = false;
    connectorsMock.isError = false;
});

function entry(installations: { id: number; label: string }[]): ConnectorEntry {
    return {
        key: 'imap',
        display_name: 'Email (IMAP)',
        icon_url: '/i.svg',
        oauth_scopes: [],
        auth_kind: 'credential',
        credential_form_schema: null,
        installations: installations.map((i) => ({
            id: i.id,
            label: i.label,
            project_key: null,
            status: 'active',
            last_sync_at: null,
            error: null,
        })),
    };
}

describe('IngestionView', () => {
    it('shows the queue loading state', () => {
        queueMock.isLoading = true;
        wrap(<IngestionView />);
        expect(screen.getByTestId('admin-ingestion')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('admin-ingestion-queue-loading')).toBeInTheDocument();
    });

    it('surfaces the queue error state with a retry', () => {
        queueMock.isError = true;
        wrap(<IngestionView />);
        expect(screen.getByTestId('admin-ingestion')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('admin-ingestion-queue-error')).toBeInTheDocument();
        expect(screen.getByTestId('admin-ingestion-queue-retry')).toBeInTheDocument();
    });

    it('renders a card per queue role with its depth', () => {
        queueMock.data = [
            { name: 'connectors', role: 'connector-sync', depth: 4 },
            { name: 'kb-ingest', role: 'kb-ingest', depth: 0 },
            { name: 'high', role: 'default', depth: null },
        ];
        wrap(<IngestionView />);
        expect(screen.getByTestId('ingestion-queue-connector-sync')).toHaveAttribute('data-depth', '4');
        expect(screen.getByTestId('ingestion-queue-kb-ingest')).toHaveAttribute('data-depth', '0');
        // null depth renders the n/a sentinel.
        expect(screen.getByTestId('ingestion-queue-default')).toHaveAttribute('data-depth', 'n/a');
    });

    it('shows a connectors loading state (not the empty sentinel) while accounts load', () => {
        queueMock.data = [];
        connectorsMock.isLoading = true;
        connectorsMock.data = undefined;
        wrap(<IngestionView />);
        expect(screen.getByTestId('admin-ingestion-accounts-loading')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-ingestion-no-accounts')).not.toBeInTheDocument();
    });

    it('surfaces a connectors error (not the empty sentinel) on failure', () => {
        queueMock.data = [];
        connectorsMock.isError = true;
        connectorsMock.data = undefined;
        wrap(<IngestionView />);
        expect(screen.getByTestId('admin-ingestion-accounts-error')).toBeInTheDocument();
        expect(screen.getByTestId('admin-ingestion-accounts-retry')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-ingestion-no-accounts')).not.toBeInTheDocument();
    });

    it('shows the no-accounts empty state when nothing is installed', () => {
        queueMock.data = [];
        connectorsMock.data = [];
        wrap(<IngestionView />);
        expect(screen.getByTestId('admin-ingestion-no-accounts')).toBeInTheDocument();
    });

    it('lists sync runs for the selected account', () => {
        queueMock.data = [];
        connectorsMock.data = [entry([{ id: 7, label: 'support' }])];
        runsMock.data = [
            {
                id: 99,
                connector_name: 'imap',
                label: 'support',
                queue: 'connectors',
                status: 'success',
                started_at: '2026-06-23T00:00:00Z',
                finished_at: '2026-06-23T00:00:02Z',
                duration_ms: 2000,
                items_discovered: 5,
                items_failed: 0,
                error: null,
            },
        ];
        wrap(<IngestionView />);
        expect(screen.getByTestId('ingestion-account-select')).toBeInTheDocument();
        const row = screen.getByTestId('ingestion-run-99');
        expect(row).toHaveAttribute('data-status', 'success');
        expect(row).toHaveTextContent('5');
    });

    it('shows the runs-empty state for an account with no runs', () => {
        queueMock.data = [];
        connectorsMock.data = [entry([{ id: 7, label: 'support' }])];
        runsMock.data = [];
        wrap(<IngestionView />);
        expect(screen.getByTestId('admin-ingestion-runs-empty')).toBeInTheDocument();
    });
});
