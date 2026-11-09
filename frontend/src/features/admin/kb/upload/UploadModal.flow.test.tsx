import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AxiosHeaders } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { kbUploadApi, type StageInput, type UploadBatchResponse } from './kb-upload.api';
import { UploadModal } from './UploadModal';

const files = [new File(['pdf'], 'manual.pdf', { type: 'application/pdf' })];
function batch(status: 'staged' | 'queued' | 'succeeded' | 'failed' = 'staged'): UploadBatchResponse {
    return {
        batch: {
            id: 'upload-1', status: status === 'staged' ? 'staged' : status === 'failed' ? 'completed_with_errors' : status === 'succeeded' ? 'completed' : 'processing',
            project_key: 'engineering', sub_path: null,
            counts: { staged: 0, queued: 0, moving: 0, processing: 0, succeeded: 0, failed: 0, [status]: 1 },
            created_at: null, committed_at: null, finished_at: null,
        },
        items: [{ id: 'file-1', original_filename: 'manual.pdf', destination_path: 'manual.pdf', size_bytes: 3,
            mime_type: 'application/pdf', source_type: 'pdf', status, is_canonical: false, canonical_warning: null,
            error: status === 'failed' ? 'PDF could not be parsed.' : null, knowledge_document_id: null }],
    };
}
function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (error: unknown) => void;
    const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}
const clients: QueryClient[] = [];
function setup() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    clients.push(client);
    const onClose = vi.fn();
    const onCommitted = vi.fn();
    const view = render(<QueryClientProvider client={client}><UploadModal seed={{ projectKey: 'engineering', subPath: '', files }} defaultProject={null} projectOptions={['engineering']} onClose={onClose} onCommitted={onCommitted} /></QueryClientProvider>);
    return { ...view, onClose, onCommitted };
}
async function review() {
    fireEvent.click(screen.getByTestId('kb-upload-stage'));
    await screen.findByTestId('kb-upload-staged');
}
beforeEach(() => {
    vi.spyOn(kbUploadApi, 'estimate').mockResolvedValue({ enabled: false, driver: 'none', driver_available: false, driver_error: null,
        metering: 'per_page', currency: 'EUR', rate_per_page: 0, total_pages: 0, total_cost: 0, items: [] });
    vi.spyOn(kbUploadApi, 'stage').mockResolvedValue(batch());
    vi.spyOn(kbUploadApi, 'commit').mockResolvedValue(batch('queued'));
    vi.spyOn(kbUploadApi, 'status').mockResolvedValue(batch('queued'));
});
afterEach(() => { cleanup(); clients.splice(0).forEach((c) => c.clear()); vi.restoreAllMocks(); });

