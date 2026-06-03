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
        mockedApi.get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } });
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