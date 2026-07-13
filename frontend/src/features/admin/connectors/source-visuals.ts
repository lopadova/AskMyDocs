/*
 * Pure visual helpers for a connector "source" — the brand-coloured letter
 * avatar used when the real `icon_url` image is absent or fails to load
 * (SourceAvatar renders the image first, this is the fallback).
 *
 * Extracted so the colour/letter derivation is unit-testable without React.
 * Brand colours mirror the "Miglioramento usabilità card" design handoff
 * (Connectors.dc.html) for the eight reference connectors; any other key
 * (in-house / community connector) gets a deterministic colour from the
 * shared palette so two different sources never collide by accident.
 */

export interface SourceAvatarStyle {
    /** Single glyph rendered in the fallback avatar (e.g. `C`, `@`). */
    letter: string;
    /** Avatar background (a brand colour, or a deterministic palette entry). */
    bg: string;
    /** Foreground/glyph colour with sufficient contrast against `bg`. */
    fg: string;
}

/**
 * Known reference connectors → brand colour + (optional) glyph override. Keyed
 * by `ConnectorInterface::key()`. `imap` uses `@` rather than its first letter
 * because "Email (IMAP)" would otherwise read as a bare `E`.
 */
const BRAND: Record<string, { bg: string; fg: string; letter?: string }> = {
    confluence: { bg: '#1868DB', fg: '#ffffff' },
    evernote: { bg: '#0FA958', fg: '#ffffff' },
    fabric: { bg: '#7C3AED', fg: '#ffffff' },
    'google-drive': { bg: '#F4B400', fg: '#1a1a1a' },
    imap: { bg: '#6366F1', fg: '#ffffff', letter: '@' },
    jira: { bg: '#2684FF', fg: '#ffffff' },
    notion: { bg: '#EDEEF0', fg: '#111111' },
    onedrive: { bg: '#0364B8', fg: '#ffffff' },
};

/**
 * Deterministic fallback palette for unknown connector keys. Chosen for legible
 * white-on-colour contrast; picked by a stable hash of the key so the same
 * connector always renders the same colour across reloads and views.
 */
const PALETTE: ReadonlyArray<{ bg: string; fg: string }> = [
    { bg: '#4f46e5', fg: '#ffffff' },
    { bg: '#0891b2', fg: '#ffffff' },
    { bg: '#059669', fg: '#ffffff' },
    { bg: '#d97706', fg: '#1a1a1a' },
    { bg: '#dc2626', fg: '#ffffff' },
    { bg: '#7c3aed', fg: '#ffffff' },
    { bg: '#db2777', fg: '#ffffff' },
    { bg: '#0284c7', fg: '#ffffff' },
];

/** First visible glyph of a name, uppercased; `?` when the name is empty. */
function initial(displayName: string, key: string): string {
    const source = displayName.trim() || key.trim();
    return (source[0] ?? '?').toUpperCase();
}

/** Stable, non-negative 32-bit hash so palette selection is reproducible. */
function hashKey(key: string): number {
    let h = 0;
    for (let i = 0; i < key.length; i += 1) {
        h = (Math.imul(h, 31) + key.charCodeAt(i)) >>> 0;
    }
    return h;
}

/**
 * Resolve the fallback avatar (letter + colours) for a connector source. Known
 * keys use their brand colour; everything else gets a deterministic palette
 * entry keyed by the connector key.
 */
export function sourceAvatar(key: string, displayName: string): SourceAvatarStyle {
    const brand = BRAND[key];
    const letter = brand?.letter ?? initial(displayName, key);
    if (brand) {
        return { letter, bg: brand.bg, fg: brand.fg };
    }
    const palette = PALETTE[hashKey(key) % PALETTE.length];
    return { letter, bg: palette.bg, fg: palette.fg };
}
