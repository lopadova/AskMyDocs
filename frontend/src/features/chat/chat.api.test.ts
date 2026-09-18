import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * chatApi.startAgentTurn() — the `depth` payload key must be OMITTED for
 * the server default (3, or unset) so a client that never touches the
 * investigation-depth control sends a request byte-identical to before
 * that knob existed, and INCLUDED for any other explicit value.
 */

const post = vi.fn();
vi.mock('../../lib/api', () => ({ api: { post: (...args: unknown[]) => post(...args) } }));

import { chatApi } from './chat.api';

describe('chatApi.startAgentTurn', () => {
    beforeEach(() => post.mockReset());

    it('omits `depth` when the caller never set one', async () => {
        post.mockResolvedValueOnce({ data: {} });
        await chatApi.startAgentTurn(7, 'Domanda');

        const [, payload] = post.mock.calls[0] as [string, Record<string, unknown>];
        expect(payload).not.toHaveProperty('depth');
    });

    it('omits `depth` when it equals the server default (3)', async () => {
        post.mockResolvedValueOnce({ data: {} });
        await chatApi.startAgentTurn(7, 'Domanda', undefined, undefined, undefined, undefined, 3);

        const [, payload] = post.mock.calls[0] as [string, Record<string, unknown>];
        expect(payload).not.toHaveProperty('depth');
    });

    it.each([1, 2, 4, 5])('includes `depth` = %i when it differs from the default', async (depth) => {
        post.mockResolvedValueOnce({ data: {} });
        await chatApi.startAgentTurn(7, 'Domanda', undefined, undefined, undefined, undefined, depth);

        const [, payload] = post.mock.calls[0] as [string, Record<string, unknown>];
        expect(payload).toMatchObject({ depth });
    });
});
