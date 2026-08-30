import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ConnectorEntry } from '../connectors/connectors.api';
import type { ImapBackfillDto, ImapBackfillStateDto, QueueDepth, SyncRunDto } from './ingestion.api';

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
const backfillMock: QueryMock<ImapBackfillStateDto> = {
    data: { enabled: true, backfill: null },
    isLoading: false,
    isError: false,
};
const startBackfillMock = { mutate: vi.fn(), isPending: false, isError: false };

vi.mock('./ingestion-hooks', () => ({
    useQueueDepths: () => ({ ...queueMock, refetch: vi.fn() }),
    useSyncRuns: () => ({ ...runsMock, refetch: vi.fn() }),
    useImapBackfill: () => ({ ...backfillMock, refetch: vi.fn() }),
    useStartImapBackfill: () => startBackfillMock,
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
    backfillMock.data = { enabled: true, backfill: null };
    backfillMock.isLoading = false;
    backfillMock.isError = false;
    startBackfillMock.mutate.mockReset();
    startBackfillMock.isPending = false;
    startBackfillMock.isError = false;
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
            status: 'active' as const,
            last_sync_at: null,
            error: null,
            folders: { include: [] },
            date_window_days: null,
        })),
    };
}

function backfill(
    status: ImapBackfillDto['status'],
    retryMode: ImapBackfillDto['retry_mode'] = status === 'failed' ? 'resume' : null,
): ImapBackfillDto {
    return {
        id: 1,
        installation_id: 7,
        status,
        retry_mode: retryMode,
        total_messages: 128_199,
        processed_messages: status === 'completed' ? 128_000 : 3_500,
        dispatched_documents: 3_500,
        total_windows: 56,
        completed_windows: status === 'completed' ? 56 : 3,
        progress_percent: status === 'completed' ? 100 : 2.73,
        batch_size: 100,
        started_at: '2026-08-13T12:00:00Z',
        completed_at: status === 'completed' ? '2026-08-14T12:00:00Z' : null,
        heartbeat_at: '2026-08-13T12:05:00Z',
        current_window: status === 'running'
            ? {
                mailbox: 'INBOX',
                start: '2026-01-01',
                end: '2026-02-01',
                processed_messages: 100,
                expected_messages: 101,
                last_uid: 123,
            }
            : null,
        last_error: status === 'failed' ? { message: 'Exchange closed the connection' } : null,
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

    it('shows a queues empty-state when the depths array is empty', () => {
        queueMock.data = [];
        wrap(<IngestionView />);
        expect(screen.getByTestId('admin-ingestion-queues-empty')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-ingestion-queues')).not.toBeInTheDocument();
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

    it('exposes the IMAP panel loading state and busy semantics', () => {
        queueMock.data = [];
        connectorsMock.data = [entry([{ id: 7, label: 'support' }])];
        backfillMock.data = undefined;
        backfillMock.isLoading = true;

        wrap(<IngestionView />);

        expect(screen.getByTestId('imap-backfill')).toHaveAttribute('data-state', 'loading');
        expect(screen.getByTestId('imap-backfill')).toHaveAttribute('aria-busy', 'true');
    });

    it('starts an empty full-history import from the observable panel', () => {
        queueMock.data = [];
        connectorsMock.data = [entry([{ id: 7, label: 'support' }])];

        wrap(<IngestionView />);

        const panel = screen.getByTestId('imap-backfill');
        expect(panel).toHaveAttribute('data-state', 'empty');
        expect(panel).toHaveAttribute('aria-busy', 'false');
        fireEvent.click(screen.getByTestId('imap-backfill-start'));
        expect(startBackfillMock.mutate).toHaveBeenCalledWith(7);
    });

    it('shows progress and keeps retry available after a terminal failure', () => {
        queueMock.data = [];
        connectorsMock.data = [entry([{ id: 7, label: 'support' }])];
        backfillMock.data = { enabled: true, backfill: backfill('failed') };

        wrap(<IngestionView />);

        expect(screen.getByTestId('imap-backfill')).toHaveAttribute('data-state', 'ready');
        // Formatted the way the component formats it, not with a hard-coded
        // en-US string. `toLocaleString()` follows the machine locale, so a
        // literal '128,199' passes only in CI and fails on, say, an Italian
        // machine that renders '128.199' -- a red suite that has nothing to
        // do with the code under test, and reads like a real regression.
        expect(screen.getByTestId('imap-backfill-progress')).toHaveTextContent(
            `${(3500).toLocaleString()} / ${(128199).toLocaleString()}`,
        );
        expect(screen.getByTestId('imap-backfill-start')).toHaveTextContent('Resume full import');
        fireEvent.click(screen.getByTestId('imap-backfill-start'));
        expect(startBackfillMock.mutate).toHaveBeenCalledWith(7);
    });

    it('surfaces an explicit restart when UIDVALIDITY invalidates the snapshot', () => {
        queueMock.data = [];
        connectorsMock.data = [entry([{ id: 7, label: 'support' }])];
        backfillMock.data = { enabled: true, backfill: backfill('failed', 'restart') };

        wrap(<IngestionView />);

        expect(screen.getByTestId('imap-backfill-start')).toHaveTextContent('Restart full import');
    });

    it('surfaces disabled and start-failure states explicitly', () => {
        queueMock.data = [];
        connectorsMock.data = [entry([{ id: 7, label: 'support' }])];
        backfillMock.data = { enabled: false, backfill: null };
        const { rerender } = wrap(<IngestionView />);

        expect(screen.getByTestId('imap-backfill')).toHaveAttribute('data-state', 'disabled');
        expect(screen.getByTestId('imap-backfill-disabled')).toBeInTheDocument();
        expect(screen.queryByTestId('imap-backfill-start')).not.toBeInTheDocument();

        backfillMock.data = { enabled: true, backfill: null };
        startBackfillMock.isError = true;
        rerender(
            <QueryClientProvider client={new QueryClient()}>
                <IngestionView />
            </QueryClientProvider>,
        );
        expect(screen.getByTestId('imap-backfill')).toHaveAttribute('data-state', 'error');
        expect(screen.getByRole('alert')).toHaveTextContent('Could not start the full import.');
    });
});
