import type { Artwork } from '../shell/navigation';

/** Decorative art carries no product information; the adjacent text remains authoritative. */
export function EditorialArtwork({ artwork, className }: { artwork: Artwork; className?: string }) {
    return <picture className={className} aria-hidden="true">
        <source type="image/avif" srcSet={`/editorial/${artwork}-small.avif 640w, /editorial/${artwork}.avif 1280w`} sizes="(max-width: 640px) 100vw, 40vw" />
        <img src={`/editorial/${artwork}.webp`} srcSet={`/editorial/${artwork}-small.webp 640w, /editorial/${artwork}.webp 1280w`} sizes="(max-width: 640px) 100vw, 40vw" alt="" width={1280} height={853} decoding="async" />
    </picture>;
}
