import { useSyncExternalStore } from 'react';
import type { Artwork } from '../shell/navigation';
import { getArtworkBase, getColorMode, subscribeAppearance, useVisualTheme, type ColorMode, type VisualThemeId } from '@/lib/visual-theme';

type EditorialArtworkProps = {
    artwork: Artwork;
    className?: string;
    imgClassName?: string;
    sizes?: string;
    resolution?: 'responsive' | 'small';
    designTheme?: VisualThemeId;
    colorMode?: ColorMode;
};

/** Decorative art carries no product information; the adjacent text remains authoritative. */
export function EditorialArtwork({ artwork, className, imgClassName, sizes = '(max-width: 640px) 100vw, 40vw', resolution = 'responsive', designTheme, colorMode }: EditorialArtworkProps) {
    // Observe the rendered theme without owning it or overwriting the user's preference.
    const theme = useSyncExternalStore(subscribeAppearance, getColorMode, () => 'dark' as const);
    const identity = useVisualTheme();
    const base = getArtworkBase(artwork, colorMode ?? theme, designTheme ?? identity);
    const small = resolution === 'small';
    return <picture className={className} aria-hidden="true">
        <source type="image/avif" srcSet={small ? `${base}-small.avif` : `${base}-small.avif 640w, ${base}.avif 1280w`} sizes={small ? undefined : sizes} />
        <img className={imgClassName} src={`${base}${small ? '-small' : ''}.webp`} srcSet={small ? undefined : `${base}-small.webp 640w, ${base}.webp 1280w`} sizes={small ? undefined : sizes} alt="" width={small ? 640 : 1280} height={small ? 427 : 853} decoding="async" />
    </picture>;
}
