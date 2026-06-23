import { api } from '../../../lib/api';

/*
 * v8.21 (Ciclo 2) — Ingestion & Sync observability HTTP client. Mirrors
 * `IngestionController` (see `routes/api.php` +
 * `app/Http/Controllers/Api/Admin/IngestionController.php`) and the
 * `IngestionObservabilityService` payload shapes (R9 — names match the BE).
 */

export interface QueueDepth {
    name: string;
    role: 'connector-sync' | 'kb-ingest' | 'default' | string;
    /** null when the queue driver has no usable size() (e.g. sync). */
    depth: number | null;
}

export type SyncRunStatus = 'running' | 'success' | 'partial' | 'failed';

export interface SyncRunDto {
    id: number;
    connector_name: string;
    label: string;
    queue: string | null;
    status: SyncRunStatus;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
    items_discovered: number;
    items_failed: number;
    error: Record<string, unknown> | null;
}

export const adminIngestionApi = {
    async queueDepths(): Promise<QueueDepth[]> {
        const { data } = await api.get<{ data: QueueDepth[] }>('/api/admin/ingestion/queue');
        return data.data;
    },

    async syncRuns(installationId: number, limit = 20): Promise<SyncRunDto[]> {
        const { data } = await api.get<{ data: SyncRunDto[] }>(
            `/api/admin/connectors/${installationId}/sync-runs`,
            { params: { limit } },
        );
        return data.data;
    },
};
