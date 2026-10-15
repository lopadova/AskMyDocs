import type { ReactNode } from 'react';
import type { Artwork } from '../shell/navigation';
import { EditorialArtwork } from './EditorialArtwork';

export function EditorialHero({ artwork, eyebrow, title, description, children, compact = false }: {
    artwork: Artwork; eyebrow?: string; title: string; description?: string; children?: ReactNode; compact?: boolean;
}) {
    return <section className={`editorial-hero${compact ? ' editorial-hero--compact' : ''}`}>
        <div className="editorial-hero-copy">
            {eyebrow && <p className="editorial-eyebrow">{eyebrow}</p>}
            <h1>{title}</h1>
            {description && <p className="editorial-hero-description">{description}</p>}
            {children && <div className="editorial-hero-actions">{children}</div>}
        </div>
        <EditorialArtwork className="editorial-hero-art" artwork={artwork} />
    </section>;
}
