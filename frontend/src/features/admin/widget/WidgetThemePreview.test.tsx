import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';

import { DEFAULT_THEME, sanitizeTheme } from '../../../widget/ui/styles';
import { WidgetThemePreview } from './WidgetThemePreview';

/** Read the injected <style> text out of the preview's shadow root. */
function shadowStyle(container: HTMLElement): string {
    const host = container.querySelector('[data-testid="admin-widget-appearance-preview"]')
        ?.firstElementChild as HTMLElement | null;
    return host?.shadowRoot?.querySelector('style')?.textContent ?? '';
}

describe('WidgetThemePreview', () => {
    it('reflects the theme colour in the shadow-root CSS', () => {
        const { container } = render(
            <WidgetThemePreview theme={sanitizeTheme({ ...DEFAULT_THEME, accent: '#123456' })} />,
        );
        expect(shadowStyle(container)).toContain('--amd-accent:#123456;');
    });

    it('updates the preview when the theme changes (R16)', () => {
        const { container, rerender } = render(
            <WidgetThemePreview theme={sanitizeTheme({ ...DEFAULT_THEME, accent: '#123456' })} />,
        );
        expect(shadowStyle(container)).toContain('--amd-accent:#123456;');

        rerender(<WidgetThemePreview theme={sanitizeTheme({ ...DEFAULT_THEME, accent: '#abcdef' })} />);
        const css = shadowStyle(container);
        expect(css).toContain('--amd-accent:#abcdef;');
        expect(css).not.toContain('--amd-accent:#123456;');
    });

    it('renders the inline block (no launcher) when mode is inline', () => {
        const { container } = render(
            <WidgetThemePreview theme={sanitizeTheme({ ...DEFAULT_THEME, mode: 'inline' })} />,
        );
        const host = container.querySelector('[data-testid="admin-widget-appearance-preview"]')
            ?.firstElementChild as HTMLElement;
        // Inline: the root carries the inline class, the panel is present, the
        // floating launcher is not rendered at all.
        expect(host.shadowRoot?.querySelector('.amd-root.amd-mode-inline')).not.toBeNull();
        expect(host.shadowRoot?.querySelector('.amd-panel')).not.toBeNull();
        expect(host.shadowRoot?.querySelector('.amd-launcher')).toBeNull();
    });

    it('renders the floating launcher in helper mode', () => {
        const { container } = render(
            <WidgetThemePreview theme={sanitizeTheme({ ...DEFAULT_THEME, mode: 'helper' })} />,
        );
        const host = container.querySelector('[data-testid="admin-widget-appearance-preview"]')
            ?.firstElementChild as HTMLElement;
        expect(host.shadowRoot?.querySelector('.amd-launcher')).not.toBeNull();
        expect(host.shadowRoot?.querySelector('.amd-mode-inline')).toBeNull();
    });

    it('renders the panel chrome and never leaks an injection payload', () => {
        const { container } = render(
            <WidgetThemePreview
                theme={sanitizeTheme({ ...DEFAULT_THEME, accent: '#fff; } body{display:none} .x{' })}
            />,
        );
        const host = container.querySelector('[data-testid="admin-widget-appearance-preview"]')
            ?.firstElementChild as HTMLElement;
        expect(host.shadowRoot?.querySelector('.amd-panel')).not.toBeNull();
        expect(shadowStyle(container)).not.toContain('display:none');
    });
});
