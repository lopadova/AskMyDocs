import { createRoot } from 'react-dom/client';
import { LandingLanguageSelect } from './features/landing/LandingLanguageSelect';
import './styles/design-themes.css';
import { getArtworkBase, initializeVisualTheme, subscribeAppearance } from './lib/visual-theme';
import type { Artwork } from './components/shell/navigation';

// The public page starts with the same server-rendered identity as the app.
// Keep an already-open landing current after a super-admin changes that identity.
function updateLandingArtwork() {
    const mode = document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
    document.querySelectorAll<HTMLImageElement>('img[data-editorial-artwork]').forEach((image) => {
        const base = getArtworkBase(image.dataset.editorialArtwork as Artwork, mode);
        image.srcset = `${base}-small.webp 640w, ${base}.webp 1280w`;
        image.src = `${base}.webp`;
    });
    const base = getArtworkBase('knowledge', mode);
    const preload = document.querySelector<HTMLLinkElement>('[data-editorial-preload]');
    if (preload) { preload.href = `${base}.webp`; preload.imageSrcset = `${base}-small.webp 640w, ${base}.webp 1280w`; }
    const og = document.querySelector<HTMLMetaElement>('[data-editorial-og]');
    if (og) og.content = `${base}.webp`;
}
initializeVisualTheme();
subscribeAppearance(updateLandingArtwork);
updateLandingArtwork();

const container = document.getElementById('language-select-root');
if (container) createRoot(container).render(<LandingLanguageSelect container={container} />);
