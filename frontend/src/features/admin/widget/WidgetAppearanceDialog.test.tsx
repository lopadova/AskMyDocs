import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { DEFAULT_THEME } from '../../../widget/ui/styles';
import { WidgetAppearanceDialog } from './WidgetAppearanceDialog';

vi.mock('../../../lib/api', () => ({
    api: { patch: vi.fn() },
}));

import { api } from '../../../lib/api';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const mockedApi = api as any;

function renderDialog() {
    const qc = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(
        <QueryClientProvider client={qc}>
            <WidgetAppearanceDialog
                open
                onOpenChange={() => {}}
                keyId={7}
                label="Production"
                projectKey="docs-v3"
                initialTheme={{ ...DEFAULT_THEME, accent: '#000000' }}
            />
        </QueryClientProvider>,
    );
}

describe('WidgetAppearanceDialog', () => {
    // mockReset (not clearAllMocks) drains the mock*Once queue so a leftover
    // resolution can't poison the next test.
    beforeEach(() => mockedApi.patch.mockReset());

    it('shows the layout-mode note (it does not propagate to embedded widgets) (#35)', () => {
        renderDialog();
        expect(screen.getByTestId('admin-widget-appearance-mode-note')).toBeDefined();
    });

    it('saves the edited theme via PATCH with the changed colour', async () => {
        mockedApi.patch.mockResolvedValueOnce({ data: { data: {} } });
        const user = userEvent.setup();
        renderDialog();

        await user.click(screen.getByTestId('admin-widget-appearance-tab-colors'));
        const hex = await screen.findByTestId('admin-widget-appearance-hex-accent');
        fireEvent.change(hex, { target: { value: '#ff0000' } });

        await user.click(screen.getByTestId('admin-widget-appearance-save'));

        await waitFor(() => {
            expect(mockedApi.patch).toHaveBeenCalledWith('/api/admin/widget-keys/7', {
                theme: expect.objectContaining({ accent: '#ff0000' }),
            });
        });
    });

    it('reset-to-defaults sends the default theme on save (R16)', async () => {
        mockedApi.patch.mockResolvedValueOnce({ data: { data: {} } });
        const user = userEvent.setup();
        renderDialog();

        await user.click(screen.getByTestId('admin-widget-appearance-reset'));
        await user.click(screen.getByTestId('admin-widget-appearance-save'));

        await waitFor(() => {
            expect(mockedApi.patch).toHaveBeenCalledWith('/api/admin/widget-keys/7', {
                theme: expect.objectContaining({ accent: DEFAULT_THEME.accent }),
            });
        });
    });

    it('switches the widget type to inline and saves theme.mode=inline (R16)', async () => {
        mockedApi.patch.mockResolvedValueOnce({ data: { data: {} } });
        const user = userEvent.setup();
        renderDialog();

        // Inline note absent in the default (helper) launcher tab.
        await user.click(screen.getByTestId('admin-widget-appearance-tab-launcher'));
        expect(screen.queryByTestId('admin-widget-appearance-launcher-inline-note')).toBeNull();

        await user.selectOptions(
            screen.getByTestId('admin-widget-appearance-field-mode'),
            'inline',
        );

        // Switching to inline surfaces the "launcher has no effect" note.
        expect(
            screen.getByTestId('admin-widget-appearance-launcher-inline-note'),
        ).toBeDefined();

        await user.click(screen.getByTestId('admin-widget-appearance-save'));

        await waitFor(() => {
            expect(mockedApi.patch).toHaveBeenCalledWith('/api/admin/widget-keys/7', {
                theme: expect.objectContaining({ mode: 'inline' }),
            });
        });
    });

    it('surfaces a 422 error in the DOM (R14)', async () => {
        mockedApi.patch.mockRejectedValueOnce({
            response: { data: { errors: { 'theme.accent': ['Invalid colour.'] } } },
        });
        const user = userEvent.setup();
        renderDialog();

        await user.click(screen.getByTestId('admin-widget-appearance-save'));

        await waitFor(() => {
            const err = screen.getByTestId('admin-widget-appearance-error');
            expect(err.textContent).toContain('Invalid colour.');
        });
    });
});
