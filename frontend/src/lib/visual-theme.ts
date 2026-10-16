import { useSyncExternalStore } from 'react';
import type { Artwork } from '@/components/shell/navigation';

export const VISUAL_THEMES = [
    { id: 'humanistic', label: 'Humanistic', description: 'Warm materials, calm spaces, and a human perspective.', palette: { light: ['#f4f2ed', '#fffdf8', '#416147', '#735c34'], dark: ['#101318', '#191e25', '#b4c4a8', '#d7b989'] } },
    { id: 'tech-pro', label: 'Tech PRO', description: 'Precise geometry, cool light, and a technology-led workspace.', palette: { light: ['#f1f6fd', '#ffffff', '#205fce', '#12667c'], dark: ['#0b1424', '#15243b', '#81b4ff', '#63d8ee'] } },
    { id: 'retro-future', label: 'Retro future', description: 'Seventies optimism, sculptural forms, and warm colour.', palette: { light: ['#f7efdf', '#fff8e9', '#984227', '#216e68'], dark: ['#211b17', '#32291f', '#eda26f', '#73c2b8'] } },
    { id: 'dynamic-office', label: 'Dynamic office', description: 'Energetic spaces, bold accents, and a shared sense of momentum.', palette: { light: ['#f1f6eb', '#ffffff', '#d4ef74', '#142a31'], dark: ['#0b1820', '#162c35', '#c7f05f', '#72d7c2'] } },
    { id: 'luxury', label: 'Luxury offices', description: 'Refined offices, rich materials, and quiet architectural detail.', palette: { light: ['#f8f4eb', '#fffdf6', '#223550', '#675127'], dark: ['#0d1524', '#18263c', '#d5bb88', '#b5c7db'] } },
] as const;

export type VisualThemeId = (typeof VISUAL_THEMES)[number]['id'];
export type ColorMode = 'light' | 'dark';

export function isVisualTheme(value: unknown): value is VisualThemeId {
    return typeof value === 'string' && VISUAL_THEMES.some((theme) => theme.id === value);
}

export function getVisualTheme(): VisualThemeId {
    const value = typeof document === 'undefined' ? undefined : document.documentElement.dataset.designTheme;
    return isVisualTheme(value) ? value : 'humanistic';
}

export function getColorMode(): ColorMode {
    return typeof document !== 'undefined' && document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
}

export function subscribeAppearance(onChange: () => void): () => void {
    const observer = new MutationObserver(onChange);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'data-design-theme'] });
    return () => observer.disconnect();
}

export function useVisualTheme(): VisualThemeId {
    return useSyncExternalStore(subscribeAppearance, getVisualTheme, () => 'humanistic');
}

export function getArtworkBase(artwork: Artwork, mode: ColorMode, theme: VisualThemeId = getVisualTheme()): string {
    const directory = theme === 'humanistic' ? '/editorial' : `/editorial/themes/${theme}`;
    return `${directory}/${artwork}-${mode === 'light' ? 'day' : 'night'}`;
}

let revision = 0;
let refreshSequence = 0;

/** Installation identity is server-owned; light/dark remains a personal preference. */
export function applyVisualTheme(theme: VisualThemeId): void {
    if (!isVisualTheme(theme) || typeof document === 'undefined') return;
    revision += 1;
    document.documentElement.dataset.designTheme = theme;
}

/** Reconcile an already open tab when returning after a super-admin changed the theme. */
export async function refreshVisualTheme(): Promise<void> {
    const started = revision;
    const request = ++refreshSequence;
    try {
        const response = await fetch('/api/appearance', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (!response.ok) return;
        const payload: unknown = await response.json();
        const theme = (payload as { data?: { theme?: unknown } })?.data?.theme;
        // An in-flight read must never undo a more recent successful save or bootstrap.
        if (request === refreshSequence && started === revision && isVisualTheme(theme)) applyVisualTheme(theme);
    } catch { /* Keep the server-rendered identity while offline. */ }
}

export function initializeVisualTheme(): () => void {
    const refresh = () => { if (document.visibilityState !== 'hidden') void refreshVisualTheme(); };
    window.addEventListener('focus', refresh);
    window.addEventListener('pageshow', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => {
        window.removeEventListener('focus', refresh);
        window.removeEventListener('pageshow', refresh);
        document.removeEventListener('visibilitychange', refresh);
    };
}
