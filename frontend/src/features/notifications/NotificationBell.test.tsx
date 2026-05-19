import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { NotificationBell } from './NotificationBell';
import { api } from '../../lib/api';

/**
 * v8.0/W1.4 — Vitest coverage for NotificationBell.
 *
 * HTTP boundary mocked via the shared axios instance. The test
 * exercises the full state machine:
 *   loading → unread badge → open dropdown → mark read → empty.
 */

const mockGet = vi.fn();
const mockPost = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('NotificationBell', () => {
    it('renders unread badge from /api/notifications/unread-count', async () => {
        mockGet.mockResolvedValueOnce({ data: { unread_count: 3 } });

        render(wrapped(<NotificationBell />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-bell')).toHaveAttribute('data-state', 'ready');
        });
        expect(screen.getByTestId('notif-bell-badge')).toHaveTextContent('3');
    });

    it('clamps badge to 99+ when count exceeds 99', async () => {
        mockGet.mockResolvedValueOnce({ data: { unread_count: 142 } });
        render(wrapped(<NotificationBell />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-bell-badge')).toHaveTextContent('99+');
        });
    });

    it('opens dropdown and shows the top-5 unread feed', async () => {
        mockGet
            .mockResolvedValueOnce({ data: { unread_count: 2 } })
            .mockResolvedValueOnce({
                data: {
                    data: [
                        {
                            id: 11,
                            tenant_id: 'default',
                            user_id: 1,
                            event_type: 'kb_doc_created',
                            payload: { title: 'Hello' },
                            channel_dispatch_log: [],
                            created_at: '2026-05-18T10:00:00Z',
                            read_at: null,
                            dismissed_at: null,
                        },
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 5, total: 1, state: 'unread' },
                },
            });

        render(wrapped(<NotificationBell />));

        await userEvent.click(await screen.findByTestId('notif-bell'));

        expect(await screen.findByTestId('notif-bell-dropdown')).toBeInTheDocument();
        expect(await screen.findByText(/New document: Hello/)).toBeInTheDocument();
        expect(screen.getByTestId('notif-bell-row-11-mark-read')).toBeInTheDocument();
    });

    it('marks a row read when the per-row button is clicked', async () => {
        mockGet
            .mockResolvedValueOnce({ data: { unread_count: 1 } })
            .mockResolvedValue({
                data: {
                    data: [
                        {
                            id: 11,
                            tenant_id: 'default',
                            user_id: 1,
                            event_type: 'kb_doc_created',
                            payload: { title: 'X' },
                            channel_dispatch_log: [],
                            created_at: '2026-05-18T10:00:00Z',
                            read_at: null,
                            dismissed_at: null,
                        },
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 5, total: 1, state: 'unread' },
                },
            });
        mockPost.mockResolvedValueOnce({
            data: {
                data: {
                    id: 11,
                    read_at: '2026-05-18T10:01:00Z',
                },
            },
        });

        render(wrapped(<NotificationBell />));
        await userEvent.click(await screen.findByTestId('notif-bell'));
        await userEvent.click(await screen.findByTestId('notif-bell-row-11-mark-read'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith('/api/notifications/11/mark-read');
        });
    });

    it('shows empty state when there are no unread notifications', async () => {
        mockGet
            .mockResolvedValueOnce({ data: { unread_count: 0 } })
            .mockResolvedValueOnce({
                data: {
                    data: [],
                    meta: { current_page: 1, last_page: 1, per_page: 5, total: 0, state: 'unread' },
                },
            });

        render(wrapped(<NotificationBell />));
        await userEvent.click(await screen.findByTestId('notif-bell'));

        expect(await screen.findByTestId('notif-bell-empty')).toBeInTheDocument();
        expect(screen.queryByTestId('notif-bell-badge')).not.toBeInTheDocument();
    });

    it('exposes data-state=error and a retry button when the count query fails', async () => {
        mockGet.mockRejectedValueOnce(new Error('network down'));
        render(wrapped(<NotificationBell />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-bell')).toHaveAttribute('data-state', 'error');
        });
        expect(screen.getByTestId('notif-bell-retry')).toBeInTheDocument();
    });

    it('surfaces a dedicated list-error placeholder when the dropdown query fails', async () => {
        // Copilot iter-2 #1 — a failed list query must NOT collapse
        // into the "no unread" empty state. Render an explicit error
        // row with a retry button so the user can distinguish "nothing
        // to show" from "we couldn't fetch your feed".
        mockGet
            .mockResolvedValueOnce({ data: { unread_count: 2 } })
            .mockRejectedValueOnce(new Error('list failure'));

        render(wrapped(<NotificationBell />));
        await userEvent.click(await screen.findByTestId('notif-bell'));

        expect(await screen.findByTestId('notif-bell-list-error')).toBeInTheDocument();
        expect(screen.getByTestId('notif-bell-list-retry')).toBeInTheDocument();
        expect(screen.queryByTestId('notif-bell-empty')).not.toBeInTheDocument();
    });

    it('shows an inline action-error banner when a mutation fails', async () => {
        // Copilot iter-2 #5 — swallowed mutation errors mislead the
        // operator. After mark-read 500s, an inline alert must be
        // visible inside the dropdown.
        mockGet
            .mockResolvedValueOnce({ data: { unread_count: 1 } })
            .mockResolvedValue({
                data: {
                    data: [
                        {
                            id: 42,
                            tenant_id: 'default',
                            user_id: 1,
                            event_type: 'kb_doc_created',
                            payload: { title: 'X' },
                            channel_dispatch_log: [],
                            created_at: '2026-05-18T10:00:00Z',
                            read_at: null,
                            dismissed_at: null,
                        },
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 5, total: 1, state: 'unread' },
                },
            });
        mockPost.mockRejectedValueOnce(new Error('500 boom'));

        render(wrapped(<NotificationBell />));
        await userEvent.click(await screen.findByTestId('notif-bell'));
        await userEvent.click(await screen.findByTestId('notif-bell-row-42-mark-read'));

        expect(await screen.findByTestId('notif-bell-action-error')).toBeInTheDocument();
    });

    it('exposes aria-busy on the bell button while the unread-count query is fetching', async () => {
        mockGet.mockResolvedValueOnce({ data: { unread_count: 0 } });
        render(wrapped(<NotificationBell />));

        // After the query settles aria-busy must serialise to 'false'
        // — assistive tech reads the boolean attribute consistently.
        await waitFor(() => {
            expect(screen.getByTestId('notif-bell')).toHaveAttribute('aria-busy', 'false');
        });
    });

    it('dropdown exposes data-state reflecting the list query (Copilot iter-4 #5)', async () => {
        // Empty list path → data-state='empty'.
        mockGet
            .mockResolvedValueOnce({ data: { unread_count: 0 } })
            .mockResolvedValueOnce({
                data: {
                    data: [],
                    meta: { current_page: 1, last_page: 1, per_page: 5, total: 0, state: 'unread' },
                },
            });

        render(wrapped(<NotificationBell />));
        await userEvent.click(await screen.findByTestId('notif-bell'));

        await waitFor(() => {
            expect(screen.getByTestId('notif-bell-dropdown')).toHaveAttribute('data-state', 'empty');
        });
    });

    it('disables Mark-all-read when there are 0 unread', async () => {
        mockGet
            .mockResolvedValueOnce({ data: { unread_count: 0 } })
            .mockResolvedValueOnce({
                data: {
                    data: [],
                    meta: { current_page: 1, last_page: 1, per_page: 5, total: 0, state: 'unread' },
                },
            });

        render(wrapped(<NotificationBell />));
        await userEvent.click(await screen.findByTestId('notif-bell'));

        await waitFor(() => {
            expect(screen.getByTestId('notif-bell-mark-all-read')).toBeDisabled();
        });
    });
});
