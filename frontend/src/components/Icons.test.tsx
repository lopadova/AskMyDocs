import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import { Icon } from './Icons';

describe('Icons', () => {
    it('renders Logo as an SVG with the expected viewBox', () => {
        const { container } = render(<Icon.Logo />);
        const svg = container.querySelector('svg');
        expect(svg).not.toBeNull();
        expect(svg?.getAttribute('viewBox')).toBe('0 0 24 24');
    });

    it('honours the `size` prop on arbitrary icons', () => {
        const { container } = render(<Icon.Chat size={32} />);
        const svg = container.querySelector('svg');
        expect(svg?.getAttribute('width')).toBe('32');
        expect(svg?.getAttribute('height')).toBe('32');
    });

    it('exposes at least 45 named icons matching the design bundle', () => {
        const keys = Object.keys(Icon);
        expect(keys.length).toBeGreaterThanOrEqual(45);
        expect(keys).toContain('Sparkles');
        expect(keys).toContain('Command');
    });
});
