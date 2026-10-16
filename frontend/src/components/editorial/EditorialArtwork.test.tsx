import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthLayout } from '../../features/auth/AuthLayout';
import { useTheme } from '../shell/hooks';
import { EditorialArtwork } from './EditorialArtwork';
import { applyVisualTheme } from '@/lib/visual-theme';

const NEW_THEME_IDS = [
    'cartographic', 'polar', 'orbital', 'botanical', 'oceanic', 'newsroom',
    'archaeological', 'alpine', 'paper-world', 'blueprint',
    'semantic-orbits', 'prismatic-index', 'ink-flow',
] as const;

// Independent expectations: application roles resolve to the three scenes in each new theme.
const NEW_THEME_SCENES = [
    ['knowledge', 'knowledge'], ['observatory', 'knowledge'],
    ['archive', 'archive'], ['processes', 'archive'], ['trust', 'archive'],
    ['community', 'community'], ['dialogue', 'community'], ['connections', 'community'],
] as const;

function ThemeControl() {
    const [theme, setTheme] = useTheme();
    return <><button onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>Change theme</button><EditorialArtwork artwork="knowledge" /></>;
}

async function setRenderedTheme(theme?: string) {
    await act(async () => {
        if (theme) document.documentElement.setAttribute('data-theme', theme);
        else document.documentElement.removeAttribute('data-theme');
    });
}

