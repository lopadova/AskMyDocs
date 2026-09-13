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

    /**
     * v8.36 / ADR 0029 §8 — OCR cost estimate for a staged batch, read
     * BEFORE commit. Side-effect free on the server (no driver runs); with
     * `KB_OCR_ENABLED=false` the server answers `enabled=false` and zeros
     * (R43 — the OFF path is an honest answer, not a missing one).
     */
    async estimate(batchId: string): Promise<OcrEstimate> {
        const { data } = await api.get<{ data: OcrEstimate }>(`/api/admin/kb/uploads/${batchId}/estimate`);
        return data.data;
    },
};

/** Why an item would (or would not) be OCR'd — mirrors OcrCostEstimator. */
export type OcrEstimateReason =
    | 'ocr_disabled'
    | 'image'
    | 'scanned_pdf'
    /** text pages and scanned pages in one PDF: the whole document is OCR'd so no page is lost */
    | 'mixed_pdf'
    | 'text_layer_present'
    | 'not_ocr_able'
    | 'staged_file_missing'
    | 'too_many_pages'
    | 'too_many_bytes'
    /** the PDF could not be parsed and the configured driver is remote: refused before egress */
    | 'pages_uncountable'
    /** the bytes do not carry the signature of the declared type: refused before any driver runs */
    | 'unrecognised_bytes';

export interface OcrEstimateItem {
    id: string;
    would_ocr: boolean;
    pages: number;
    /** false when `pages` is a floor (the PDF could not be parsed) */
    pages_exact: boolean;
    cost: number;
    reason: OcrEstimateReason;
}

export interface OcrEstimate {
    enabled: boolean;
    driver: string;
    /** false when the configured driver cannot run here (binary missing, remote driver with KB_OCR_ALLOW_REMOTE off) */
    driver_available: boolean;
    driver_error: string | null;
    /** per_page: total_cost = pages × rate_per_page; sdk: no page rate — the provider meters tokens, FinOps records the real spend */
    metering: 'per_page' | 'sdk';
    currency: string;
    rate_per_page: number;
    total_pages: number;
    total_cost: number;
    items: OcrEstimateItem[];
}

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
