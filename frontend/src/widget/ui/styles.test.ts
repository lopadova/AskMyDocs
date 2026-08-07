import { describe, it, expect } from 'vitest';

import { DEFAULT_THEME, buildThemeCss, mergeThemeLayers, sanitizeTheme } from './styles';

describe('sanitizeTheme', () => {
    it('returns the full default theme for empty input', () => {
        expect(sanitizeTheme({})).toEqual(DEFAULT_THEME);
        expect(sanitizeTheme(undefined)).toEqual(DEFAULT_THEME);
        expect(sanitizeTheme(null)).toEqual(DEFAULT_THEME);
    });

    it('keeps valid values and lowercases hex colours', () => {
        const t = sanitizeTheme({
            accent: '#FF8800',
            fontFamily: 'inter',
            fontSize: 16,
            launcherShape: 'circle',
            launcherSide: 'left',
            panelWidth: 680,
            sourceViewerWidth: 1120,
            panelShadow: 'soft',
        });
        expect(t.accent).toBe('#ff8800');
        expect(t.fontFamily).toBe('inter');
        expect(t.fontSize).toBe(16);
        expect(t.launcherShape).toBe('circle');
        expect(t.launcherSide).toBe('left');
        expect(t.panelWidth).toBe(680);
        expect(t.sourceViewerWidth).toBe(1120);
        expect(t.panelShadow).toBe('soft');
    });

    it('rejects a CSS-injection colour and falls back to default (R19)', () => {
        const t = sanitizeTheme({ accent: '#fff; } body { display: none } .x{', background: 'red' });
        expect(t.accent).toBe(DEFAULT_THEME.accent);
        expect(t.background).toBe(DEFAULT_THEME.background);
    });

    it('clamps numbers into range', () => {
        expect(sanitizeTheme({ fontSize: 999 }).fontSize).toBe(18);
        expect(sanitizeTheme({ fontSize: 1 }).fontSize).toBe(12);
        expect(sanitizeTheme({ panelWidth: 9999 }).panelWidth).toBe(720);
        expect(sanitizeTheme({ panelHeight: 9999 }).panelHeight).toBe(900);
        expect(sanitizeTheme({ sourceViewerWidth: 1 }).sourceViewerWidth).toBe(560);
        expect(sanitizeTheme({ sourceViewerWidth: 9999 }).sourceViewerWidth).toBe(1200);
        expect(sanitizeTheme({ bubbleMaxWidth: 1 }).bubbleMaxWidth).toBe(50);
        expect(sanitizeTheme({ panelRadius: -50 }).panelRadius).toBe(0);
        expect(sanitizeTheme({ fontSize: 13.6 }).fontSize).toBe(14);
        expect(sanitizeTheme({ fontSize: '13.6' }).fontSize).toBe(14);
        expect(sanitizeTheme({ fontSize: '1e1' }).fontSize).toBe(12);
        expect(sanitizeTheme({ fontSize: '0x10' }).fontSize).toBe(DEFAULT_THEME.fontSize);
        expect(sanitizeTheme({ fontSize: '0b1111' }).fontSize).toBe(DEFAULT_THEME.fontSize);
    });

    it('rejects non-allowlisted fonts and enums', () => {
        const t = sanitizeTheme({
            fontFamily: 'Comic Sans; }',
            launcherShape: 'hexagon',
            launcherIcon: '<svg>',
            launcherShadow: '0 0 99px red',
            panelShadow: 'url(javascript:alert(1))',
        });
        expect(t.fontFamily).toBe('system');
        expect(t.launcherShape).toBe('pill');
        expect(t.launcherIcon).toBe('chat');
        expect(t.launcherShadow).toBe('medium');
        expect(t.panelShadow).toBe('strong');
    });

    it('sanitizes the widget mode (helper default, inline allowed, garbage → helper)', () => {
        expect(sanitizeTheme({}).mode).toBe('helper');
        expect(sanitizeTheme({ mode: 'inline' }).mode).toBe('inline');
        expect(sanitizeTheme({ mode: 'floating' }).mode).toBe('helper');
    });

    it('accepts an https image URL but rejects unsafe ones', () => {
        expect(sanitizeTheme({ headerLogoUrl: 'https://cdn.example.com/l.png' }).headerLogoUrl).toBe(
            'https://cdn.example.com/l.png',
        );
        expect(sanitizeTheme({ headerLogoUrl: 'http://cdn.example.com/l.png' }).headerLogoUrl).toBe('');
        expect(sanitizeTheme({ headerLogoUrl: 'https:cdn.example.com/l.png' }).headerLogoUrl).toBe('');
        expect(sanitizeTheme({ headerLogoUrl: 'https:/cdn.example.com/l.png' }).headerLogoUrl).toBe('');
        expect(sanitizeTheme({ launcherIconUrl: 'javascript:alert(1)' }).launcherIconUrl).toBe('');
        expect(sanitizeTheme({ launcherIconUrl: 'https://x.com/a") url(' }).launcherIconUrl).toBe('');
    });

    it('strips control characters and caps label length', () => {
        expect(sanitizeTheme({ launcherLabel: 'Ask\x00\x1f me' }).launcherLabel).toBe('Ask me');
        expect(sanitizeTheme({ panelTitle: 'x'.repeat(200) }).panelTitle).toHaveLength(60);
        expect(sanitizeTheme({ panelTitle: `${'a'.repeat(59)}😀` }).panelTitle)
            .toBe(`${'a'.repeat(59)}😀`);
    });

    it('merges valid inline values over the server theme', () => {
        const merged = mergeThemeLayers(
            { accent: '#111111', fontSize: 12 },
            { accent: '#222222' },
        );
        expect(merged.accent).toBe('#222222');
        expect(merged.fontSize).toBe(12);
    });

    it('keeps valid server values when higher-priority inline values are invalid', () => {
        const merged = mergeThemeLayers(
            {
                accent: '#111111',
                panelWidth: 640,
                launcherLabel: 'Server label',
                headerLogoUrl: 'https://cdn.example.com/server.png',
            },
            {
                accent: 'red',
                panelWidth: Number.NaN,
                launcherLabel: 42,
                headerLogoUrl: 'javascript:alert(1)',
            },
        );

        expect(merged.accent).toBe('#111111');
        expect(merged.panelWidth).toBe(640);
        expect(merged.launcherLabel).toBe('Server label');
        expect(merged.headerLogoUrl).toBe('https://cdn.example.com/server.png');
    });

    it('allows an explicit empty inline label or URL to clear the server value', () => {
        const merged = mergeThemeLayers(
            {
                launcherLabel: 'Server label',
                headerLogoUrl: 'https://cdn.example.com/server.png',
            },
            { launcherLabel: '', headerLogoUrl: '' },
        );

        expect(merged.launcherLabel).toBe('');
        expect(merged.headerLogoUrl).toBe('');
    });
});

