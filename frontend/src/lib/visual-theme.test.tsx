import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Artwork } from '@/components/shell/navigation';
import {
    applyVisualTheme, getArtworkBase, getColorMode, getVisualTheme, initializeVisualTheme,
    isVisualTheme, refreshVisualTheme, useVisualTheme, VISUAL_THEMES, type VisualThemeId,
} from './visual-theme';

const CLASSIC_IDENTITIES = ['humanistic', 'tech-pro', 'retro-future', 'dynamic-office', 'luxury'] as const;
const WORLD_IDENTITIES = [
    'cartographic', 'polar', 'orbital', 'botanical', 'oceanic',
    'newsroom', 'archaeological', 'alpine', 'paper-world', 'blueprint',
] as const;
const ABSTRACT_IDENTITIES = ['semantic-orbits', 'prismatic-index', 'ink-flow'] as const;
const ROLE_SCENES: Record<Artwork, 'knowledge' | 'archive' | 'community'> = {
    knowledge: 'knowledge', dialogue: 'community', archive: 'archive', connections: 'community',
    processes: 'archive', community: 'community', trust: 'archive', observatory: 'knowledge',
};

function themeResponse(theme: unknown): Response {
    return new Response(JSON.stringify({ data: { theme } }), { status: 200 });
}

function deferredResponse() {
    let resolve!: (response: Response) => void;
    const promise = new Promise<Response>(done => { resolve = done; });
    return { promise, resolve };
}

let originalIdentity: string | null;
let originalMode: string | null;
const disposers: Array<() => void> = [];

beforeEach(() => {
    originalIdentity = document.documentElement.getAttribute('data-design-theme');
    originalMode = document.documentElement.getAttribute('data-theme');
    document.documentElement.removeAttribute('data-design-theme');
    document.documentElement.removeAttribute('data-theme');
});

