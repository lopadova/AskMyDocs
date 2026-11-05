import { useSyncExternalStore } from 'react';
import type { Artwork } from '@/components/shell/navigation';

export const VISUAL_THEMES = [
    { category: 'identity', id: 'humanistic', label: 'Humanistic', description: 'Warm materials, calm spaces, and a human perspective.', palette: { light: ['#f4f2ed', '#fffdf8', '#416147', '#735c34'], dark: ['#101318', '#191e25', '#b4c4a8', '#d7b989'] } },
    { category: 'identity', id: 'tech-pro', label: 'Tech PRO', description: 'Precise geometry, cool light, and a technology-led workspace.', palette: { light: ['#f1f6fd', '#ffffff', '#205fce', '#12667c'], dark: ['#0b1424', '#15243b', '#81b4ff', '#63d8ee'] } },
    { category: 'identity', id: 'retro-future', label: 'Retro future', description: 'Seventies optimism, sculptural forms, and warm colour.', palette: { light: ['#f7efdf', '#fff8e9', '#984227', '#216e68'], dark: ['#211b17', '#32291f', '#eda26f', '#73c2b8'] } },
    { category: 'identity', id: 'dynamic-office', label: 'Dynamic office', description: 'Energetic spaces, bold accents, and a shared sense of momentum.', palette: { light: ['#f1f6eb', '#ffffff', '#d4ef74', '#142a31'], dark: ['#0b1820', '#162c35', '#c7f05f', '#72d7c2'] } },
    { category: 'identity', id: 'luxury', label: 'Luxury offices', description: 'Refined offices, rich materials, and quiet architectural detail.', palette: { light: ['#f8f4eb', '#fffdf6', '#223550', '#675127'], dark: ['#0d1524', '#18263c', '#d5bb88', '#b5c7db'] } },
    { category: 'worlds', id: 'cartographic', label: 'Cartographic atlas', description: 'Maps, ink, and layered charts in a cartographer’s studio.', palette: { light: ['#f4ebd5', '#25333a', '#92482d', '#2b6470'], dark: ['#1b2427', '#f4ecd8', '#eeab83', '#b5c7a3'] } },
    { category: 'worlds', id: 'polar', label: 'Polar research', description: 'Field notebooks and glacial light at a polar research camp.', palette: { light: ['#f1f6fa', '#1b304b', '#4a58a6', '#215d80'], dark: ['#121a2b', '#edf4ff', '#acb8ff', '#8ad8ef'] } },
    { category: 'worlds', id: 'orbital', label: 'Orbital archive', description: 'Weightless records and Earth views in an orbital research station.', palette: { light: ['#f3f0fc', '#252141', '#6244a4', '#076a7e'], dark: ['#100e28', '#f6f0ff', '#c4a3ff', '#74ddec'] } },
    { category: 'worlds', id: 'botanical', label: 'Botanical herbarium', description: 'Pressed specimens and research sheets in a botanical greenhouse.', palette: { light: ['#f7f3e7', '#233a2c', '#32643f', '#84601e'], dark: ['#142019', '#f2f3df', '#a8cf96', '#dfbf74'] } },
    { category: 'worlds', id: 'oceanic', label: 'Ocean research', description: 'Research logs and marine light in a submerged observatory.', palette: { light: ['#eef6f3', '#193a3b', '#1d6764', '#a43f3b'], dark: ['#0e2327', '#eff8ef', '#8ad5bf', '#efa89b'] } },
    { category: 'worlds', id: 'newsroom', label: 'City newsroom', description: 'Clippings, contact sheets, and red ink in a city newsroom.', palette: { light: ['#f6f4ee', '#252727', '#a42c35', '#344f6b'], dark: ['#191b1c', '#f6f2e7', '#f2a1a5', '#a7bfd9'] } },
    { category: 'worlds', id: 'archaeological', label: 'Archaeological traces', description: 'Excavation notes and desert light at an archaeological field camp.', palette: { light: ['#f4ead8', '#382d23', '#7d4f2c', '#245f64'], dark: ['#241d19', '#f7ecd5', '#ddb888', '#95c4bf'] } },
    { category: 'worlds', id: 'alpine', label: 'Alpine retreat', description: 'Survey maps and warm timber in an alpine research retreat.', palette: { light: ['#f6f3eb', '#203c31', '#2e6147', '#92452c'], dark: ['#16231e', '#eff3e2', '#a4cdaf', '#e4ac85'] } },
    { category: 'worlds', id: 'paper-world', label: 'Paper landscapes', description: 'Folded records and paths of discovery in a sculpted paper world.', palette: { light: ['#f8f5eb', '#292d32', '#2e52b1', '#5a596b'], dark: ['#1b1e27', '#f7f2e5', '#a2b9ff', '#cfbdb0'] } },
    { category: 'worlds', id: 'blueprint', label: 'Blueprint workshop', description: 'Technical drawings and precise ink in an engineering workshop.', palette: { light: ['#f1f5f8', '#1d354c', '#215bb5', '#9b4c19'], dark: ['#10253d', '#edf5f7', '#98c3f4', '#f0b37a'] } },
    { category: 'abstract', id: 'semantic-orbits', label: 'Semantic orbits', description: 'Translucent forms and orbital paths reveal connections between documents.', palette: { light: ['#f7f3ec', '#2d293b', '#7050a4', '#a84745'], dark: ['#1c1c2b', '#f6f0e8', '#c2b0f0', '#f1aa9e'] } },
    { category: 'abstract', id: 'prismatic-index', label: 'Prismatic index', description: 'Glass prisms and layers of light turn research into a clear visual rhythm.', palette: { light: ['#f0f7f9', '#23333b', '#086a7c', '#9e357b'], dark: ['#111b25', '#edf7f8', '#83d6e8', '#f0a5d1'] } },
    { category: 'abstract', id: 'ink-flow', label: 'Ink flows', description: 'Fluid ink and textured paper trace the flow from sources to insight.', palette: { light: ['#f7f3e9', '#252b38', '#3e5591', '#945327'], dark: ['#1b2029', '#f5efdf', '#b0bfe8', '#e1b286'] } },
] as const;

export type VisualThemeId = (typeof VISUAL_THEMES)[number]['id'];
export type ColorMode = 'light' | 'dark';
export type ThemeCategory = (typeof VISUAL_THEMES)[number]['category'];

/** Each world has a discovery scene, a source archive and a collaboration scene. */
const WORLD_ARTWORKS: Record<Artwork, 'knowledge' | 'archive' | 'community'> = {
    knowledge: 'knowledge', observatory: 'knowledge',
    archive: 'archive', processes: 'archive', trust: 'archive',
    community: 'community', dialogue: 'community', connections: 'community',
};

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
    const category = VISUAL_THEMES.find((entry) => entry.id === theme)?.category;
    const scene = category !== 'identity' ? WORLD_ARTWORKS[artwork] : artwork;
    return `${directory}/${scene}-${mode === 'light' ? 'day' : 'night'}`;
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
