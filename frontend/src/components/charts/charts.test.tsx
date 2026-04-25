import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import { Sparkline, AreaChart, BarStack, Donut } from './index';

describe('charts', () => {
    it('renders Sparkline with a line path', () => {
        const { container } = render(<Sparkline data={[1, 2, 3, 4, 5]} animate={false} />);
        expect(container.querySelector('svg')).not.toBeNull();
        expect(container.querySelectorAll('path').length).toBeGreaterThan(0);
    });

    it('renders AreaChart with y-axis gridlines', () => {
        const { container } = render(<AreaChart data={[1, 5, 3, 8, 2]} labels={['a', 'b', 'c', 'd', 'e']} />);
        const lines = container.querySelectorAll('line');
        expect(lines.length).toBeGreaterThanOrEqual(5);
    });

    it('renders BarStack with one <g> per datum', () => {
        const data = [
            { a: 1, b: 2, c: 3 },
            { a: 2, b: 1, c: 4 },
        ];
        const { container } = render(<BarStack data={data} labels={['x', 'y']} />);
        expect(container.querySelectorAll('g').length).toBe(2);
    });

    it('renders Donut with one background ring + N segments', () => {
        const { container } = render(
            <Donut segments={[{ v: 1, color: '#8b5cf6' }, { v: 2, color: '#22d3ee' }]} />
        );
        expect(container.querySelectorAll('circle').length).toBe(3);
    });
});
