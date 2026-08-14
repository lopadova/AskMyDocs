import { api } from '../../../lib/api';

/*
 * v8.21 (Ciclo 2) — Ingestion & Sync observability HTTP client. Mirrors
 * `IngestionController` (see `routes/api.php` +
 * `app/Http/Controllers/Api/Admin/IngestionController.php`) and the
 * `IngestionObservabilityService` payload shapes (R9 — names match the BE).
 */

export interface QueueDepth {
    name: string;
    // (string & {}) keeps the known-literal autocomplete while still accepting
    // any future role — a bare `| string` would collapse the whole union.
    role: 'connector-sync' | 'kb-ingest' | 'default' | (string & {});
    /** null when the queue driver has no usable size() (e.g. sync). */
    depth: number | null;
}

export type SyncRunStatus = 'running' | 'success' | 'partial' | 'failed' | (string & {});

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

export type ImapBackfillStatus = 'discovering' | 'running' | 'completed' | 'failed' | 'paused' | (string & {});

export interface ImapBackfillDto {
    id: number;
    installation_id: number;
    status: ImapBackfillStatus;
    total_messages: number;
    processed_messages: number;
    dispatched_documents: number;
    total_windows: number;
    completed_windows: number;
    progress_percent: number;
    batch_size: number;
    started_at: string | null;
    completed_at: string | null;
    heartbeat_at: string | null;
    current_window: {
        mailbox: string;
        start: string;
        end: string;
        processed_messages: number;
        expected_messages: number;
        last_uid: number;
    } | null;
    last_error: Record<string, unknown> | null;
}

export interface ImapBackfillStateDto {
    enabled: boolean;
    backfill: ImapBackfillDto | null;
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

    async imapBackfill(installationId: number): Promise<ImapBackfillStateDto> {
        const { data } = await api.get<{ data: ImapBackfillStateDto }>(
            `/api/admin/connectors/${installationId}/imap-backfill`,
        );
        return data.data;
    },

    async startImapBackfill(installationId: number): Promise<ImapBackfillStateDto> {
        const { data } = await api.post<{ data: ImapBackfillStateDto }>(
            `/api/admin/connectors/${installationId}/imap-backfill`,
        );
        return data.data;
    },
};
