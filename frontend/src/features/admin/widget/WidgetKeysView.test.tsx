import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { WidgetKeysView } from './WidgetKeysView';

// Mock the api module
vi.mock('../../../lib/api', () => ({
    api: {
        get: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    },
}));

import { api } from '../../../lib/api';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = api as any;

function renderWithQuery(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('WidgetKeysView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the view with testid (R11)', () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        renderWithQuery(<WidgetKeysView />);
        expect(screen.getByTestId('admin-widget-keys-view')).toBeDefined();
    });

    it('shows empty state when no keys', async () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        renderWithQuery(<WidgetKeysView />);
        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-empty')).toBeDefined();
        });
    });

    it('displays keys in a table', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 1,
                        label: 'Production',
                        public_key: 'pk_abc123',
                        project_key: 'main',
                        allowed_origins: ['https://example.com'],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 5,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-table')).toBeDefined();
        });
        expect(screen.getByText('Production')).toBeDefined();
        expect(screen.getByText('pk_abc123')).toBeDefined();
    });

    it('opens create form on button click', async () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: [] } });
        renderWithQuery(<WidgetKeysView />);

        const btn = screen.getByTestId('admin-widget-keys-create-btn');
        fireEvent.click(btn);

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-create-form')).toBeDefined();
        });
    });

    it('shows loading state (R14)', () => {
        mockedApi.get.mockReturnValue(new Promise(() => {})); // never resolves
        renderWithQuery(<WidgetKeysView />);
        expect(screen.getByTestId('admin-widget-keys-loading')).toBeDefined();
    });

    it('shows revoke button for active keys', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 1,
                        label: 'Active Key',
                        public_key: 'pk_active',
                        project_key: 'main',
                        allowed_origins: [],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: true,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-revoke-1')).toBeDefined();
            expect(screen.getByTestId('admin-widget-keys-rotate-1')).toBeDefined();
        });
    });

    it('shows revoked status badge for inactive keys', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        id: 2,
                        label: 'Revoked Key',
                        public_key: 'pk_revoked',
                        project_key: 'main',
                        allowed_origins: [],
                        rate_limit: 60,
                        skill: 'askmydocs-assistant@1',
                        is_active: false,
                        last_used_at: null,
                        sessions_count: 0,
                        created_at: '2026-05-30T00:00:00Z',
                        updated_at: '2026-05-30T00:00:00Z',
                    },
                ],
            },
        });

        renderWithQuery(<WidgetKeysView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-keys-status-2')).toBeDefined();
            expect(screen.getByTestId('admin-widget-keys-status-2').textContent).toBe('Revoked');
        });
    });
});