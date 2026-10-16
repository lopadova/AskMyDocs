import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useAuthStore } from '@/lib/auth-store';
import { setLocalUiLocale } from '@/lib/ui-locale';
import { RequireRole } from '@/routes/role-guard';
import { appearanceApi } from './appearance.api';
import { AppearanceView } from './AppearanceView';

const { applyTheme } = vi.hoisted(() => ({ applyTheme: vi.fn() }));
vi.mock('@/lib/visual-theme', async (importOriginal) => ({
    ...await importOriginal<typeof import('@/lib/visual-theme')>(),
    applyVisualTheme: applyTheme,
    getVisualTheme: () => 'humanistic',
}));
vi.mock('@/components/editorial/EditorialArtwork', () => ({
    EditorialArtwork: ({ designTheme, colorMode }: { designTheme: string; colorMode: string }) => <span data-testid={`preview-art-${designTheme}`} data-color-mode={colorMode} />,
}));

function renderView(guarded = false) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(<QueryClientProvider client={client}>{guarded ? <RequireRole roles={['super-admin']}><AppearanceView /></RequireRole> : <AppearanceView />}</QueryClientProvider>);
}

beforeEach(() => {
    useAuthStore.setState({ user: null, roles: [], permissions: [], loading: false });
    setLocalUiLocale('en');
    document.documentElement.dataset.theme = 'dark';
    document.documentElement.dataset.designTheme = 'humanistic';
    vi.spyOn(appearanceApi, 'load').mockResolvedValue({ theme: 'humanistic' });
    vi.spyOn(appearanceApi, 'save').mockImplementation(async (theme) => ({ theme }));
    applyTheme.mockClear();
});
afterEach(() => vi.restoreAllMocks());

