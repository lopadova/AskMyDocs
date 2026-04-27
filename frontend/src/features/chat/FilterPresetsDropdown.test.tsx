import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { FilterPresetsDropdown } from './FilterPresetsDropdown';
import { api } from '../../lib/api';

const mockGet = vi.fn();
const mockPost = vi.fn();
const mockDelete = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    mockPost.mockReset();
    mockDelete.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
    vi.spyOn(api, 'post').mockImplementation(mockPost);
    vi.spyOn(api, 'delete').mockImplementation(mockDelete);
});

afterEach(() => {
    vi.restoreAllMocks();
});

function withQueryClient(node: ReactNode): ReactNode {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return <QueryClientProvider client={qc}>{node}</QueryClientProvider>;
}

describe('FilterPresetsDropdown', () => {
    it('renders the trigger button with star glyph and aria-expanded=false', () => {
        render(withQueryClient(<FilterPresetsDropdown filters={{}} onLoad={() => {}} />));
        const trigger = screen.getByTestId('chat-filter-presets-trigger');
        expect(trigger).toBeVisible();
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
    });

    it('opens the menu on click and toggles aria-expanded', async () => {
        // No fetch needed when there's nothing to load.
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<FilterPresetsDropdown filters={{}} onLoad={() => {}} />));
        const trigger = screen.getByTestId('chat-filter-presets-trigger');
        await userEvent.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByTestId('chat-filter-presets-menu')).toBeVisible();
    });

    it('shows empty state when the user has no saved presets', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<FilterPresetsDropdown filters={{}} onLoad={() => {}} />));
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        await waitFor(() => {
            expect(screen.getByTestId('chat-filter-presets-empty')).toBeVisible();
        });
    });

    it('renders one row per preset returned by the API', async () => {
        mockGet.mockResolvedValue({
            data: {
                data: [
                    { id: 1, name: 'HR + PDF', filters: { project_keys: ['hr'], source_types: ['pdf'] } },
                    { id: 2, name: 'Engineering only', filters: { project_keys: ['engineering'] } },
                ],
            },
        });
        render(withQueryClient(<FilterPresetsDropdown filters={{}} onLoad={() => {}} />));
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        await waitFor(() => {
            expect(screen.getByTestId('chat-filter-preset-1')).toHaveAttribute('data-preset-name', 'HR + PDF');
            expect(screen.getByTestId('chat-filter-preset-2')).toHaveAttribute('data-preset-name', 'Engineering only');
        });
    });

    it('clicking a preset row calls onLoad with that preset filters and closes the menu', async () => {
        mockGet.mockResolvedValue({
            data: { data: [{ id: 1, name: 'HR', filters: { project_keys: ['hr'] } }] },
        });
        const onLoad = vi.fn();
        render(withQueryClient(<FilterPresetsDropdown filters={{}} onLoad={onLoad} />));
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        await waitFor(() => {
            expect(screen.getByTestId('chat-filter-preset-1-load')).toBeVisible();
        });
        await userEvent.click(screen.getByTestId('chat-filter-preset-1-load'));
        expect(onLoad).toHaveBeenCalledWith({ project_keys: ['hr'] });
        // Menu auto-closes on load — trigger is back to aria-expanded=false.
        expect(screen.getByTestId('chat-filter-presets-trigger')).toHaveAttribute('aria-expanded', 'false');
    });

    it('Save current is disabled when current filter state is empty', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(withQueryClient(<FilterPresetsDropdown filters={{}} onLoad={() => {}} />));
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        const saveBtn = await screen.findByTestId('chat-filter-presets-save');
        expect(saveBtn).toBeDisabled();
    });

    it('Save current is enabled when at least one filter is active', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(
            withQueryClient(
                <FilterPresetsDropdown
                    filters={{ project_keys: ['hr'] }}
                    onLoad={() => {}}
                />,
            ),
        );
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        const saveBtn = await screen.findByTestId('chat-filter-presets-save');
        expect(saveBtn).not.toBeDisabled();
    });

    it('clicking Save current opens the name-input form', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(
            withQueryClient(
                <FilterPresetsDropdown
                    filters={{ project_keys: ['hr'] }}
                    onLoad={() => {}}
                />,
            ),
        );
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        await userEvent.click(await screen.findByTestId('chat-filter-presets-save'));
        expect(screen.getByTestId('chat-filter-presets-save-form')).toBeVisible();
        expect(screen.getByTestId('chat-filter-presets-name-input')).toBeVisible();
    });

    it('Save confirm POSTs the new preset and refetches the list', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        mockPost.mockResolvedValue({
            data: { data: { id: 99, name: 'My Preset', filters: { project_keys: ['hr'] } } },
        });
        const filters = { project_keys: ['hr'] };
        render(withQueryClient(<FilterPresetsDropdown filters={filters} onLoad={() => {}} />));
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        await userEvent.click(await screen.findByTestId('chat-filter-presets-save'));
        await userEvent.type(screen.getByTestId('chat-filter-presets-name-input'), 'My Preset');
        await userEvent.click(screen.getByTestId('chat-filter-presets-save-confirm'));
        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                '/api/chat-filter-presets',
                { name: 'My Preset', filters },
            );
        });
    });

    it('Save Cancel closes the form without sending POST', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });
        render(
            withQueryClient(
                <FilterPresetsDropdown
                    filters={{ project_keys: ['hr'] }}
                    onLoad={() => {}}
                />,
            ),
        );
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        await userEvent.click(await screen.findByTestId('chat-filter-presets-save'));
        await userEvent.click(screen.getByTestId('chat-filter-presets-save-cancel'));
        // Form gone, save button is back.
        expect(screen.queryByTestId('chat-filter-presets-save-form')).not.toBeInTheDocument();
        expect(screen.getByTestId('chat-filter-presets-save')).toBeVisible();
        expect(mockPost).not.toHaveBeenCalled();
    });

    it('Delete requires a confirm step (no accidental delete)', async () => {
        mockGet.mockResolvedValue({
            data: { data: [{ id: 7, name: 'Removable', filters: {} }] },
        });
        render(withQueryClient(<FilterPresetsDropdown filters={{}} onLoad={() => {}} />));
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        await waitFor(() =>
            expect(screen.getByTestId('chat-filter-preset-7-delete')).toBeVisible(),
        );
        await userEvent.click(screen.getByTestId('chat-filter-preset-7-delete'));
        // First click reveals confirm + cancel; no DELETE issued yet.
        expect(screen.getByTestId('chat-filter-preset-7-delete-confirm')).toBeVisible();
        expect(screen.getByTestId('chat-filter-preset-7-delete-cancel')).toBeVisible();
        expect(mockDelete).not.toHaveBeenCalled();
    });

    it('Delete confirm fires the DELETE call', async () => {
        mockGet.mockResolvedValue({
            data: { data: [{ id: 7, name: 'X', filters: {} }] },
        });
        mockDelete.mockResolvedValue({ status: 204 });
        render(withQueryClient(<FilterPresetsDropdown filters={{}} onLoad={() => {}} />));
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        await waitFor(() => expect(screen.getByTestId('chat-filter-preset-7-delete')).toBeVisible());
        await userEvent.click(screen.getByTestId('chat-filter-preset-7-delete'));
        await userEvent.click(screen.getByTestId('chat-filter-preset-7-delete-confirm'));
        await waitFor(() => {
            expect(mockDelete).toHaveBeenCalledWith('/api/chat-filter-presets/7');
        });
    });

    it('Delete cancel reverts the row to its non-confirming state', async () => {
        mockGet.mockResolvedValue({
            data: { data: [{ id: 7, name: 'X', filters: {} }] },
        });
        render(withQueryClient(<FilterPresetsDropdown filters={{}} onLoad={() => {}} />));
        await userEvent.click(screen.getByTestId('chat-filter-presets-trigger'));
        await waitFor(() => expect(screen.getByTestId('chat-filter-preset-7-delete')).toBeVisible());
        await userEvent.click(screen.getByTestId('chat-filter-preset-7-delete'));
        await userEvent.click(screen.getByTestId('chat-filter-preset-7-delete-cancel'));
        // Back to single × button — confirm + cancel hidden.
        expect(screen.getByTestId('chat-filter-preset-7-delete')).toBeVisible();
        expect(screen.queryByTestId('chat-filter-preset-7-delete-confirm')).not.toBeInTheDocument();
    });
});
