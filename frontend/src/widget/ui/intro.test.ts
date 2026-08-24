import { describe, expect, it, vi } from 'vitest';

import { DEFAULT_INTRO, IntroCard, mergeIntroLayers, sanitizeIntro } from './intro';

describe('widget intro contract', () => {
    it('sanitizes untrusted values and enforces collection limits', () => {
        const intro = sanitizeIntro({
            enabled: true,
            variant: 'invalid',
            title: '<img src=x onerror=alert(1)>',
            imageUrl: 'javascript:alert(1)',
            bullets: ['a', 'b', 'c', 'd', 'e'],
            suggestions: [{ label: 'Open', prompt: 'Show docs' }, { label: '', prompt: 'bad' }],
        });

        expect(intro.variant).toBe('card');
        expect(intro.imageUrl).toBe('');
        expect(intro.bullets).toEqual(['a', 'b', 'c', 'd']);
        expect(intro.suggestions).toEqual([{ label: 'Open', prompt: 'Show docs' }]);
    });

    it('merges server defaults with a host override and supports explicit disable', () => {
        expect(mergeIntroLayers(
            { enabled: true, title: 'Server', body: 'Server body' },
            { title: 'Page' },
        )).toMatchObject({ enabled: true, title: 'Page', body: 'Server body' });
        expect(mergeIntroLayers({ enabled: true, title: 'Server' }, false)).toEqual(DEFAULT_INTRO);
    });

    it('renders text safely and sends a suggestion prompt', () => {
        const container = document.createElement('div');
        const onSuggestion = vi.fn();
        const card = new IntroCard(container, onSuggestion);
        card.show(sanitizeIntro({
            enabled: true,
            title: '<strong>Not HTML</strong>',
            suggestions: [{ label: 'Start', prompt: 'Explain the product' }],
        }));

        expect(container.querySelector('strong')).toBeNull();
        expect(container.textContent).toContain('<strong>Not HTML</strong>');
        (container.querySelector('[data-testid="askmydocs-widget-intro-suggestion"]') as HTMLButtonElement).click();
        expect(onSuggestion).toHaveBeenCalledWith('Explain the product');
    });

    it('removes itself after the first message when configured', () => {
        vi.useFakeTimers();
        const container = document.createElement('div');
        const card = new IntroCard(container, vi.fn());
        card.show(sanitizeIntro({ enabled: true, title: 'Welcome' }));
        card.beforeFirstMessage();
        vi.advanceTimersByTime(221);
        expect(container.querySelector('[data-testid="askmydocs-widget-intro"]')).toBeNull();
        vi.useRealTimers();
    });
});