afterEach(() => {
    cleanup();
    disposers.splice(0).forEach(dispose => dispose());
    if (originalIdentity === null) document.documentElement.removeAttribute('data-design-theme');
    else document.documentElement.setAttribute('data-design-theme', originalIdentity);
    if (originalMode === null) document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', originalMode);
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('installation visual theme', () => {
    it('uses the safe default for absent or unrecognised server attributes', () => {
        expect(getVisualTheme()).toBe('humanistic');
        expect(getColorMode()).toBe('dark');
        document.documentElement.dataset.designTheme = '../../untrusted';
        document.documentElement.dataset.theme = 'unsupported';
        expect(getVisualTheme()).toBe('humanistic');
        expect(getColorMode()).toBe('dark');
        expect(getArtworkBase('knowledge', 'dark')).toBe('/editorial/knowledge-night');
    });

    it('accepts all eighteen installation themes while rejecting category names and unsafe values', () => {
        const allowed = [...CLASSIC_IDENTITIES, ...WORLD_IDENTITIES, ...ABSTRACT_IDENTITIES];
        expect(VISUAL_THEMES.map((theme) => theme.id)).toEqual(allowed);
        for (const theme of allowed) expect(isVisualTheme(theme)).toBe(true);
        for (const theme of [null, undefined, '', 'Luxury', {}, ['luxury'], 1, 'all', 'identity', 'worlds', 'abstract', 'https://example.com/theme.css']) expect(isVisualTheme(theme)).toBe(false);
    });

    it('groups eighteen unique installation IDs into five identities, ten worlds and three abstractions', () => {
        expect(VISUAL_THEMES).toHaveLength(18);
        expect(new Set(VISUAL_THEMES.map((theme) => theme.id)).size).toBe(18);
        expect(new Set(VISUAL_THEMES.map((theme) => theme.category))).toEqual(new Set(['identity', 'worlds', 'abstract']));
        expect(VISUAL_THEMES.filter((theme) => theme.category === 'identity').map((theme) => theme.id)).toEqual(CLASSIC_IDENTITIES);
        expect(VISUAL_THEMES.filter((theme) => theme.category === 'worlds').map((theme) => theme.id)).toEqual(WORLD_IDENTITIES);
        expect(VISUAL_THEMES.filter((theme) => theme.category === 'abstract').map((theme) => theme.id)).toEqual(ABSTRACT_IDENTITIES);
    });

    it.each([...WORLD_IDENTITIES, ...ABSTRACT_IDENTITIES])('maps every editorial role to the three available scenes for %s in day and night', (identity) => {
        for (const mode of ['light', 'dark'] as const) {
            const lighting = mode === 'light' ? 'day' : 'night';
            for (const [role, scene] of Object.entries(ROLE_SCENES)) {
                expect(getArtworkBase(role as Artwork, mode, identity)).toBe(`/editorial/themes/${identity}/${scene}-${lighting}`);
            }
        }
    });

    it.each(CLASSIC_IDENTITIES)('preserves all eight original artwork filenames for %s in day and night', (identity) => {
        const directory = identity === 'humanistic' ? '/editorial' : `/editorial/themes/${identity}`;
        for (const mode of ['light', 'dark'] as const) {
            const lighting = mode === 'light' ? 'day' : 'night';
            for (const role of Object.keys(ROLE_SCENES)) {
                expect(getArtworkBase(role as Artwork, mode, identity)).toBe(`${directory}/${role}-${lighting}`);
            }
        }
    });

    it('applies identity without changing the personal color mode or writing local preferences', () => {
        document.documentElement.dataset.theme = 'light';
        const writePreference = vi.spyOn(localStorage, 'setItem');
        applyVisualTheme('ink-flow');
        expect(getVisualTheme()).toBe('ink-flow');
        expect(getColorMode()).toBe('light');
        expect(getArtworkBase('observatory', getColorMode())).toBe('/editorial/themes/ink-flow/knowledge-day');
        applyVisualTheme('invalid' as VisualThemeId);
        expect(getVisualTheme()).toBe('ink-flow');
        expect(writePreference).not.toHaveBeenCalled();
    });

    it('does not let a browser-local identity replace the installation value', () => {
        const previous = localStorage.getItem('amd-design-theme');
        try {
            localStorage.setItem('amd-design-theme', 'luxury');
            expect(getVisualTheme()).toBe('humanistic');
            applyVisualTheme('tech-pro');
            expect(getVisualTheme()).toBe('tech-pro');
            expect(localStorage.getItem('amd-design-theme')).toBe('luxury');
        } finally {
            if (previous === null) localStorage.removeItem('amd-design-theme');
            else localStorage.setItem('amd-design-theme', previous);
        }
    });

    it('notifies consumers when the root identity changes independently of light and dark', async () => {
        function Identity() { return <output aria-label="Installation identity">{useVisualTheme()}</output>; }
        render(<Identity />);
        expect(screen.getByLabelText('Installation identity')).toHaveTextContent('humanistic');
        await act(async () => { applyVisualTheme('tech-pro'); });
        expect(screen.getByLabelText('Installation identity')).toHaveTextContent('tech-pro');
        await act(async () => { document.documentElement.dataset.theme = 'light'; });
        expect(screen.getByLabelText('Installation identity')).toHaveTextContent('tech-pro');
    });

    it('refreshes through the public endpoint without tenant scope or HTTP caching', async () => {
        const fetchTheme = vi.fn<typeof fetch>().mockResolvedValue(themeResponse('dynamic-office'));
        vi.stubGlobal('fetch', fetchTheme);
        await refreshVisualTheme();
        expect(fetchTheme).toHaveBeenCalledWith('/api/appearance', {
            credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store',
        });
        expect(getVisualTheme()).toBe('dynamic-office');
    });

    it.each([null, {}, 'unknown', ['luxury']])('preserves the rendered identity for invalid payload theme %j', async (theme) => {
        applyVisualTheme('retro-future');
        vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValue(themeResponse(theme)));
        await refreshVisualTheme();
        expect(getVisualTheme()).toBe('retro-future');
    });

    it('preserves the rendered identity on network, HTTP and JSON failures', async () => {
        applyVisualTheme('luxury');
        const fetchTheme = vi.fn<typeof fetch>()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(new Response('', { status: 503 }))
            .mockResolvedValueOnce(new Response('not-json', { status: 200 }));
        vi.stubGlobal('fetch', fetchTheme);
        await refreshVisualTheme();
        await refreshVisualTheme();
        await refreshVisualTheme();
        expect(getVisualTheme()).toBe('luxury');
    });

    it('never lets an in-flight GET undo a newer successful save or auth bootstrap', async () => {
        const pending = deferredResponse();
        vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockReturnValue(pending.promise));
        const refresh = refreshVisualTheme();
        applyVisualTheme('luxury');
        pending.resolve(themeResponse('humanistic'));
        await refresh;
        expect(getVisualTheme()).toBe('luxury');
    });

    it.each(['older-first', 'newer-first'])('accepts only the newest refresh when responses arrive %s', async (order) => {
        const older = deferredResponse();
        const newer = deferredResponse();
        vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise));
        const first = refreshVisualTheme();
        const second = refreshVisualTheme();
        if (order === 'older-first') {
            older.resolve(themeResponse('tech-pro')); await first;
            newer.resolve(themeResponse('luxury')); await second;
        } else {
            newer.resolve(themeResponse('luxury')); await second;
            older.resolve(themeResponse('tech-pro')); await first;
        }
        expect(getVisualTheme()).toBe('luxury');
    });

    it('refreshes on visible focus/pageshow/visibility events and removes all listeners on cleanup', async () => {
        const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
        const fetchTheme = vi.fn<typeof fetch>()
            .mockResolvedValueOnce(themeResponse('tech-pro'))
            .mockResolvedValueOnce(themeResponse('retro-future'))
            .mockResolvedValueOnce(themeResponse('luxury'));
        vi.stubGlobal('fetch', fetchTheme);
        const dispose = initializeVisualTheme();
        disposers.push(dispose);
        window.dispatchEvent(new Event('focus'));
        window.dispatchEvent(new Event('pageshow'));
        document.dispatchEvent(new Event('visibilitychange'));
        expect(fetchTheme).not.toHaveBeenCalled();
        visibility.mockReturnValue('visible');
        window.dispatchEvent(new Event('focus'));
        await waitFor(() => expect(getVisualTheme()).toBe('tech-pro'));
        window.dispatchEvent(new Event('pageshow'));
        await waitFor(() => expect(getVisualTheme()).toBe('retro-future'));
        document.dispatchEvent(new Event('visibilitychange'));
        await waitFor(() => expect(getVisualTheme()).toBe('luxury'));
        dispose();
        window.dispatchEvent(new Event('focus'));
        window.dispatchEvent(new Event('pageshow'));
        document.dispatchEvent(new Event('visibilitychange'));
        expect(fetchTheme).toHaveBeenCalledTimes(3);
    });
});
