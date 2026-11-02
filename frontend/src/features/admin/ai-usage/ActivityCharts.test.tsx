import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ActivityChart from './ActivityCharts';

vi.mock('../../../lib/ui-locale', () => ({ useUiTranslation: () => ({ t: (s: string) => s, locale: 'en' }) }));

describe('explorable activity charts', () => {
    it('keeps unknown costs distinct from zero in its keyboard-readable data', () => {
        render(<ActivityChart title="Cost by model" currency="USD" points={[{ date: '2026-11-01', end: '2026-11-01', spend: null }, { date: '2026-11-02', end: '2026-11-02', spend: 0 }]}
            series={[{ key: 'spend', label: 'Model Alpha', color: '#3485ff' }]} />);
        expect(screen.getByText('Not available')).toBeInTheDocument();
        expect(screen.getByText('$0.00')).toBeInTheDocument();
        expect(screen.getByText('Show chart data').tagName).toBe('SUMMARY');
    });
    it('replaces the old calendar and units after a range or metric change', () => {
        const { rerender } = render(<ActivityChart title="Cost by model" currency="USD" points={[{ date: '2026-10-01', end: '2026-10-30', v: .00000001 }]}
            series={[{ key: 'v', label: 'A very long model identity', color: '#3485ff' }]} />);
        expect(screen.getByText('$0.00000001')).toBeInTheDocument();
        rerender(<ActivityChart key="new-dataset" title="Token composition" unit="Tokens" points={[{ date: '2026-11-01', end: '2026-11-01', v: 18 }]}
            series={[{ key: 'v', label: 'Input tokens', color: '#3485ff' }]} />);
        expect(screen.queryByText(/2026-10-01/)).not.toBeInTheDocument();
        expect(screen.getByText('Tokens')).toBeInTheDocument();
        expect(screen.getByText('18')).toBeInTheDocument();
    });
});
