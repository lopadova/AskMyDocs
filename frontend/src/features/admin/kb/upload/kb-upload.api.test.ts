import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../../../../lib/api';
import { kbUploadApi } from './kb-upload.api';

afterEach(() => vi.restoreAllMocks());

describe('KB upload transport', () => {
    it('wires multipart progress, cancellation and a finite timeout into the shared client', async () => {
        const post = vi.spyOn(api, 'post').mockResolvedValue({ data: {} });
        const controller = new AbortController();
        const onUploadProgress = vi.fn();
        const file = new File(['pdf'], 'manual.pdf', { type: 'application/pdf' });
        await kbUploadApi.stage({ projectKey: 'engineering', subPath: 'runbooks', files: [file], signal: controller.signal, onUploadProgress });
        const [url, form, options] = post.mock.calls[0]!;
        expect(url).toBe('/api/admin/kb/uploads');
        expect((form as FormData).get('project_key')).toBe('engineering');
        expect((form as FormData).get('sub_path')).toBe('runbooks');
        expect((form as FormData).getAll('files[]')).toEqual([file]);
        expect(options).toEqual({ signal: controller.signal, onUploadProgress, timeout: 300_000 });
    });

    it('bounds each polling request so a lost connection can be retried', async () => {
        const get = vi.spyOn(api, 'get').mockResolvedValue({ data: {} });
        await kbUploadApi.status('upload-1');
        expect(get).toHaveBeenCalledWith('/api/admin/kb/uploads/upload-1/status', { timeout: 30_000 });
    });
});
