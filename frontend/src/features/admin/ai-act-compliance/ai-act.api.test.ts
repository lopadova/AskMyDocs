import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { api } from '../../../lib/api';
import { AI_ACT_DOMAINS, getAiActDomain, getAiActOverview } from './ai-act.api';

const mockGet = vi.fn();

beforeEach(() => {
    mockGet.mockReset();
    vi.spyOn(api, 'get').mockImplementation(mockGet);
});
afterEach(() => vi.restoreAllMocks());

const incidents = AI_ACT_DOMAINS.find((d) => d.key === 'incidents')!;

describe('ai-act.api', () => {
    it('counts records and tallies status/state from the {data:[…]} envelope', async () => {
        mockGet.mockResolvedValue({
            data: {
                data: [
                    { id: 1, status: 'open' },
                    { id: 2, status: 'open' },
                    { id: 3, state: 'closed' }, // falls back to `state` when no `status`
                    { id: 4 }, // no status/state — counted but not tallied
                ],
            },
        });

        const result = await getAiActDomain(incidents);

        expect(mockGet).toHaveBeenCalledWith('/api/admin/ai-act-compliance/incidents');
        expect(result.count).toBe(4);
        expect(result.statuses).toEqual({ open: 2, closed: 1 });
    });

    it('treats a missing/non-array data envelope as zero, not a crash', async () => {
        mockGet.mockResolvedValue({ data: {} });
        const result = await getAiActDomain(incidents);
        expect(result.count).toBe(0);
        expect(result.statuses).toEqual({});
    });

    it('overview fetches every declared compliance register exactly once', async () => {
        mockGet.mockResolvedValue({ data: { data: [] } });

        const results = await getAiActOverview();

        expect(results).toHaveLength(AI_ACT_DOMAINS.length);
        for (const d of AI_ACT_DOMAINS) {
            expect(mockGet).toHaveBeenCalledWith(`/api/admin/ai-act-compliance/${d.path}`);
        }
        expect(results.map((r) => r.key).sort()).toEqual(AI_ACT_DOMAINS.map((d) => d.key).sort());
    });
});
