import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

type MockState = {
    data: { lines: string[]; truncated: boolean; total_scanned: number } | undefined;
    isLoading: boolean;
    isError: boolean;
    error: unknown;
};

const appState: MockState = {
    data: undefined,
    isLoading: false,
    isError: false,
    error: null,
};

vi.mock('./logs.api', () => ({
    useApplicationLog: () => ({
        data: appState.data,
        isLoading: appState.isLoading,
        isError: appState.isError,
        error: appState.error,
        refetch: () => {},
    }),
}));

import { ApplicationLogTab } from './ApplicationLogTab';

function wrap(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('ApplicationLogTab', () => {
    beforeEach(() => {
        appState.data = undefined;
        appState.isLoading = false;
        appState.isError = false;
        appState.error = null;
    });

    it('renders the loading state first', () => {
        appState.isLoading = true;
        wrap(<ApplicationLogTab />);
        expect(screen.getByTestId('application-log-loading')).toBeInTheDocument();
        expect(screen.getByTestId('application-log')).toHaveAttribute('data-state', 'loading');
    });

    it('renders ready state and pre block with joined lines', () => {
        appState.data = {
            lines: ['[2025-01-01 10:00:00] local.INFO: a', '[2025-01-01 10:01:00] local.ERROR: b'],
            truncated: false,
            total_scanned: 2,
        };
        wrap(<ApplicationLogTab />);
        const pre = screen.getByTestId('application-log-lines');
        expect(pre).toBeInTheDocument();
        expect(pre.textContent).toContain('local.INFO: a');
        expect(pre.textContent).toContain('local.ERROR: b');
    });

    it('surfaces the 422 validation error body', () => {
        appState.isError = true;
        appState.error = {
            response: {
                status: 422,
                data: {
                    message: "Invalid log filename 'secrets.txt'.",
                    errors: { file: ["Invalid log filename 'secrets.txt'."] },
                },
            },
        };
        wrap(<ApplicationLogTab />);
        const err = screen.getByTestId('application-log-error');
        expect(err).toBeInTheDocument();
        expect(err.textContent).toContain('422');
        expect(err.textContent).toContain('Invalid log filename');
    });

    it('exposes the file picker / level / tail / live toggles with testids', () => {
        appState.data = { lines: [], truncated: false, total_scanned: 0 };
        wrap(<ApplicationLogTab />);
        expect(screen.getByTestId('application-log-file')).toBeInTheDocument();
        expect(screen.getByTestId('application-log-file-custom')).toBeInTheDocument();
        expect(screen.getByTestId('application-log-level')).toBeInTheDocument();
        expect(screen.getByTestId('application-log-tail')).toBeInTheDocument();
        expect(screen.getByTestId('application-log-refresh')).toBeInTheDocument();
        expect(screen.getByTestId('application-log-live-toggle')).toBeInTheDocument();
    });
});
