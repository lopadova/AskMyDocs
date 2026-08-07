import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { DEFAULT_THEME } from '../../../widget/ui/styles';
import { WidgetAppearanceDialog } from './WidgetAppearanceDialog';
import {
    buildWidgetThemeAgentHandoff,
    createWidgetThemeProfile,
    serializeWidgetThemeProfile,
} from './widget-theme-exchange';

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

    it('organizes advanced controls into the five appearance sections', () => {
        renderDialog();

        for (const section of ['branding', 'launcher', 'chat', 'composer', 'sources']) {
            expect(screen.getByTestId(`admin-widget-appearance-tab-${section}`)).toBeDefined();
        }
    });

    it('exposes the agent handoff and JSON import actions without saving', async () => {
        const user = userEvent.setup();
        renderDialog();

        expect(screen.getByTestId('admin-widget-appearance-handoff')).toHaveAccessibleName(
            'Agent handoff',
        );
        expect(screen.getByTestId('admin-widget-appearance-import-json')).toHaveAccessibleName(
            'Import JSON',
        );

        await user.click(screen.getByTestId('admin-widget-appearance-handoff'));
        const handoff = screen.getByTestId('admin-widget-appearance-handoff-text');
        expect(handoff.textContent).toContain('Inspect the host interface read-only');
        expect(handoff.textContent).toContain('Return ONLY one valid JSON object');
        expect(handoff.textContent).toContain('"format": "askmydocs.widget-theme"');
        expect(handoff.textContent).not.toContain('docs-v3');
        expect(handoff.textContent).not.toContain('Production');
        for (const key of Object.keys(DEFAULT_THEME)) {
            expect(handoff.textContent).toContain(`"${key}"`);
        }
        expect(mockedApi.patch).not.toHaveBeenCalled();
    });

    it('copies the exact handoff script generated from the current draft', async () => {
        const user = userEvent.setup();
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });
        renderDialog();

        await user.click(screen.getByTestId('admin-widget-appearance-handoff'));
        await user.click(screen.getByTestId('admin-widget-appearance-handoff-copy'));

        await waitFor(() => {
            expect(writeText).toHaveBeenCalledWith(
                buildWidgetThemeAgentHandoff({ ...DEFAULT_THEME, accent: '#000000' }),
            );
            expect(screen.getByTestId('admin-widget-appearance-handoff-copy')).toHaveAttribute(
                'data-state',
                'copied',
            );
        });
    });

    it('imports a complete profile into the draft and preview without auto-saving', async () => {
        const user = userEvent.setup();
        renderDialog();
        const importedTheme = {
            ...DEFAULT_THEME,
            mode: 'inline' as const,
            accent: '#123456',
            panelWidth: 640,
            sourceSidebarBackground: '#112233',
            sourceViewerWidth: 1120,
        };

        await user.click(screen.getByTestId('admin-widget-appearance-import-json'));
        fireEvent.change(screen.getByTestId('admin-widget-appearance-import-input'), {
            target: { value: serializeWidgetThemeProfile(importedTheme) },
        });
        await user.click(screen.getByTestId('admin-widget-appearance-import-apply'));

        expect(screen.getByTestId('admin-widget-appearance-import-success')).toHaveTextContent(
            'save explicitly',
        );
        expect(screen.getByTestId('admin-widget-appearance-field-mode')).toHaveValue('inline');
        expect(screen.getByTestId('admin-widget-appearance-hex-accent')).toHaveValue('#123456');
        expect(mockedApi.patch).not.toHaveBeenCalled();

        const previewHost = screen.getByTestId('admin-widget-appearance-preview')
            .firstElementChild as HTMLElement;
        expect(previewHost.shadowRoot?.querySelector('style')?.textContent).toContain(
            '--amd-accent:var(--askmydocs-accent,#123456);',
        );

        await user.click(screen.getByTestId('admin-widget-appearance-tab-sources'));
        expect(
            screen.getByTestId('admin-widget-appearance-field-sourceViewerWidth'),
        ).toHaveValue('1120');
        expect(
            screen.getByTestId('admin-widget-appearance-hex-sourceSidebarBackground'),
        ).toHaveValue('#112233');
    });

    it('loads a .json file into the importer before the operator applies it', async () => {
        const user = userEvent.setup();
        renderDialog();
        const source = serializeWidgetThemeProfile({ ...DEFAULT_THEME, accent: '#654321' });
        const file = new File([source], 'host-widget-theme.json', { type: 'application/json' });
        Object.defineProperty(file, 'text', {
            configurable: true,
            value: vi.fn().mockResolvedValue(source),
        });

        await user.click(screen.getByTestId('admin-widget-appearance-import-json'));
        fireEvent.change(screen.getByTestId('admin-widget-appearance-import-file'), {
            target: { files: [file] },
        });

        await waitFor(() => {
            expect(screen.getByTestId('admin-widget-appearance-import-input')).toHaveValue(source);
        });
        expect(screen.getByTestId('admin-widget-appearance-hex-accent')).toHaveValue('#000000');
        expect(mockedApi.patch).not.toHaveBeenCalled();
    });

    it('ignores a stale file read when a newer JSON file finishes first', async () => {
        const user = userEvent.setup();
        renderDialog();
        const firstSource = serializeWidgetThemeProfile({ ...DEFAULT_THEME, accent: '#111111' });
        const secondSource = serializeWidgetThemeProfile({ ...DEFAULT_THEME, accent: '#222222' });
        let resolveFirst!: (value: string) => void;
        let resolveSecond!: (value: string) => void;
        const firstRead = new Promise<string>((resolve) => {
            resolveFirst = resolve;
        });
        const secondRead = new Promise<string>((resolve) => {
            resolveSecond = resolve;
        });
        const firstFile = new File(['first'], 'first.json', { type: 'application/json' });
        const secondFile = new File(['second'], 'second.json', { type: 'application/json' });
        Object.defineProperty(firstFile, 'text', { value: () => firstRead });
        Object.defineProperty(secondFile, 'text', { value: () => secondRead });

        await user.click(screen.getByTestId('admin-widget-appearance-import-json'));
        const fileInput = screen.getByTestId('admin-widget-appearance-import-file');
        fireEvent.change(fileInput, { target: { files: [firstFile] } });
        fireEvent.change(fileInput, { target: { files: [secondFile] } });

        await act(async () => {
            resolveSecond(secondSource);
            await secondRead;
        });
        expect(screen.getByTestId('admin-widget-appearance-import-input')).toHaveValue(
            secondSource,
        );

        await act(async () => {
            resolveFirst(firstSource);
            await firstRead;
        });
        expect(screen.getByTestId('admin-widget-appearance-import-input')).toHaveValue(
            secondSource,
        );
    });

    it('clears stale import content when a newly selected file is oversized', async () => {
        const user = userEvent.setup();
        renderDialog();
        const source = serializeWidgetThemeProfile(DEFAULT_THEME);
        const oversizedFile = new File(['x'.repeat(65_537)], 'too-large.json', {
            type: 'application/json',
        });

        await user.click(screen.getByTestId('admin-widget-appearance-import-json'));
        fireEvent.change(screen.getByTestId('admin-widget-appearance-import-input'), {
            target: { value: source },
        });
        fireEvent.change(screen.getByTestId('admin-widget-appearance-import-file'), {
            target: { files: [oversizedFile] },
        });

        expect(screen.getByTestId('admin-widget-appearance-import-error')).toHaveTextContent(
            '64 KB',
        );
        expect(screen.getByTestId('admin-widget-appearance-import-input')).toHaveValue('');
        expect(screen.getByTestId('admin-widget-appearance-import-apply')).toBeDisabled();
    });

    it('saves only the imported theme after explicit confirmation', async () => {
        mockedApi.patch.mockResolvedValueOnce({ data: { data: {} } });
        const user = userEvent.setup();
        renderDialog();
        const importedTheme = { ...DEFAULT_THEME, accent: '#445566', launcherSize: 72 };

        await user.click(screen.getByTestId('admin-widget-appearance-import-json'));
        fireEvent.change(screen.getByTestId('admin-widget-appearance-import-input'), {
            target: { value: serializeWidgetThemeProfile(importedTheme) },
        });
        await user.click(screen.getByTestId('admin-widget-appearance-import-apply'));
        await user.click(screen.getByTestId('admin-widget-appearance-save'));

        await waitFor(() => {
            expect(mockedApi.patch).toHaveBeenCalledWith('/api/admin/widget-keys/7', {
                theme: importedTheme,
            });
        });
    });

    it('keeps the current draft unchanged when an imported profile is invalid, then allows retry', async () => {
        const user = userEvent.setup();
        renderDialog();
        const invalid = createWidgetThemeProfile(DEFAULT_THEME);
        invalid.theme.accent = '#fff;body{display:none}';

        await user.click(screen.getByTestId('admin-widget-appearance-import-json'));
        fireEvent.change(screen.getByTestId('admin-widget-appearance-import-input'), {
            target: { value: JSON.stringify(invalid) },
        });
        await user.click(screen.getByTestId('admin-widget-appearance-import-apply'));

        expect(screen.getByTestId('admin-widget-appearance-import-error')).toHaveTextContent(
            'theme.accent',
        );
        expect(screen.getByTestId('admin-widget-appearance-hex-accent')).toHaveValue('#000000');
        expect(mockedApi.patch).not.toHaveBeenCalled();

        fireEvent.change(screen.getByTestId('admin-widget-appearance-import-input'), {
            target: { value: serializeWidgetThemeProfile({ ...DEFAULT_THEME, accent: '#abcdef' }) },
        });
        await user.click(screen.getByTestId('admin-widget-appearance-import-apply'));
        expect(screen.queryByTestId('admin-widget-appearance-import-error')).toBeNull();
        expect(screen.getByTestId('admin-widget-appearance-hex-accent')).toHaveValue('#abcdef');
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

    it('edits source-viewer tokens and includes them in the saved theme', async () => {
        mockedApi.patch.mockResolvedValueOnce({ data: { data: {} } });
        const user = userEvent.setup();
        renderDialog();

        await user.click(screen.getByTestId('admin-widget-appearance-tab-sources'));
        fireEvent.change(screen.getByTestId('admin-widget-appearance-field-sourceViewerWidth'), {
            target: { value: '1120' },
        });
        fireEvent.change(screen.getByTestId('admin-widget-appearance-hex-sourceSidebarBackground'), {
            target: { value: '#112233' },
        });
        await user.click(screen.getByTestId('admin-widget-appearance-save'));

        await waitFor(() => {
            expect(mockedApi.patch).toHaveBeenCalledWith('/api/admin/widget-keys/7', {
                theme: expect.objectContaining({
                    sourceViewerWidth: 1120,
                    sourceSidebarBackground: '#112233',
                }),
            });
        });
    });
});
