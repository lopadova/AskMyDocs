import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthLayout } from '../../features/auth/AuthLayout';
import { useTheme } from '../shell/hooks';
import { EditorialArtwork } from './EditorialArtwork';

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
    beforeEach(() => {
        document.documentElement.removeAttribute('data-theme');
        localStorage.removeItem('amd-theme');
    });

    afterEach(() => {
        cleanup();
        document.documentElement.removeAttribute('data-theme');
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
});