describe('AppearanceView', () => {
    it('keeps theme and lighting previews local until an explicit successful save', async () => {
        const user = userEvent.setup();
        renderView();
        await screen.findByTestId('appearance-theme-humanistic');

        expect(screen.getByTestId('system-appearance-save')).toBeDisabled();
        await user.click(screen.getByRole('radio', { name: 'Tech PRO' }));
        await user.click(screen.getByTestId('appearance-preview-light'));

        for (const image of screen.getAllByTestId('preview-art-tech-pro')) expect(image).toHaveAttribute('data-color-mode', 'light');
        expect(screen.getByTestId('appearance-workspace-preview')).toHaveAttribute('data-design-theme', 'tech-pro');
        expect(screen.getByTestId('appearance-workspace-preview')).toHaveAttribute('data-theme', 'light');
        expect(document.documentElement.dataset.theme).toBe('dark');
        expect(document.documentElement.dataset.designTheme).toBe('humanistic');
        expect(screen.getByTestId('system-appearance-active')).toHaveTextContent('Humanistic');
        expect(appearanceApi.save).not.toHaveBeenCalled();
        expect(applyTheme).not.toHaveBeenCalled();

        await user.click(screen.getByTestId('system-appearance-save'));
        await screen.findByTestId('system-appearance-saved');
        expect(appearanceApi.save).toHaveBeenCalledWith('tech-pro', expect.anything());
        expect(applyTheme).toHaveBeenCalledExactlyOnceWith('tech-pro');
        expect(screen.getByTestId('system-appearance-active')).toHaveTextContent('Tech PRO');
        expect(screen.getByTestId('system-appearance-save')).toBeDisabled();
    });

    it('preserves the selected theme after a failed save and allows retry', async () => {
        const user = userEvent.setup();
        vi.mocked(appearanceApi.save).mockRejectedValueOnce(new Error('Unavailable'));
        renderView();
        await user.click(await screen.findByRole('radio', { name: 'Luxury offices' }));
        await user.click(screen.getByTestId('system-appearance-save'));
        await screen.findByTestId('system-appearance-save-error');

        expect(screen.getByRole('radio', { name: 'Luxury offices' })).toBeChecked();
        expect(applyTheme).not.toHaveBeenCalled();
        expect(screen.getByTestId('system-appearance-active')).toHaveTextContent('Humanistic');
        await user.click(screen.getByTestId('system-appearance-save'));
        await screen.findByTestId('system-appearance-saved');
        expect(applyTheme).toHaveBeenCalledExactlyOnceWith('luxury');
    });

    it('keeps selection disabled and the save label stable while saving', async () => {
        const user = userEvent.setup();
        let finish!: (settings: { theme: 'retro-future' }) => void;
        vi.mocked(appearanceApi.save).mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
        renderView();
        await user.click(await screen.findByRole('radio', { name: 'Retro future' }));
        await user.click(screen.getByTestId('system-appearance-save'));
        expect(screen.getByTestId('system-appearance-save')).toBeDisabled();
        expect(screen.getByTestId('system-appearance-save')).toHaveAttribute('aria-busy', 'true');
        expect(screen.getByTestId('system-appearance-save')).toHaveTextContent('Save platform theme');
        expect(screen.getByRole('radio', { name: 'Tech PRO' })).toBeDisabled();
        expect(applyTheme).not.toHaveBeenCalled();
        await act(async () => finish({ theme: 'retro-future' }));
        await screen.findByTestId('system-appearance-saved');
    });

    it('shows a load error with a working retry before showing the theme controls', async () => {
        const user = userEvent.setup();
        vi.mocked(appearanceApi.load).mockRejectedValueOnce(new Error('Unavailable'));
        renderView();
        await screen.findByTestId('system-appearance-load-error');
        expect(screen.queryByTestId('system-appearance-save')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Retry' }));
        await screen.findByTestId('appearance-theme-humanistic');
    });

    it('offers the complete Italian collection without changing runtime preferences', async () => {
        setLocalUiLocale('it');
        renderView();
        await screen.findByRole('radio', { name: 'Umanistico' });
        expect(screen.getByRole('heading', { name: 'Aspetto', level: 1 })).toBeInTheDocument();
        expect(screen.getAllByRole('radio')).toHaveLength(5);
        expect(screen.getByRole('radio', { name: 'Futuro retrò anni 70' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Dinamico office sportivo' })).toBeInTheDocument();
        expect(applyTheme).not.toHaveBeenCalled();
    });

    it('refreshes the active theme on window focus without overwriting an unsaved selection', async () => {
        const user = userEvent.setup();
        renderView();
        await user.click(await screen.findByRole('radio', { name: 'Tech PRO' }));
        vi.mocked(appearanceApi.load).mockResolvedValue({ theme: 'luxury' });

        await act(async () => { window.dispatchEvent(new Event('focus')); });

        await waitFor(() => expect(screen.getByTestId('system-appearance-active')).toHaveTextContent('Luxury offices'));
        expect(screen.getByRole('radio', { name: 'Tech PRO' })).toBeChecked();
        expect(screen.getByTestId('system-appearance-dirty')).toBeInTheDocument();
        expect(applyTheme).not.toHaveBeenCalled();
    });

    it('follows later platform changes after a successful save without inventing a new draft', async () => {
        const user = userEvent.setup();
        renderView();
        await user.click(await screen.findByRole('radio', { name: 'Tech PRO' }));
        await user.click(screen.getByTestId('system-appearance-save'));
        await screen.findByTestId('system-appearance-saved');
        vi.mocked(appearanceApi.load).mockResolvedValue({ theme: 'luxury' });

        await act(async () => { window.dispatchEvent(new Event('focus')); });

        await waitFor(() => expect(screen.getByRole('radio', { name: 'Luxury offices' })).toBeChecked());
        expect(screen.queryByTestId('system-appearance-dirty')).not.toBeInTheDocument();
        expect(screen.getByTestId('system-appearance-save')).toBeDisabled();
        expect(applyTheme).toHaveBeenCalledExactlyOnceWith('tech-pro');
    });

    it('does not query or expose settings for system-admin without the super-admin role', () => {
        useAuthStore.setState({ user: { id: 1, name: 'System', email: 'system@example.test' }, roles: ['system-admin'], permissions: ['platform.admin'], loading: false });
        renderView(true);
        expect(screen.getByTestId('admin-forbidden')).toBeInTheDocument();
        expect(appearanceApi.load).not.toHaveBeenCalled();
    });
});
