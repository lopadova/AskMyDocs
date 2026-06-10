/*
 * Per-operator explorer preferences (grid vs list, tile size). Persisted
 * to localStorage rather than the URL — they are a viewing preference,
 * not part of a shareable deep link, so keeping them out of the zod
 * search schema keeps the URL lean.
 */

export type ExplorerLayout = 'grid' | 'list';
export type ExplorerTileSize = 'sm' | 'md' | 'lg';

export interface ExplorerPrefs {
    layout: ExplorerLayout;
    size: ExplorerTileSize;
}

const STORAGE_KEY = 'kb-explorer-prefs';
const DEFAULTS: ExplorerPrefs = { layout: 'grid', size: 'md' };

export function loadExplorerPrefs(): ExplorerPrefs {
    if (typeof window === 'undefined') {
        return DEFAULTS;
    }
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (raw === null) {
            return DEFAULTS;
        }
        const parsed = JSON.parse(raw) as Partial<ExplorerPrefs>;
        return {
            layout: parsed.layout === 'list' ? 'list' : 'grid',
            size: parsed.size === 'sm' || parsed.size === 'lg' ? parsed.size : 'md',
        };
    } catch {
        return DEFAULTS;
    }
}

export function saveExplorerPrefs(prefs: ExplorerPrefs): void {
    if (typeof window === 'undefined') {
        return;
    }
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    } catch {
        // Quota / privacy mode — preferences just won't persist.
    }
}
