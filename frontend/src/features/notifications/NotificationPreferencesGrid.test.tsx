import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { NotificationPreferencesGrid } from './NotificationPreferencesGrid';
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
        defaults: { in_app: true, email: false, discord: false },
        preferences: [
            { event_type: 'kb_doc_created', channel: 'email', enabled: true },
        ],
    },
};

describe('NotificationPreferencesGrid', () => {
    it('renders the grid scaffolding seeded with defaults + stored prefs', async () => {
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<NotificationPreferencesGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-pref')).toHaveAttribute('data-state', 'ready');
        });

        // Defaults seeded — in_app=true for all event_types.
        expect(screen.getByTestId('notif-pref-cell-kb_doc_created-in_app-toggle')).toBeChecked();
        expect(screen.getByTestId('notif-pref-cell-kb_canonical_promoted-in_app-toggle')).toBeChecked();

        // Stored pref overrides the default for the email column.
        expect(screen.getByTestId('notif-pref-cell-kb_doc_created-email-toggle')).toBeChecked();
        // Other email cell uses default (false).
        expect(screen.getByTestId('notif-pref-cell-kb_canonical_promoted-email-toggle')).not.toBeChecked();

        // Un-registered channel: rendered as a disabled checkbox.
        const discordCell = screen.getByTestId('notif-pref-cell-kb_doc_created-discord-toggle');
        expect(discordCell).toBeDisabled();
    });

    it('flips a single cell and marks the grid dirty', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<NotificationPreferencesGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-pref')).toHaveAttribute('data-state', 'ready');
        });

        // No dirty indicator initially.
        expect(screen.queryByTestId('notif-pref-dirty-indicator')).toBeNull();
        // Save is disabled initially.
        expect(screen.getByTestId('notif-pref-save')).toBeDisabled();

        await user.click(screen.getByTestId('notif-pref-cell-kb_canonical_promoted-in_app-toggle'));

        // The cell flipped to unchecked, dirty indicator appears.
        expect(screen.getByTestId('notif-pref-cell-kb_canonical_promoted-in_app-toggle')).not.toBeChecked();
        expect(screen.getByTestId('notif-pref-dirty-indicator')).toBeInTheDocument();
        expect(screen.getByTestId('notif-pref-save')).not.toBeDisabled();
    });

    it('row bulk-on enables every REGISTERED channel for the event type', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<NotificationPreferencesGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-pref')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-pref-row-kb_canonical_promoted-enable-all'));

        expect(screen.getByTestId('notif-pref-cell-kb_canonical_promoted-in_app-toggle')).toBeChecked();
        expect(screen.getByTestId('notif-pref-cell-kb_canonical_promoted-email-toggle')).toBeChecked();
        // Un-registered channel stays unchecked even after row bulk-on.
        expect(screen.getByTestId('notif-pref-cell-kb_canonical_promoted-discord-toggle')).not.toBeChecked();
    });

    it('column bulk-off disables every row for the channel', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<NotificationPreferencesGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-pref')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-pref-column-in_app-disable-all'));

        expect(screen.getByTestId('notif-pref-cell-kb_doc_created-in_app-toggle')).not.toBeChecked();
        expect(screen.getByTestId('notif-pref-cell-kb_canonical_promoted-in_app-toggle')).not.toBeChecked();
    });

    it('save posts every cell as a row to the PUT endpoint', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);
        mockPut.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<NotificationPreferencesGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-pref')).toHaveAttribute('data-state', 'ready');
        });

        // Make a single edit so Save becomes enabled.
        await user.click(screen.getByTestId('notif-pref-cell-kb_canonical_promoted-email-toggle'));
        await user.click(screen.getByTestId('notif-pref-save'));

        await waitFor(() => {
            expect(mockPut).toHaveBeenCalledTimes(1);
        });
        const [url, body] = mockPut.mock.calls[0];
        expect(url).toBe('/api/notifications/preferences');
        // Body must include EVERY cell (2 event_types × 3 channels = 6 rows).
        expect(body.preferences).toHaveLength(6);
        // The edited cell now reads `enabled=true`.
        const edited = body.preferences.find(
            (r: { event_type: string; channel: string; enabled: boolean }) =>
                r.event_type === 'kb_canonical_promoted' && r.channel === 'email',
        );
        expect(edited.enabled).toBe(true);
    });

    it('discard restores the last-saved snapshot and clears dirty', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);

        render(wrapped(<NotificationPreferencesGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-pref')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-pref-cell-kb_doc_created-in_app-toggle'));
        expect(screen.getByTestId('notif-pref-cell-kb_doc_created-in_app-toggle')).not.toBeChecked();
        expect(screen.getByTestId('notif-pref-dirty-indicator')).toBeInTheDocument();

        await user.click(screen.getByTestId('notif-pref-discard'));

        expect(screen.getByTestId('notif-pref-cell-kb_doc_created-in_app-toggle')).toBeChecked();
        expect(screen.queryByTestId('notif-pref-dirty-indicator')).toBeNull();
    });

    it('renders the error state when the GET fails', async () => {
        mockGet.mockRejectedValueOnce(new Error('500'));

        render(wrapped(<NotificationPreferencesGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-pref')).toHaveAttribute('data-state', 'error');
        });
        expect(screen.getByTestId('notif-pref-error')).toBeInTheDocument();
        expect(screen.getByTestId('notif-pref-retry')).toBeInTheDocument();
    });

    it('renders the save error banner when the PUT fails', async () => {
        const user = userEvent.setup();
        mockGet.mockResolvedValueOnce(DEFAULT_RESPONSE);
        mockPut.mockRejectedValueOnce(new Error('422'));

        render(wrapped(<NotificationPreferencesGrid />));

        await waitFor(() => {
            expect(screen.getByTestId('notif-pref')).toHaveAttribute('data-state', 'ready');
        });

        await user.click(screen.getByTestId('notif-pref-cell-kb_doc_created-in_app-toggle'));
        await user.click(screen.getByTestId('notif-pref-save'));

        await waitFor(() => {
            expect(screen.getByTestId('notif-pref-save-error')).toBeInTheDocument();
        });
    });
});
