import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyVisualTheme, getArtworkBase, getColorMode, getVisualTheme, initializeVisualTheme,
    isVisualTheme, refreshVisualTheme, useVisualTheme, type VisualThemeId,
} from './visual-theme';

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

    it('accepts exactly the five supported installation identities', () => {
        for (const theme of ['humanistic', 'tech-pro', 'retro-future', 'dynamic-office', 'luxury']) expect(isVisualTheme(theme)).toBe(true);
        for (const theme of [null, undefined, '', 'Luxury', {}, ['luxury'], 1, 'https://example.com/theme.css']) expect(isVisualTheme(theme)).toBe(false);
    });

    it('applies identity without changing the personal color mode or writing local preferences', () => {
        document.documentElement.dataset.theme = 'light';
        const writePreference = vi.spyOn(localStorage, 'setItem');
        applyVisualTheme('luxury');
        expect(getVisualTheme()).toBe('luxury');
        expect(getColorMode()).toBe('light');
        expect(getArtworkBase('archive', getColorMode())).toBe('/editorial/themes/luxury/archive-day');
        applyVisualTheme('invalid' as VisualThemeId);
        expect(getVisualTheme()).toBe('luxury');
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
