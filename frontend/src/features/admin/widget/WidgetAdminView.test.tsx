import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { WidgetAdminView } from './WidgetAdminView';

// Mock the api module for child components
vi.mock('../../../lib/api', () => ({
    api: {
        get: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    },
}));

// #31 — controlla i ruoli: la tab Keys è super-admin, Sessions è admin+super-admin.
let mockRoles: string[] = ['super-admin'];
vi.mock('../../../lib/auth-store', () => ({
    useAuthStore: (selector: (s: { roles: string[]; loading: boolean }) => unknown) =>
        selector({ roles: mockRoles, loading: false }),
}));

import { api } from '../../../lib/api';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = api as any;

function renderWithQuery(ui: React.ReactElement) {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('WidgetAdminView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockRoles = ['super-admin'];
        mockedApi.get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
    });

    // #31 — un admin (non super-admin) vede SOLO la tab Sessions; niente Keys/Integration.
    it('admin (non super-admin) sees only the Sessions tab (#31)', () => {
        mockRoles = ['admin'];
        renderWithQuery(<WidgetAdminView />);

        expect(screen.getByTestId('admin-widget-tab-sessions')).toBeDefined();
        expect(screen.queryByTestId('admin-widget-tab-keys')).toBeNull();
        expect(screen.queryByTestId('admin-widget-tab-guide')).toBeNull();
        // Default tab = sessions per un admin.
        expect(screen.getByTestId('admin-widget-tab-sessions').getAttribute('aria-selected')).toBe('true');
    });

    it('super-admin sees Keys + Sessions + Integration tabs (#31)', () => {
        mockRoles = ['super-admin'];
        renderWithQuery(<WidgetAdminView />);

        expect(screen.getByTestId('admin-widget-tab-keys')).toBeDefined();
        expect(screen.getByTestId('admin-widget-tab-sessions')).toBeDefined();
        expect(screen.getByTestId('admin-widget-tab-guide')).toBeDefined();
    });

    // #31 — la tab è DERIVATA dai ruoli (non un useState sticky): un super-admin
    // i cui ruoli arrivano DOPO il primo render deve comunque atterrare su Keys.
    it('super-admin lands on the Keys tab when roles populate after mount (#31)', () => {
        // primo render: ruoli non ancora caricati (race auth-store)
        mockRoles = [];
        const { rerender } = renderWithQuery(<WidgetAdminView />);
        expect(screen.queryByTestId('admin-widget-tab-keys')).toBeNull();

        // i ruoli arrivano → re-render: Keys è la tab selezionata, niente più "sticky".
        mockRoles = ['super-admin'];
        rerender(
            <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
                <WidgetAdminView />
            </QueryClientProvider>,
        );
        expect(screen.getByTestId('admin-widget-tab-keys').getAttribute('aria-selected')).toBe('true');
    });

    it('renders with testid', () => {
        renderWithQuery(<WidgetAdminView />);
        expect(screen.getByTestId('admin-widget-view')).toBeDefined();
    });

    it('renders tabs with correct testids (R11)', () => {
        renderWithQuery(<WidgetAdminView />);
        expect(screen.getByTestId('admin-widget-tabs')).toBeDefined();
        expect(screen.getByTestId('admin-widget-tab-keys')).toBeDefined();
        expect(screen.getByTestId('admin-widget-tab-sessions')).toBeDefined();
    });

    it('switches to sessions tab on click', () => {
        renderWithQuery(<WidgetAdminView />);
        const sessTab = screen.getByTestId('admin-widget-tab-sessions');
        fireEvent.click(sessTab);
        expect(screen.getByTestId('admin-widget-tab-sessions').getAttribute('aria-selected')).toBe('true');
        expect(screen.getByTestId('admin-widget-tab-keys').getAttribute('aria-selected')).toBe('false');
    });

    it('a11y: tabs have correct ARIA roles (R15)', () => {
        renderWithQuery(<WidgetAdminView />);
        const tablist = screen.getByTestId('admin-widget-tabs');
        expect(tablist.getAttribute('role')).toBe('tablist');
        expect(tablist.getAttribute('aria-label')).toBe('Widget admin sections');

        const keysTab = screen.getByTestId('admin-widget-tab-keys');
        expect(keysTab.getAttribute('role')).toBe('tab');
        expect(keysTab.getAttribute('aria-selected')).toBe('true');
    });
});