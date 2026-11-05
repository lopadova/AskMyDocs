import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '@/lib/api';
import { appearanceApi } from './appearance.api';

afterEach(() => vi.restoreAllMocks());

describe('global appearance API', () => {
    it('loads the installation setting without a tenant or project scope', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: { data: { theme: 'humanistic' } } } as never);
        await expect(appearanceApi.load()).resolves.toEqual({ theme: 'humanistic' });
        expect(api.get).toHaveBeenCalledExactlyOnceWith('/api/system/appearance');
    });

    it('saves only the explicit theme selection and returns the server value', async () => {
        vi.spyOn(api, 'put').mockResolvedValue({ data: { data: { theme: 'luxury' } } } as never);
        await expect(appearanceApi.save('luxury')).resolves.toEqual({ theme: 'luxury' });
        expect(api.put).toHaveBeenCalledExactlyOnceWith('/api/system/appearance', { theme: 'luxury' });
    });
});
