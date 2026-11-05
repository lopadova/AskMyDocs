import { describe, expect, it } from 'vitest';
import { amount, seriesColor } from './usage-format';

describe('activity formatting', () => {
    it('retains meaningful tiny costs and negative adjustments', () => {
        expect(amount(.00000001, 'en', 'USD')).toBe('$0.00000001');
        expect(amount(-.0023, 'en', 'USD')).toBe('-$0.0023');
    });
    it('keeps semantic colors stable and distinguishes other models', () => {
        const original = seriesColor('provider:long-model');
        expect(seriesColor('other')).toBe('#94a3b8');
        expect(new Set(Array.from({ length: 10 }, (_, i) => seriesColor(`model-${i}`))).size).toBeGreaterThan(6);
        expect(seriesColor('provider:long-model')).toBe(original);
    });
});
