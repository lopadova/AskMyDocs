import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * v8.8.3 — `anonymousChatApi.config()` schema validation (R14/R16).
 *
 * A malformed 200 (missing / non-boolean `enabled`) must THROW so React Query
 * surfaces it as an error and the view renders its error landing — it must NOT
 * be silently coerced to the deliberate OFF state.
 */

const get = vi.fn();
vi.mock('../../lib/api', () => ({ api: { get: (...args: unknown[]) => get(...args) } }));

import { anonymousChatApi } from './chat.api';

describe('anonymousChatApi.config', () => {
    beforeEach(() => get.mockReset());

    it('returns the boolean enabled flag on a well-formed response', async () => {
        get.mockResolvedValueOnce({ data: { enabled: true } });
        await expect(anonymousChatApi.config()).resolves.toEqual({ enabled: true });

        get.mockResolvedValueOnce({ data: { enabled: false } });
        await expect(anonymousChatApi.config()).resolves.toEqual({ enabled: false });
    });

    it('throws on a malformed 200 with a missing `enabled` (R14)', async () => {
        get.mockResolvedValueOnce({ data: {} });
        await expect(anonymousChatApi.config()).rejects.toThrow(/not a boolean/);
    });

    it('throws on a malformed 200 with a non-boolean `enabled` (R14)', async () => {
        get.mockResolvedValueOnce({ data: { enabled: 'yes' } });
        await expect(anonymousChatApi.config()).rejects.toThrow(/not a boolean/);
    });
});
