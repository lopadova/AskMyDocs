import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { NotificationPanel } from './NotificationPanel';
import { api } from '../../lib/api';

const mockGet = vi.fn();
const mockPost = vi.fn();

// Copilot iter-4 #1 — the panel now fetches `/api/notifications/event-types`
// alongside the list. The spy below dispatches by URL so each query
// receives its own canned payload; list-specific tests still call
// `mockGet.mockResolvedValueOnce` to override the list response only.
const EVENT_TYPES_RESPONSE = { data: { data: ['kb_doc_created', 'kb_canonical_promoted'] } };

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    vi.spyOn(api, 'get').mockImplementation((url: string, ...args: unknown[]) => {
        if (url.includes('/event-types')) {
            return Promise.resolve(EVENT_TYPES_RESPONSE);
        }
        return mockGet(url, ...args);
    });
    vi.spyOn(api, 'post').mockImplementation(mockPost);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const TWO_UNREAD = {
    data: {
        data: [
            {
                id: 1,
                tenant_id: 'default',
                user_id: 7,
                event_type: 'kb_doc_created',
                payload: { title: 'Doc A' },
                channel_dispatch_log: [],
                created_at: '2026-05-18T09:00:00Z',
                read_at: null,
                dismissed_at: null,
            },
            {
                id: 2,
                tenant_id: 'default',
                user_id: 7,
                event_type: 'kb_canonical_promoted',
                payload: { slug: 'dec-cache-v2' },
                channel_dispatch_log: [],
                created_at: '2026-05-18T08:00:00Z',
                read_at: null,
                dismissed_at: null,
            },
        ],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 2, state: 'unread' },
    },
};

describe('NotificationPanel', () => {
    it('renders all 4 tabs and the event-type filter', async () => {
        mockGet.mockResolvedValueOnce(TWO_UNREAD);

        render(wrapped(<NotificationPanel />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-panel')).toHaveAttribute('data-state', 'ready');
        });

        for (const state of ['unread', 'read', 'dismissed', 'all']) {
            expect(screen.getByTestId(`notif-panel-tab-${state}`)).toBeInTheDocument();
        }
        expect(screen.getByTestId('notif-panel-filter-event_type')).toBeInTheDocument();
    });

    it('switches state when a tab is clicked', async () => {
        mockGet.mockResolvedValue(TWO_UNREAD);
        render(wrapped(<NotificationPanel />));

        await screen.findByTestId('notif-panel-tab-read');
        await userEvent.click(screen.getByTestId('notif-panel-tab-read'));

        await waitFor(() => {
            const lastCall = mockGet.mock.calls.at(-1);
            expect(lastCall?.[1]?.params?.state).toBe('read');
        });
    });

    it('filters by event_type when the dropdown changes', async () => {
        mockGet.mockResolvedValue(TWO_UNREAD);
        render(wrapped(<NotificationPanel />));

        await screen.findByTestId('notif-panel-filter-event_type');
        await userEvent.selectOptions(
            screen.getByTestId('notif-panel-filter-event_type'),
            'kb_canonical_promoted',
        );

        await waitFor(() => {
            const lastCall = mockGet.mock.calls.at(-1);
            expect(lastCall?.[1]?.params?.event_type).toBe('kb_canonical_promoted');
        });
    });

    it('renders one row per notification with mark-read + dismiss buttons', async () => {
        mockGet.mockResolvedValueOnce(TWO_UNREAD);
        render(wrapped(<NotificationPanel />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-panel-row-1-mark-read')).toBeInTheDocument();
            expect(screen.getByTestId('notif-panel-row-1-dismiss')).toBeInTheDocument();
            expect(screen.getByTestId('notif-panel-row-2-mark-read')).toBeInTheDocument();
            expect(screen.getByTestId('notif-panel-row-2-dismiss')).toBeInTheDocument();
        });
    });

    it('shows empty state when no rows', async () => {
        mockGet.mockResolvedValueOnce({
            data: {
                data: [],
                meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, state: 'unread' },
            },
        });
        render(wrapped(<NotificationPanel />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-panel')).toHaveAttribute('data-state', 'empty');
        });
        expect(screen.getByTestId('notif-panel-empty')).toBeInTheDocument();
    });

    it('surfaces an error state with a retry button on API failure', async () => {
        mockGet.mockRejectedValueOnce(new Error('boom'));
        render(wrapped(<NotificationPanel />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-panel')).toHaveAttribute('data-state', 'error');
        });
        expect(screen.getByTestId('notif-panel-error')).toBeInTheDocument();
        expect(screen.getByTestId('notif-panel-retry')).toBeInTheDocument();
    });

    it('disables Mark-all-read on non-unread tabs', async () => {
        mockGet.mockResolvedValue(TWO_UNREAD);
        render(wrapped(<NotificationPanel />));

        await userEvent.click(await screen.findByTestId('notif-panel-tab-all'));

        await waitFor(() => {
            expect(screen.getByTestId('notif-panel-mark-all-read')).toBeDisabled();
        });
    });

    it('fires mark-all-read mutation when bulk button is clicked on unread tab', async () => {
        mockGet.mockResolvedValue(TWO_UNREAD);
        mockPost.mockResolvedValueOnce({ data: { marked_read: 2 } });

        render(wrapped(<NotificationPanel />));
        await screen.findByTestId('notif-panel-mark-all-read');
        await userEvent.click(screen.getByTestId('notif-panel-mark-all-read'));

        await waitFor(() => {
            // Copilot iter-2 #3 — the FE forwards an empty body when no
            // event_type filter is active; the API client only adds
            // event_type when set.
            expect(mockPost).toHaveBeenCalledWith('/api/notifications/mark-all-read', {});
        });
    });

    it('forwards the active event_type filter to mark-all-read', async () => {
        mockGet.mockResolvedValue(TWO_UNREAD);
        mockPost.mockResolvedValueOnce({ data: { marked_read: 1 } });

        render(wrapped(<NotificationPanel />));

        await userEvent.selectOptions(
            await screen.findByTestId('notif-panel-filter-event_type'),
            'kb_canonical_promoted',
        );
        await userEvent.click(await screen.findByTestId('notif-panel-mark-all-read'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                '/api/notifications/mark-all-read',
                { event_type: 'kb_canonical_promoted' },
            );
        });
    });

    it('renders pagination controls and disables Prev on page 1', async () => {
        mockGet.mockResolvedValue({
            data: {
                ...TWO_UNREAD.data,
                meta: { current_page: 1, last_page: 3, per_page: 20, total: 45, state: 'unread' },
            },
        });
        render(wrapped(<NotificationPanel />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-panel-page-status')).toHaveTextContent('Page 1 of 3 (45 total)');
        });
        expect(screen.getByTestId('notif-panel-page-prev')).toBeDisabled();
        expect(screen.getByTestId('notif-panel-page-next')).toBeEnabled();
    });

    it('exposes aria-busy on the panel container while fetching', async () => {
        mockGet.mockResolvedValue(TWO_UNREAD);
        render(wrapped(<NotificationPanel />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-panel')).toHaveAttribute('data-state', 'ready');
        });
        // After the query settles aria-busy must be false (not absent
        // — React serialises boolean attributes as 'false'/'true' so
        // assistive tech can read it consistently).
        expect(screen.getByTestId('notif-panel')).toHaveAttribute('aria-busy', 'false');
    });
});
