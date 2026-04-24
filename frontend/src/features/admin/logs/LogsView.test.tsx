import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

// Stub every tab so LogsView can mount without the real HTTP layer.
vi.mock('./ChatLogsTab', () => ({
    ChatLogsTab: () => <div data-testid="stub-chat">chat</div>,
}));
vi.mock('./AuditTab', () => ({
    AuditTab: () => <div data-testid="stub-audit">audit</div>,
}));
vi.mock('./ApplicationLogTab', () => ({
    ApplicationLogTab: () => <div data-testid="stub-app">app</div>,
}));
vi.mock('./ActivityTab', () => ({
    ActivityTab: () => <div data-testid="stub-activity">activity</div>,
}));
vi.mock('./FailedJobsTab', () => ({
    FailedJobsTab: () => <div data-testid="stub-failed">failed</div>,
}));

// AdminShell renders a rail that navigates — stub it flat.
vi.mock('../shell/AdminShell', () => ({
    AdminShell: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="admin-shell">{children}</div>
    ),
}));

import { LogsView } from './LogsView';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('LogsView', () => {
    beforeEach(() => {
        window.history.replaceState(null, '', '/app/admin/logs');
    });

    it('renders the chat tab by default when ?tab is absent', () => {
        wrap(<LogsView />);
        expect(screen.getByTestId('logs-view')).toBeInTheDocument();
        expect(screen.getByTestId('stub-chat')).toBeInTheDocument();
        expect(screen.getByTestId('logs-tab-chat')).toHaveAttribute('data-active', 'true');
    });

    it('deep-links to the tab named in ?tab=', () => {
        window.history.replaceState(null, '', '/app/admin/logs?tab=failed');
        wrap(<LogsView />);
        expect(screen.getByTestId('stub-failed')).toBeInTheDocument();
        expect(screen.getByTestId('logs-tab-failed')).toHaveAttribute('data-active', 'true');
    });

    it('switches panels on tab click and syncs the URL', async () => {
        wrap(<LogsView />);
        await act(async () => {
            await userEvent.click(screen.getByTestId('logs-tab-app'));
        });
        expect(screen.getByTestId('stub-app')).toBeInTheDocument();
        expect(window.location.search).toContain('tab=app');

        await act(async () => {
            await userEvent.click(screen.getByTestId('logs-tab-audit'));
        });
        expect(screen.getByTestId('stub-audit')).toBeInTheDocument();
        expect(window.location.search).toContain('tab=audit');
    });

    it('ignores unknown ?tab= values and falls back to chat', () => {
        window.history.replaceState(null, '', '/app/admin/logs?tab=not-a-tab');
        wrap(<LogsView />);
        expect(screen.getByTestId('stub-chat')).toBeInTheDocument();
    });

    it('exposes one testid per tab button', () => {
        wrap(<LogsView />);
        for (const slug of ['chat', 'audit', 'app', 'activity', 'failed']) {
            expect(screen.getByTestId(`logs-tab-${slug}`)).toBeInTheDocument();
        }
    });
});