describe('EditorialArtwork theme variants', () => {
    it('switches authentication lighting without changing the installation identity', async () => {
        document.documentElement.dataset.designTheme = 'luxury';
        localStorage.setItem('amd-theme', 'light');
        const { container } = render(<AuthLayout title="Sign in"><form aria-label="Login form" /></AuthLayout>);
        const image = container.querySelector('.editorial-auth-story img')!;
        await act(async () => undefined);
        expect(image).toHaveAttribute('src', '/editorial/themes/luxury/knowledge-day.webp');
        await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Toggle theme' })));
        expect(image).toHaveAttribute('src', '/editorial/themes/luxury/knowledge-night.webp');
        expect(document.documentElement).toHaveAttribute('data-design-theme', 'luxury');
        expect(localStorage.getItem('amd-theme')).toBe('dark');
    });
    beforeEach(() => {
        document.documentElement.removeAttribute('data-theme');
        document.documentElement.removeAttribute('data-design-theme');
        localStorage.removeItem('amd-theme');
    });

    afterEach(() => {
        cleanup();
        document.documentElement.removeAttribute('data-theme');
        document.documentElement.removeAttribute('data-design-theme');
        localStorage.removeItem('amd-theme');
        vi.restoreAllMocks();
    });

    it('reads an already rendered light theme and preserves responsive layout and decorative semantics', () => {
        document.documentElement.dataset.theme = 'light';
        const { container } = render(<EditorialArtwork artwork="archive" className="art-container" imgClassName="art-image" sizes="50vw" />);
        const picture = container.querySelector('picture')!;
        const image = container.querySelector('img')!;
        expect(picture).toHaveClass('art-container');
        expect(picture).toHaveAttribute('aria-hidden', 'true');
        expect(image).toHaveClass('art-image');
        expect(image).toHaveAttribute('src', '/editorial/archive-day.webp');
        expect(image).toHaveAttribute('srcset', '/editorial/archive-day-small.webp 640w, /editorial/archive-day.webp 1280w');
        expect(image).toHaveAttribute('sizes', '50vw');
        expect(image).toHaveAttribute('alt', '');
        expect(image).toHaveAttribute('width', '1280');
        expect(image).toHaveAttribute('height', '853');
        expect(picture.querySelector('source')).toHaveAttribute('srcset', '/editorial/archive-day-small.avif 640w, /editorial/archive-day.avif 1280w');
        expect(picture.querySelector('source')).toHaveAttribute('sizes', '50vw');
    });

    it('updates both formats on external theme changes, keeps one image and never writes theme preferences', async () => {
        const writePreference = vi.spyOn(localStorage, 'setItem');
        const { container } = render(<EditorialArtwork artwork="trust" />);
        const image = container.querySelector('img')!;
        const source = container.querySelector('source')!;
        expect(image).toHaveAttribute('src', '/editorial/trust-night.webp');
        await setRenderedTheme('light');
        expect(image).toHaveAttribute('src', '/editorial/trust-day.webp');
        expect(source).toHaveAttribute('srcset', '/editorial/trust-day-small.avif 640w, /editorial/trust-day.avif 1280w');
        await setRenderedTheme('dark');
        expect(image).toHaveAttribute('srcset', '/editorial/trust-night-small.webp 640w, /editorial/trust-night.webp 1280w');
        expect(source).toHaveAttribute('srcset', '/editorial/trust-night-small.avif 640w, /editorial/trust-night.avif 1280w');
        expect(container.querySelectorAll('img')).toHaveLength(1);
        expect(writePreference).not.toHaveBeenCalled();
    });

    it('follows the existing manual theme control in both directions', async () => {
        const { container } = render(<ThemeControl />);
        const image = container.querySelector('img')!;
        expect(image).toHaveAttribute('src', '/editorial/knowledge-night.webp');
        await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Change theme' })));
        expect(document.documentElement).toHaveAttribute('data-theme', 'light');
        expect(image).toHaveAttribute('src', '/editorial/knowledge-day.webp');
        await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Change theme' })));
        expect(image).toHaveAttribute('src', '/editorial/knowledge-night.webp');
    });

    it('uses the last rendered theme when several changes arrive in one observer batch', async () => {
        const { container } = render(<EditorialArtwork artwork="connections" />);
        const image = container.querySelector('img')!;
        await act(async () => {
            document.documentElement.dataset.theme = 'light';
            document.documentElement.dataset.theme = 'dark';
            document.documentElement.dataset.theme = 'light';
        });
        expect(image).toHaveAttribute('src', '/editorial/connections-day.webp');
        await act(async () => {
            document.documentElement.dataset.theme = 'dark';
            document.documentElement.dataset.theme = 'light';
            document.documentElement.dataset.theme = 'dark';
        });
        expect(image).toHaveAttribute('src', '/editorial/connections-night.webp');
    });

    it('does not retain an old snapshot after unmounting or resetting the root theme', async () => {
        document.documentElement.dataset.theme = 'light';
        const first = render(<EditorialArtwork artwork="community" />);
        expect(first.container.querySelector('img')).toHaveAttribute('src', '/editorial/community-day.webp');
        first.unmount();
        await setRenderedTheme();
        const second = render(<EditorialArtwork artwork="community" />);
        expect(second.container.querySelector('img')).toHaveAttribute('src', '/editorial/community-night.webp');
        await setRenderedTheme('light');
        expect(second.container.querySelector('img')).toHaveAttribute('src', '/editorial/community-day.webp');
        await setRenderedTheme();
        expect(second.container.querySelector('img')).toHaveAttribute('src', '/editorial/community-night.webp');
    });

    it('keeps small artwork on its small assets while the theme changes', async () => {
        const { container } = render(<EditorialArtwork artwork="dialogue" resolution="small" imgClassName="chat-empty-art" />);
        const image = container.querySelector('img')!;
        expect(image).toHaveAttribute('src', '/editorial/dialogue-night-small.webp');
        expect(image).not.toHaveAttribute('srcset');
        expect(image).toHaveAttribute('width', '640');
        expect(image).toHaveAttribute('height', '427');
        await setRenderedTheme('light');
        expect(image).toHaveAttribute('src', '/editorial/dialogue-day-small.webp');
        expect(container.querySelector('source')).toHaveAttribute('srcset', '/editorial/dialogue-day-small.avif');
    });

    it('uses the persisted theme on authentication screens without a separate artwork theme owner', async () => {
        localStorage.setItem('amd-theme', 'light');
        const { container } = render(<AuthLayout title="Sign in"><form aria-label="Login form" /></AuthLayout>);
        const image = container.querySelector('.editorial-auth-story img')!;
        await act(async () => undefined);
        expect(document.documentElement).toHaveAttribute('data-theme', 'light');
        expect(image).toHaveAttribute('src', '/editorial/knowledge-day.webp');
        expect(container.querySelector('.editorial-auth-story picture source')).toHaveAttribute('sizes', '(max-width: 800px) 100vw, 50vw');
        expect(screen.getByRole('form', { name: 'Login form' })).toBeInTheDocument();
    });

    it('recomputes both image formats as installation identity and color mode change independently', async () => {
        document.documentElement.dataset.designTheme = 'tech-pro';
        const { container } = render(<EditorialArtwork artwork="archive" />);
        const image = container.querySelector('img')!;
        const source = container.querySelector('source')!;
        expect(image).toHaveAttribute('src', '/editorial/themes/tech-pro/archive-night.webp');
        await setRenderedTheme('light');
        expect(image).toHaveAttribute('src', '/editorial/themes/tech-pro/archive-day.webp');
        await act(async () => { applyVisualTheme('luxury'); });
        expect(image).toHaveAttribute('srcset', '/editorial/themes/luxury/archive-day-small.webp 640w, /editorial/themes/luxury/archive-day.webp 1280w');
        expect(source).toHaveAttribute('srcset', '/editorial/themes/luxury/archive-day-small.avif 640w, /editorial/themes/luxury/archive-day.avif 1280w');
        await setRenderedTheme('dark');
        expect(image).toHaveAttribute('src', '/editorial/themes/luxury/archive-night.webp');
        await act(async () => { applyVisualTheme('humanistic'); });
        expect(image).toHaveAttribute('src', '/editorial/archive-night.webp');
    });

    it('keeps a fixed preview independent from live theme changes without mutating global appearance', async () => {
        document.documentElement.dataset.designTheme = 'tech-pro';
        document.documentElement.dataset.theme = 'dark';
        const writePreference = vi.spyOn(localStorage, 'setItem');
        const { container } = render(<EditorialArtwork artwork="observatory" designTheme="luxury" colorMode="light" resolution="small" />);
        const image = container.querySelector('img')!;
        expect(image).toHaveAttribute('src', '/editorial/themes/luxury/observatory-day-small.webp');
        expect(document.documentElement).toHaveAttribute('data-design-theme', 'tech-pro');
        expect(document.documentElement).toHaveAttribute('data-theme', 'dark');
        await act(async () => { applyVisualTheme('retro-future'); });
        await setRenderedTheme('light');
        expect(image).toHaveAttribute('src', '/editorial/themes/luxury/observatory-day-small.webp');
        expect(container.querySelector('source')).toHaveAttribute('srcset', '/editorial/themes/luxury/observatory-day-small.avif');
        expect(writePreference).not.toHaveBeenCalled();
    });

    it('allows previewing a design while still following the global personal color mode', async () => {
        const { container } = render(<EditorialArtwork artwork="community" designTheme="dynamic-office" />);
        const image = container.querySelector('img')!;
        expect(image).toHaveAttribute('src', '/editorial/themes/dynamic-office/community-night.webp');
        await setRenderedTheme('light');
        expect(image).toHaveAttribute('src', '/editorial/themes/dynamic-office/community-day.webp');
        expect(document.documentElement).not.toHaveAttribute('data-design-theme');
    });

    it.each(NEW_THEME_IDS)('renders responsive AVIF and WebP for all eight roles in %s, in both lighting modes and resolutions', (designTheme) => {
        document.documentElement.dataset.designTheme = 'humanistic';
        document.documentElement.dataset.theme = 'light';
        const { container, rerender } = render(<EditorialArtwork artwork="knowledge" designTheme={designTheme} />);

        for (const [artwork, scene] of NEW_THEME_SCENES) {
            for (const [colorMode, lighting] of [['light', 'day'], ['dark', 'night']] as const) {
                for (const resolution of ['responsive', 'small'] as const) {
                    rerender(<EditorialArtwork artwork={artwork} designTheme={designTheme} colorMode={colorMode} resolution={resolution} sizes="(max-width: 720px) 100vw, 48vw" />);
                    const image = container.querySelector('img')!;
                    const source = container.querySelector('source')!;
                    const base = `/editorial/themes/${designTheme}/${scene}-${lighting}`;
                    const small = resolution === 'small';

                    expect(source).toHaveAttribute('type', 'image/avif');
                    expect(source).toHaveAttribute('srcset', small ? `${base}-small.avif` : `${base}-small.avif 640w, ${base}.avif 1280w`);
                    expect(image).toHaveAttribute('src', `${base}${small ? '-small' : ''}.webp`);
                    if (small) {
                        expect(image).not.toHaveAttribute('srcset');
                        expect(image).not.toHaveAttribute('sizes');
                        expect(source).not.toHaveAttribute('sizes');
                    } else {
                        expect(image).toHaveAttribute('srcset', `${base}-small.webp 640w, ${base}.webp 1280w`);
                        expect(image).toHaveAttribute('sizes', '(max-width: 720px) 100vw, 48vw');
                        expect(source).toHaveAttribute('sizes', '(max-width: 720px) 100vw, 48vw');
                    }
                    expect(image).toHaveAttribute('width', small ? '640' : '1280');
                    expect(image).toHaveAttribute('height', small ? '427' : '853');
                    expect(container.querySelectorAll('picture')).toHaveLength(1);
                    expect(container.querySelectorAll('img')).toHaveLength(1);
                }
            }
        }

        expect(document.documentElement).toHaveAttribute('data-design-theme', 'humanistic');
        expect(document.documentElement).toHaveAttribute('data-theme', 'light');
    });

    it('switches a live classic identity to a world and an abstraction while preserving decoration, adjacent content and personal preferences', async () => {
        document.documentElement.dataset.designTheme = 'luxury';
        document.documentElement.dataset.theme = 'light';
        localStorage.setItem('amd-theme', 'light');
        const writePreference = vi.spyOn(localStorage, 'setItem');
        const removePreference = vi.spyOn(localStorage, 'removeItem');
        const clearPreferences = vi.spyOn(localStorage, 'clear');
        const { container } = render(<>
            <h1>Research notebook</h1>
            <form aria-label="Document details"><label>Document title<input defaultValue="Field notes" /></label><button type="button">Save document</button></form>
            <EditorialArtwork artwork="dialogue" className="live-artwork" imgClassName="live-image" sizes="42vw" />
            <EditorialArtwork artwork="trust" className="live-small-artwork" imgClassName="live-small-image" resolution="small" />
        </>);
        const heading = screen.getByRole('heading', { name: 'Research notebook' });
        const form = screen.getByRole('form', { name: 'Document details' });
        const input = screen.getByRole('textbox', { name: 'Document title' });
        const saveButton = screen.getByRole('button', { name: 'Save document' });
        fireEvent.change(input, { target: { value: 'Edited field notes' } });
        const picture = container.querySelector('.live-artwork')!;
        const image = picture.querySelector('img')!;
        const source = picture.querySelector('source')!;
        const smallPicture = container.querySelector('.live-small-artwork')!;
        const smallImage = smallPicture.querySelector('img')!;
        const smallSource = smallPicture.querySelector('source')!;

        for (const [identity, mainScene, smallScene] of [
            ['luxury', 'dialogue', 'trust'],
            ['polar', 'community', 'archive'],
            ['semantic-orbits', 'community', 'archive'],
        ] as const) {
            await act(async () => { applyVisualTheme(identity); });
            const base = `/editorial/themes/${identity}/${mainScene}-day`;
            const smallBase = `/editorial/themes/${identity}/${smallScene}-day`;
            expect(picture).toHaveAttribute('aria-hidden', 'true');
            expect(picture).toHaveClass('live-artwork');
            expect(image).toHaveClass('live-image');
            expect(image).toHaveAttribute('alt', '');
            expect(image).toHaveAttribute('src', `${base}.webp`);
            expect(image).toHaveAttribute('srcset', `${base}-small.webp 640w, ${base}.webp 1280w`);
            expect(image).toHaveAttribute('sizes', '42vw');
            expect(image).toHaveAttribute('width', '1280');
            expect(image).toHaveAttribute('height', '853');
            expect(source).toHaveAttribute('srcset', `${base}-small.avif 640w, ${base}.avif 1280w`);
            expect(source).toHaveAttribute('sizes', '42vw');
            expect(smallPicture).toHaveAttribute('aria-hidden', 'true');
            expect(smallPicture).toHaveClass('live-small-artwork');
            expect(smallImage).toHaveClass('live-small-image');
            expect(smallImage).toHaveAttribute('alt', '');
            expect(smallImage).toHaveAttribute('src', `${smallBase}-small.webp`);
            expect(smallImage).not.toHaveAttribute('srcset');
            expect(smallImage).not.toHaveAttribute('sizes');
            expect(smallImage).toHaveAttribute('width', '640');
            expect(smallImage).toHaveAttribute('height', '427');
            expect(smallSource).toHaveAttribute('srcset', `${smallBase}-small.avif`);
            expect(smallSource).not.toHaveAttribute('sizes');
            expect(screen.getByRole('heading', { name: 'Research notebook' })).toBe(heading);
            expect(screen.getByRole('form', { name: 'Document details' })).toBe(form);
            expect(screen.getByRole('textbox', { name: 'Document title' })).toBe(input);
            expect(input).toHaveValue('Edited field notes');
            expect(screen.getByRole('button', { name: 'Save document' })).toBe(saveButton);
            expect(container.querySelectorAll('picture')).toHaveLength(2);
            expect(container.querySelectorAll('img')).toHaveLength(2);
            expect(document.documentElement).toHaveAttribute('data-design-theme', identity);
            expect(document.documentElement).toHaveAttribute('data-theme', 'light');
            expect(localStorage.getItem('amd-theme')).toBe('light');
        }

        expect(writePreference).not.toHaveBeenCalled();
        expect(removePreference).not.toHaveBeenCalled();
        expect(clearPreferences).not.toHaveBeenCalled();
    });
});
