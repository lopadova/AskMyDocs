import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../../lib/api';
import { apiConnectorsApi } from './api-connectors.api';

afterEach(() => vi.restoreAllMocks());

describe('API connector transfer transport', () => {
    it('sends ZIPs as multipart files and JSON configurations in their existing envelope', async () => {
        const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: { name: 'Orders' } } });
        const file = new File(['zip'], 'connector.zip', { type: 'application/zip' });
        await apiConnectorsApi.previewImport(file);
        const payload = post.mock.calls[0][1] as FormData;
        expect(payload).toBeInstanceOf(FormData);
        expect(payload.get('file')).toBe(file);
        await apiConnectorsApi.previewImport({ connector: { name: 'Orders' } });
        expect(post).toHaveBeenLastCalledWith('/api/admin/api-connectors/import/validate', { config: { connector: { name: 'Orders' } } });
    });

    it('requests ZIP bytes explicitly while retaining the legacy JSON export', async () => {
        const bytes = new Blob(['zip']);
        const get = vi.spyOn(api, 'get').mockResolvedValue({ data: bytes });
        expect(await apiConnectorsApi.exportPackage(12)).toBe(bytes);
        expect(get).toHaveBeenCalledWith('/api/admin/api-connectors/12/export', { params: { format: 'zip' }, responseType: 'blob' });
        get.mockResolvedValueOnce({ data: { data: { _meta: { version: 1 } } } });
        expect(await apiConnectorsApi.exportConfig(12)).toEqual({ _meta: { version: 1 } });
    });

    it('decodes server validation errors returned as blobs for ZIP downloads', async () => {
        const body = new Blob(['error']);
        Object.defineProperty(body, 'text', { value: async () => JSON.stringify({ message: 'Credential material found.' }) });
        const failure = { response: { status: 422, data: body } };
        vi.spyOn(api, 'get').mockRejectedValue(failure);
        await expect(apiConnectorsApi.exportPackage(12)).rejects.toBe(failure);
        expect(failure.response.data).toEqual({ message: 'Credential material found.' });
    });
});
