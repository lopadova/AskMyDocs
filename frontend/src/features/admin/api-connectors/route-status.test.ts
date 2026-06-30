import { describe, expect, it } from 'vitest';
import { routeStatusBadge } from './route-status';

describe('routeStatusBadge', () => {
    it('labels each route status distinctly', () => {
        expect(routeStatusBadge('draft').label).toBe('Draft');
        expect(routeStatusBadge('tested').label).toBe('Tested');
        expect(routeStatusBadge('active').label).toBe('Active');
        expect(routeStatusBadge('disabled').label).toBe('Disabled');
    });

    it('gives active a green-tinted colour distinct from draft', () => {
        const active = routeStatusBadge('active');
        const draft = routeStatusBadge('draft');
        expect(active.color).not.toBe(draft.color);
        expect(active.color).toBe('#34d399');
        expect(draft.color).toBe('#fbbf24');
    });

    it('falls back to the draft style for an unknown status', () => {
        // @ts-expect-error — exercising the defensive default branch.
        expect(routeStatusBadge('mystery').label).toBe('Draft');
    });
});
