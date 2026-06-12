import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { WidgetSessionsView } from './WidgetSessionsView';

vi.mock('../../../lib/api', () => ({
    api: {
        get: vi.fn(),
        post: vi.fn(),
    },
}));

import { api } from '../../../lib/api';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = api as any;

function renderWithQuery(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('WidgetSessionsView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the view with testid (R11)', () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
        renderWithQuery(<WidgetSessionsView />);
        expect(screen.getByTestId('admin-widget-sessions-view')).toBeDefined();
    });

    it('status filter includes all 7 statuses incl. waiting_tool/waiting_user (#33)', () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
        renderWithQuery(<WidgetSessionsView />);

        const select = screen.getByTestId('admin-widget-sessions-filter-status');
        const values = Array.from(select.querySelectorAll('option')).map((o) => o.getAttribute('value'));
        expect(values).toEqual(
            expect.arrayContaining(['', 'active', 'waiting_user', 'waiting_tool', 'completed', 'blocked', 'aborted', 'error']),
        );
    });

    it('shows empty state when no sessions', async () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
        renderWithQuery(<WidgetSessionsView />);
        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-sessions-empty')).toBeDefined();
        });
    });

    it('shows loading state (R14)', () => {
        mockedApi.get.mockReturnValue(new Promise(() => {}));
        renderWithQuery(<WidgetSessionsView />);
        expect(screen.getByTestId('admin-widget-sessions-loading')).toBeDefined();
    });

    it('renders sessions in a table', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 1,
                        public_session_id: '550e8400-e29b-41d4-a716-446655440000',
                        widget_key: { id: 1, label: 'Test Key', public_key: 'pk_test' },
                        status: 'completed',
                        skill: 'askmydocs-assistant@1',
                        mission: null,
                        origin: 'https://example.com',
                        steps_count: 3,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
                meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
            },
        });

        renderWithQuery(<WidgetSessionsView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-sessions-table')).toBeDefined();
        });
        expect(screen.getByTestId('admin-widget-sessions-row-1')).toBeDefined();
        expect(screen.getByTestId('admin-widget-sessions-status-1').textContent).toBe('completed');
    });
});