describe('KB upload feedback', () => {
    it('keeps filenames visible, reports real bytes, and waits for server confirmation at 100%', async () => {
        const pending = deferred<UploadBatchResponse>();
        vi.mocked(kbUploadApi.stage).mockReturnValue(pending.promise);
        setup();
        fireEvent.click(screen.getByTestId('kb-upload-stage'));
        await waitFor(() => expect(kbUploadApi.stage).toHaveBeenCalledOnce());
        const input = vi.mocked(kbUploadApi.stage).mock.calls[0]![0];
        expect(screen.getByTestId('kb-upload-picked')).toHaveTextContent('manual.pdf');
        expect(screen.getByTestId('kb-upload-close')).toBeDisabled();
        act(() => input.onUploadProgress?.({ loaded: 512, total: 1024, bytes: 512, lengthComputable: true }));
        expect(screen.getByRole('progressbar', { name: 'File transfer progress' })).toHaveAttribute('value', '50');
        expect(screen.getByTestId('kb-upload-transfer')).toHaveTextContent('512 B of 1.0 KB');
        act(() => input.onUploadProgress?.({ loaded: 1024, total: 1024, bytes: 512, lengthComputable: true }));
        expect(screen.getByTestId('kb-upload-transfer')).toHaveAttribute('data-state', 'waiting');
        expect(screen.getByTestId('kb-upload-transfer')).toHaveTextContent('Waiting for the server');
        expect(screen.queryByTestId('kb-upload-commit')).not.toBeInTheDocument();
        await act(async () => pending.resolve(batch()));
        expect(await screen.findByTestId('kb-upload-commit')).toBeEnabled();
    });

    it('stops the request, preserves files for retry, and ignores a late response', async () => {
        const pending = deferred<UploadBatchResponse>();
        vi.mocked(kbUploadApi.stage).mockReturnValueOnce(pending.promise);
        setup();
        fireEvent.click(screen.getByTestId('kb-upload-stage'));
        await waitFor(() => expect(kbUploadApi.stage).toHaveBeenCalledOnce());
        const input: StageInput = vi.mocked(kbUploadApi.stage).mock.calls[0]![0];
        fireEvent.click(screen.getByTestId('kb-upload-stop'));
        expect(input.signal?.aborted).toBe(true);
        expect(screen.getByTestId('kb-upload-picked')).toHaveTextContent('manual.pdf');
        await act(async () => pending.resolve(batch()));
        expect(screen.queryByTestId('kb-upload-commit')).not.toBeInTheDocument();
        await review();
        expect(kbUploadApi.stage).toHaveBeenCalledTimes(2);
    });

    it.each([
        [{ code: 'ECONNABORTED' }, 'did not respond in time'],
        [{ code: 'ERR_NETWORK' }, 'connection was lost'],
        [{ response: { status: 413, data: '<html>too large</html>' } }, 'server upload limit'],
        [{ response: { status: 419 } }, 'session has expired'],
        [{ response: { status: 403, headers: new AxiosHeaders({ 'Cf-Mitigated': 'challenge', 'Cf-Ray': 'example-ray-FCO' }), data: '<html>Browser verification</html>' } }, 'Cloudflare Ray ID: example-ray-FCO'],
        [{ response: { status: 403, headers: { 'cf-mitigated': 'challenge' }, data: '<html>Browser verification</html>' } }, 'Cloudflare blocked this request'],
        [{ response: { status: 403, headers: { server: 'cloudflare', 'cf-ray': 'example-ray' }, data: { message: 'Forbidden by project policy.' } } }, 'Forbidden by project policy.'],
        [{ response: { status: 403, data: '<html>Forbidden</html>' } }, 'Access denied (403)'],
        [{ response: { status: 422, data: { errors: { 'files.0': ['Unsupported file type.'] } } } }, 'manual.pdf: Unsupported file type.'],
    ])('explains request failures and allows retry (%j)', async (error, message) => {
        vi.mocked(kbUploadApi.stage).mockRejectedValueOnce(error);
        setup();
        fireEvent.click(screen.getByTestId('kb-upload-stage'));
        expect(await screen.findByTestId('kb-upload-error')).toHaveTextContent(message);
        expect(screen.getByTestId('kb-upload-modal')).toHaveAttribute('data-state', 'error');
        expect(screen.getByTestId('kb-upload-picked')).toHaveTextContent('manual.pdf');
        expect(screen.getByTestId('kb-upload-stage')).toBeEnabled();
    });

    it('explains the Cloudflare challenge in Italian without rendering its HTML', async () => {
        localStorage.setItem('amd-ui-locale', 'it');
        vi.mocked(kbUploadApi.stage).mockRejectedValueOnce({ response: {
            status: 403, headers: { 'cf-mitigated': 'challenge' },
            data: '<html><script src="https://challenges.cloudflare.com/test.js"></script></html>',
        } });
        const { container } = setup();
        fireEvent.click(screen.getByTestId('kb-upload-stage'));
        const error = await screen.findByTestId('kb-upload-error');
        expect(error).toHaveTextContent('Cloudflare ha bloccato la richiesta');
        expect(error).not.toHaveTextContent('<html>');
        expect(container.querySelector('script')).toBeNull();
        expect(screen.getByTestId('kb-upload-stage')).toBeEnabled();
    });

    it('shows polling errors alongside the last status and recovers without reuploading', async () => {
        vi.mocked(kbUploadApi.status).mockRejectedValueOnce(new Error('Offline')).mockResolvedValue(batch('succeeded'));
        const { onCommitted } = setup();
        await review();
        fireEvent.click(screen.getByTestId('kb-upload-commit'));
        expect(await screen.findByTestId('kb-upload-poll-error')).toHaveTextContent('last known status');
        expect(screen.getByTestId('kb-upload-item-file-1-progress')).toHaveTextContent('Queued for indexing');
        fireEvent.click(screen.getByRole('button', { name: 'Retry status check' }));
        await screen.findByTestId('kb-upload-done-close');
        expect(screen.getByTestId('kb-upload-item-file-1-progress')).toHaveTextContent('Indexed');
        expect(onCommitted).toHaveBeenCalledOnce();
        expect(kbUploadApi.stage).toHaveBeenCalledOnce();
        expect(kbUploadApi.commit).toHaveBeenCalledOnce();
    });

    it('shows terminal per-file errors from the commit response even when polling fails', async () => {
        vi.mocked(kbUploadApi.commit).mockResolvedValue(batch('failed'));
        vi.mocked(kbUploadApi.status).mockRejectedValue(new Error('Offline'));
        const { onCommitted } = setup();
        await review();
        fireEvent.click(screen.getByTestId('kb-upload-commit'));
        expect(await screen.findByTestId('kb-upload-item-file-1-error')).toHaveTextContent('PDF could not be parsed.');
        expect(screen.getByTestId('kb-upload-modal')).toHaveAttribute('data-state', 'error');
        expect(onCommitted).toHaveBeenCalledOnce();
    });

    it('keeps a failed cancellation visible instead of closing silently', async () => {
        vi.spyOn(kbUploadApi, 'cancel').mockRejectedValue(new Error('Cannot cancel batch.'));
        const { onClose } = setup();
        await review();
        fireEvent.click(screen.getByTestId('kb-upload-cancel'));
        expect(await screen.findByTestId('kb-upload-error')).toHaveTextContent('Cannot cancel batch.');
        expect(onClose).not.toHaveBeenCalled();
    });

    it('aborts an active transfer when the modal is unmounted', async () => {
        vi.mocked(kbUploadApi.stage).mockReturnValue(new Promise(() => {}));
        const { unmount } = setup();
        fireEvent.click(screen.getByTestId('kb-upload-stage'));
        await waitFor(() => expect(kbUploadApi.stage).toHaveBeenCalled());
        const signal = vi.mocked(kbUploadApi.stage).mock.calls[0]![0].signal;
        unmount();
        expect(signal?.aborted).toBe(true);
    });
});
