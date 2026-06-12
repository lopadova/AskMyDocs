import { api } from '../../../../lib/api';

/**
 * v8.9 — admin drag-and-drop KB upload client.
 *
 * stage → review → commit → poll. All calls hit the internal admin
 * namespace `/api/admin/kb/uploads/*` (never stubbed in E2E per R13;
 * X-Tenant-Id is injected by the shared axios client — team-scope-wiring).
 *
 * Every batch-shaped endpoint (stage/show/status/commit/cancel) returns the
 * SAME `{ batch, items }` envelope so the modal polls one contract (R27).
 */

export type BatchItemStatus =
    | 'staged'
    | 'moving'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type BatchStatus =
    | 'staged'
    | 'committing'
    | 'processing'
    | 'completed'
    | 'completed_with_errors'
    | 'cancelled'
    | 'expired';

export interface UploadBatchItem {
    id: string;
    original_filename: string;
    destination_path: string;
    size_bytes: number;
    mime_type: string;
    source_type: string;
    status: BatchItemStatus;
    is_canonical: boolean;
    canonical_warning: string | null;
    error: string | null;
    knowledge_document_id: number | null;
}

export interface UploadBatch {
    id: string;
    status: BatchStatus;
    project_key: string;
    sub_path: string | null;
    counts: Record<BatchItemStatus, number>;
    committed_at: string | null;
    finished_at: string | null;
    created_at: string | null;
}

export interface UploadBatchResponse {
    batch: UploadBatch;
    items: UploadBatchItem[];
}

export interface StageInput {
    projectKey: string;
    subPath: string;
    files: File[];
}

export const kbUploadApi = {
    async stage(input: StageInput): Promise<UploadBatchResponse> {
        const fd = new FormData();
        fd.append('project_key', input.projectKey);
        if (input.subPath !== '') {
            fd.append('sub_path', input.subPath);
        }
        input.files.forEach((file) => fd.append('files[]', file, file.name));

        // NB: do NOT set Content-Type — the browser adds the multipart boundary.
        const { data } = await api.post<UploadBatchResponse>('/api/admin/kb/uploads', fd);
        return data;
    },

    async get(batchId: string): Promise<UploadBatchResponse> {
        const { data } = await api.get<UploadBatchResponse>(`/api/admin/kb/uploads/${batchId}`);
        return data;
    },

    async status(batchId: string): Promise<UploadBatchResponse> {
        const { data } = await api.get<UploadBatchResponse>(`/api/admin/kb/uploads/${batchId}/status`);
        return data;
    },

    async commit(batchId: string, expectedItemIds?: string[]): Promise<UploadBatchResponse> {
        const { data } = await api.post<UploadBatchResponse>(
            `/api/admin/kb/uploads/${batchId}/commit`,
            expectedItemIds ? { expected_item_ids: expectedItemIds } : {},
        );
        return data;
    },

    async cancel(batchId: string): Promise<UploadBatchResponse> {
        const { data } = await api.post<UploadBatchResponse>(`/api/admin/kb/uploads/${batchId}/cancel`, {});
        return data;
    },

    async removeItem(batchId: string, itemId: string): Promise<void> {
        await api.delete(`/api/admin/kb/uploads/${batchId}/items/${itemId}`);
    },
};

/** Terminal batch statuses — polling stops here. */
export const TERMINAL_BATCH_STATUSES: BatchStatus[] = [
    'completed',
    'completed_with_errors',
    'cancelled',
    'expired',
];

export function isTerminalBatch(batch: UploadBatch | undefined): boolean {
    if (!batch) {
        return false;
    }
    return TERMINAL_BATCH_STATUSES.includes(batch.status);
}
