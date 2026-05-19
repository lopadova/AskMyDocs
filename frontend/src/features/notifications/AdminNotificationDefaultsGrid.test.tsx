import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { AdminNotificationDefaultsGrid } from './AdminNotificationDefaultsGrid';
import { api } from '../../lib/api';

const mockGet = vi.fn();
const mockPut = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPut.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'put').mockImplementation(mockPut);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function wrapped(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

const DEFAULT_RESPONSE = {
    data: {
        event_types: ['kb_doc_created', 'kb_canonical_promoted'],
        channels: ['in_app', 'email', 'discord'],
        registered_channels: ['in_app', 'email'],
        platform_defaults: { in_app: true, email: false, discord: false },
        defaults: [
            { event_type: 'kb_doc_created', channel: 'email', enabled: true },
        ],
    },
};

describe('AdminNotificationDefaultsGrid', () => {
    it('renders the grid seeded with platform_defaults + tenant overrides', async () => {
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<AdminNotificationDefaultsGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults')).toHaveAttribute('data-state', 'ready');
        });

        // Platform defaults seeded.
        expect(screen.getByTestId('notif-defaults-cell-kb_doc_created-in_app-toggle')).toBeChecked();
        expect(screen.getByTestId('notif-defaults-cell-kb_canonical_promoted-in_app-toggle')).toBeChecked();

        // Tenant override for the email column wins.
        expect(screen.getByTestId('notif-defaults-cell-kb_doc_created-email-toggle')).toBeChecked();
        // No tenant override for kb_canonical_promoted × email → platform default (false).
        expect(screen.getByTestId('notif-defaults-cell-kb_canonical_promoted-email-toggle')).not.toBeChecked();

        // Un-registered channel rendered disabled.
        expect(screen.getByTestId('notif-defaults-cell-kb_doc_created-discord-toggle')).toBeDisabled();
    });

    it('flips a single cell and marks the grid dirty', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<AdminNotificationDefaultsGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-defaults-cell-kb_canonical_promoted-in_app-toggle'));

        expect(screen.getByTestId('notif-defaults-cell-kb_canonical_promoted-in_app-toggle')).not.toBeChecked();
        expect(screen.getByTestId('notif-defaults-dirty-indicator')).toBeInTheDocument();
        expect(screen.getByTestId('notif-defaults-save')).not.toBeDisabled();
    });

    it('row bulk-on enables every REGISTERED channel for the event type', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<AdminNotificationDefaultsGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-defaults-row-kb_canonical_promoted-enable-all'));

        expect(screen.getByTestId('notif-defaults-cell-kb_canonical_promoted-in_app-toggle')).toBeChecked();
        expect(screen.getByTestId('notif-defaults-cell-kb_canonical_promoted-email-toggle')).toBeChecked();
        // Un-registered channel stays unchecked.
        expect(screen.getByTestId('notif-defaults-cell-kb_canonical_promoted-discord-toggle')).not.toBeChecked();
    });

    it('column bulk-off disables every row for the channel', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<AdminNotificationDefaultsGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-defaults-column-in_app-disable-all'));

        expect(screen.getByTestId('notif-defaults-cell-kb_doc_created-in_app-toggle')).not.toBeChecked();
        expect(screen.getByTestId('notif-defaults-cell-kb_canonical_promoted-in_app-toggle')).not.toBeChecked();
    });

    it('save posts every cell as a row to the admin PUT endpoint', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);
        mockPut.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<AdminNotificationDefaultsGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-defaults-cell-kb_canonical_promoted-email-toggle'));
        await user.click(screen.getByTestId('notif-defaults-save'));

        await waitFor(() => {
            expect(mockPut).toHaveBeenCalledTimes(1);
        });
        const [url, body] = mockPut.mock.calls[0];
        expect(url).toBe('/api/admin/notifications/defaults');
        // 2 event_types × 3 channels = 6 rows.
        expect(body.defaults).toHaveLength(6);
        const edited = body.defaults.find(
            (r: { event_type: string; channel: string; enabled: boolean }) =>
                r.event_type === 'kb_canonical_promoted' && r.channel === 'email',
        );
        expect(edited.enabled).toBe(true);
    });

    it('discard restores the last-saved snapshot and clears dirty', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<AdminNotificationDefaultsGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-defaults-cell-kb_doc_created-in_app-toggle'));
        expect(screen.getByTestId('notif-defaults-cell-kb_doc_created-in_app-toggle')).not.toBeChecked();
        expect(screen.getByTestId('notif-defaults-dirty-indicator')).toBeInTheDocument();

        await user.click(screen.getByTestId('notif-defaults-discard'));

        expect(screen.getByTestId('notif-defaults-cell-kb_doc_created-in_app-toggle')).toBeChecked();
        expect(screen.queryByTestId('notif-defaults-dirty-indicator')).toBeNull();
    });

    it('renders the error state when the GET fails', async () => {
        mockGet.mockRejectedValueOnce(new Error('500'));

        render(wrapped(<AdminNotificationDefaultsGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults')).toHaveAttribute('data-state', 'error');
        });
        expect(screen.getByTestId('notif-defaults-error')).toBeInTheDocument();
        expect(screen.getByTestId('notif-defaults-retry')).toBeInTheDocument();
    });

    it('treats zero stored defaults as inherently dirty so the super-admin can opt-in via Save', async () => {
        mockGet.mockResolvedValueOnce({
            data: {
                event_types: ['kb_doc_created'],
                channels: ['in_app', 'email'],
                registered_channels: ['in_app', 'email'],
                platform_defaults: { in_app: true, email: false },
                defaults: [],
            },
        });

        render(wrapped(<AdminNotificationDefaultsGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults')).toHaveAttribute('data-state', 'ready');
        });

        expect(screen.getByTestId('notif-defaults-save')).not.toBeDisabled();
        expect(screen.getByTestId('notif-defaults-dirty-indicator')).toBeInTheDocument();
    });

    it('renders the save error banner when the PUT fails (e.g. 403 for non-super-admin)', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);
        mockPut.mockRejectedValueOnce(new Error('403'));

        render(wrapped(<AdminNotificationDefaultsGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-defaults-cell-kb_doc_created-in_app-toggle'));
        await user.click(screen.getByTestId('notif-defaults-save'));

        await waitFor(() => {
            expect(screen.getByTestId('notif-defaults-save-error')).toBeInTheDocument();
        });
    });
});
