import { describe, expect, it } from 'vitest';
import { normalizeTemplateColumns } from './admin-tabular-reviews.api';

describe('normalizeTemplateColumns', () => {
    it('returns [] for a non-array input (malformed seed)', () => {
        expect(normalizeTemplateColumns(null)).toEqual([]);
        expect(normalizeTemplateColumns(undefined)).toEqual([]);
        expect(normalizeTemplateColumns('nope')).toEqual([]);
        expect(normalizeTemplateColumns({})).toEqual([]);
    });

    it('drops rows without a usable name', () => {
        const cols = normalizeTemplateColumns([
            { prompt: 'no name here' },
            { name: '   ' },
            null,
            42,
            { name: 'Status', prompt: 'What is the status?' },
        ]);
        expect(cols).toHaveLength(1);
        expect(cols[0]?.name).toBe('Status');
    });

    it('falls back to a safe format when the seed format is unknown', () => {
        const [col] = normalizeTemplateColumns([{ name: 'X', format: 'bogus_format' }]);
        expect(col?.format).toBe('text');
    });

    it('keeps a known format verbatim', () => {
        const [col] = normalizeTemplateColumns([{ name: 'X', format: 'yes_no' }]);
        expect(col?.format).toBe('yes_no');
    });

    it('only keeps a metric for a graph agent with a known governance metric', () => {
        const [graphOk] = normalizeTemplateColumns([{ name: 'G', agent: 'graph', metric: 'evidence_tier' }]);
        expect(graphOk?.agent).toBe('graph');
        expect(graphOk?.metric).toBe('evidence_tier');

        const [graphBadMetric] = normalizeTemplateColumns([{ name: 'G', agent: 'graph', metric: 'made_up' }]);
        expect(graphBadMetric?.metric).toBeNull();

        const [extractWithMetric] = normalizeTemplateColumns([{ name: 'E', agent: 'extract', metric: 'evidence_tier' }]);
        expect(extractWithMetric?.agent).toBe('extract');
        expect(extractWithMetric?.metric).toBeNull();
    });

    it('drops an unknown agent down to undefined (defaults to extract in the editor)', () => {
        const [col] = normalizeTemplateColumns([{ name: 'X', agent: 'hallucinate' }]);
        expect(col?.agent).toBeUndefined();
    });
});