describe('buildThemeCss', () => {
    it('emits sanitized CSS custom properties on .amd-root', () => {
        const css = buildThemeCss(sanitizeTheme({ accent: '#10b981', fontFamily: 'mono', fontSize: 17 }));
        expect(css).toContain('.amd-root{');
        expect(css).toContain('--amd-accent:var(--askmydocs-accent,#10b981);');
        expect(css).toContain('--amd-font-size:var(--askmydocs-font-size,17px);');
        expect(css).toContain("'SFMono-Regular'"); // mono stack
    });

    it('never leaks an injection payload into the CSS (R19)', () => {
        const css = buildThemeCss(sanitizeTheme({ accent: '#fff; } body{display:none} .x{color:red' }));
        expect(css).not.toContain('display:none');
        expect(css).not.toContain('body{');
        expect(css).toContain(`--amd-accent:var(--askmydocs-accent,${DEFAULT_THEME.accent});`);
    });

    it('does not emit the widget mode as a CSS var (it is structural, like launcherSide)', () => {
        const css = buildThemeCss(sanitizeTheme({ mode: 'inline' }));
        expect(css).not.toContain('mode');
        expect(css).not.toContain('inline');
    });

    it('wraps every advanced CSS token in a host override variable', () => {
        const css = buildThemeCss(
            sanitizeTheme({
                accentForeground: '#010203',
                composerBackground: '#111213',
                sourceBackdrop: '#141516cc',
                launcherOffsetX: 31,
                panelWidth: 700,
                bubbleMaxWidth: 72,
                sourceViewerWidth: 1180,
                sourceViewerRadius: 24,
                launcherShadow: 'none',
            }),
        );

        expect(css).toContain('--amd-accent-fg:var(--askmydocs-accent-foreground,#010203);');
        expect(css).toContain('--amd-composer-bg:var(--askmydocs-composer-background,#111213);');
        expect(css).toContain('--amd-source-backdrop:var(--askmydocs-source-backdrop,#141516cc);');
        expect(css).toContain('--amd-launcher-offset-x:var(--askmydocs-launcher-offset-x,31px);');
        expect(css).toContain('--amd-panel-width:var(--askmydocs-panel-width,700px);');
        expect(css).toContain('--amd-bubble-max-width:var(--askmydocs-bubble-max-width,72%);');
        expect(css).toContain('--amd-source-viewer-width:var(--askmydocs-source-viewer-width,1180px);');
        expect(css).toContain('--amd-source-viewer-radius:var(--askmydocs-source-viewer-radius,24px);');
        expect(css).toContain('--amd-launcher-shadow:var(--askmydocs-launcher-shadow,none);');
    });

    it('never interpolates an untrusted shadow value', () => {
        const css = buildThemeCss(
            sanitizeTheme({ launcherShadow: 'none;}body{display:none', panelShadow: 'url(javascript:alert(1))' }),
        );
        expect(css).not.toContain('body{');
        expect(css).not.toContain('javascript:');
        expect(css).toContain('rgba(15,23,42,.18)');
        expect(css).toContain('rgba(15,23,42,.24)');
    });
});
