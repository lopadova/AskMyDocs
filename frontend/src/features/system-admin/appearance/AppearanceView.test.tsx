import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useAuthStore } from '@/lib/auth-store';
import { setLocalUiLocale } from '@/lib/ui-locale';
import { RequireRole } from '@/routes/role-guard';
import { chooseSelectOption } from '@/test-utils/select';
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
        expect(screen.getAllByRole('radio')).toHaveLength(18);
        expect(screen.getByRole('radio', { name: 'Futuro retrò anni 70' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Dinamico office sportivo' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Atlante cartografico' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Officina blueprint' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Orbite semantiche' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Indice prismatico' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Flussi d’inchiostro' })).toBeInTheDocument();
        expect(applyTheme).not.toHaveBeenCalled();
        await userEvent.click(screen.getByTestId('appearance-category-filter'));
        expect(screen.getByRole('option', { name: 'Tutti (18)' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Identità originali (5)' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Ambientazioni (10)' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Astratti (3)' })).toBeInTheDocument();
    });

    it('filters the collection through the shared select and shows category counts and labels', async () => {
        renderView();
        await screen.findByRole('radio', { name: 'Cartographic atlas' });
        expect(screen.getByTestId('appearance-theme-count')).toHaveTextContent('18 of 18 themes');

        await chooseSelectOption(screen.getByTestId('appearance-category-filter'), 'identity');
        expect(screen.getAllByRole('radio')).toHaveLength(5);
        expect(screen.getByTestId('appearance-theme-count')).toHaveTextContent('5 of 18 themes');
        expect(within(screen.getByTestId('appearance-theme-humanistic')).getByText('Original identities')).toBeInTheDocument();
        expect(screen.queryByRole('radio', { name: 'Cartographic atlas' })).not.toBeInTheDocument();

        await chooseSelectOption(screen.getByTestId('appearance-category-filter'), 'worlds');
        expect(screen.getAllByRole('radio')).toHaveLength(10);
        expect(screen.getByTestId('appearance-theme-count')).toHaveTextContent('10 of 18 themes');
        expect(within(screen.getByTestId('appearance-theme-cartographic')).getByText('Worlds')).toBeInTheDocument();

        await chooseSelectOption(screen.getByTestId('appearance-category-filter'), 'abstract');
        expect(screen.getAllByRole('radio')).toHaveLength(3);
        expect(screen.getByTestId('appearance-theme-count')).toHaveTextContent('3 of 18 themes');
        expect(within(screen.getByTestId('appearance-theme-semantic-orbits')).getByText('Abstract themes')).toBeInTheDocument();
        expect(screen.getByTestId('system-appearance-active')).toHaveTextContent('Humanistic');
        expect(appearanceApi.save).not.toHaveBeenCalled();
        expect(applyTheme).not.toHaveBeenCalled();
    });

    it('keeps an abstract draft available for preview and saving outside its category', async () => {
        const user = userEvent.setup();
        renderView();
        await chooseSelectOption(await screen.findByTestId('appearance-category-filter'), 'abstract');
        await user.click(screen.getByRole('radio', { name: 'Ink flows' }));
        await chooseSelectOption(screen.getByTestId('appearance-category-filter'), 'worlds');

        expect(screen.queryByRole('radio', { name: 'Ink flows' })).not.toBeInTheDocument();
        expect(screen.getByTestId('appearance-workspace-preview')).toHaveAttribute('data-design-theme', 'ink-flow');
        expect(screen.getByTestId('system-appearance-save')).toBeEnabled();
        expect(applyTheme).not.toHaveBeenCalled();
        await user.click(screen.getByTestId('system-appearance-save'));
        await screen.findByTestId('system-appearance-saved');
        expect(appearanceApi.save).toHaveBeenCalledWith('ink-flow', expect.anything());
        expect(applyTheme).toHaveBeenCalledExactlyOnceWith('ink-flow');
        expect(screen.getByTestId('system-appearance-active')).toHaveTextContent('Ink flows');
        expect(screen.getByTestId('appearance-category-filter')).toHaveAttribute('data-value', 'worlds');
    });

    it('saves a selected world even while the current filter hides its card', async () => {
        const user = userEvent.setup();
        renderView();
        await user.click(await screen.findByRole('radio', { name: 'Cartographic atlas' }));
        await chooseSelectOption(screen.getByTestId('appearance-category-filter'), 'identity');

        expect(screen.queryByRole('radio', { name: 'Cartographic atlas' })).not.toBeInTheDocument();
        expect(screen.getByTestId('appearance-hidden-selection')).toBeInTheDocument();
        expect(screen.getByTestId('appearance-workspace-preview')).toHaveAttribute('data-design-theme', 'cartographic');
        expect(screen.getByTestId('system-appearance-save')).toBeEnabled();
        expect(screen.getByTestId('system-appearance-active')).toHaveTextContent('Humanistic');

        await user.click(screen.getByTestId('system-appearance-save'));
        await screen.findByTestId('system-appearance-saved');
        expect(appearanceApi.save).toHaveBeenCalledWith('cartographic', expect.anything());
        expect(applyTheme).toHaveBeenCalledExactlyOnceWith('cartographic');
        expect(screen.getByTestId('system-appearance-active')).toHaveTextContent('Cartographic atlas');
        expect(screen.getByTestId('appearance-category-filter')).toHaveAttribute('data-value', 'identity');
        expect(screen.getAllByRole('radio')).toHaveLength(5);
    });

    it('can reveal an original identity draft after filtering it out', async () => {
        const user = userEvent.setup();
        renderView();
        await user.click(await screen.findByRole('radio', { name: 'Tech PRO' }));
        await chooseSelectOption(screen.getByTestId('appearance-category-filter'), 'worlds');
        expect(screen.getByTestId('appearance-workspace-preview')).toHaveAttribute('data-design-theme', 'tech-pro');

        await user.click(screen.getByRole('button', { name: 'Show selected theme' }));
        expect(screen.getByTestId('appearance-category-filter')).toHaveAttribute('data-value', 'identity');
        expect(screen.getByRole('radio', { name: 'Tech PRO' })).toBeChecked();
        expect(appearanceApi.save).not.toHaveBeenCalled();
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
