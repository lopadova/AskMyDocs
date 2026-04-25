import { useQuery } from '@tanstack/react-query';
import { api } from '../../../lib/api';

/*
 * Phase H1 — admin Log Viewer HTTP + TanStack Query layer.
 *
 * Mirrors the controller at app/Http/Controllers/Api/Admin/LogViewerController.
 * Every filter object maps 1:1 to a query string. All endpoints are
 * GET + paginated (or capped, in the case of the application log tail).
 *
 * Source of truth: routes/api.php → group `/api/admin/logs/*`.
 * Keep this file in lockstep with the backend (R9 — docs match code).
 */

// ---------------------------------------------------------------------------
// Shared pagination envelope — identical to AdminPaginated<T>
// ---------------------------------------------------------------------------

export interface LogsPaginatedMeta {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface LogsPaginated<T> {
    data: T[];
    meta: LogsPaginatedMeta;
    links?: Record<string, string | null>;
    /** Returned by activity / failed-jobs when the table is absent. */
    note?: string;
}

// ---------------------------------------------------------------------------
// Chat logs
// ---------------------------------------------------------------------------

export interface ChatLogRow {
    id: number;
    session_id: string;
    user_id: number | null;
    question: string;
    answer: string;
    project_key: string | null;
    ai_provider: string;
    ai_model: string;
    chunks_count: number;
    sources: Array<Record<string, unknown>> | null;
    prompt_tokens: number | null;
    completion_tokens: number | null;
    total_tokens: number | null;
    latency_ms: number;
    client_ip: string | null;
    user_agent: string | null;
    extra: Record<string, unknown> | null;
    created_at: string | null;
}

export interface ChatLogsQuery {
    project?: string;
    model?: string;
    min_latency_ms?: number;
    min_tokens?: number;
    from?: string;
    to?: string;
    page?: number;
}

function buildChatParams(q: ChatLogsQuery): Record<string, string> {
    const p: Record<string, string> = {};
    if (q.project && q.project.trim() !== '') p.project = q.project.trim();
    if (q.model && q.model.trim() !== '') p.model = q.model.trim();
    if (typeof q.min_latency_ms === 'number' && !Number.isNaN(q.min_latency_ms)) {
        p.min_latency_ms = String(q.min_latency_ms);
    }
    if (typeof q.min_tokens === 'number' && !Number.isNaN(q.min_tokens)) {
        p.min_tokens = String(q.min_tokens);
    }
    if (q.from && q.from.trim() !== '') p.from = q.from.trim();
    if (q.to && q.to.trim() !== '') p.to = q.to.trim();
    if (typeof q.page === 'number') p.page = String(q.page);
    return p;
}

export const CHAT_LOGS_KEY = ['admin', 'logs', 'chat'] as const;

export function useChatLogs(q: ChatLogsQuery = {}) {
    return useQuery<LogsPaginated<ChatLogRow>>({
        queryKey: [...CHAT_LOGS_KEY, q],
        queryFn: async () => {
            const { data } = await api.get<LogsPaginated<ChatLogRow>>('/api/admin/logs/chat', {
                params: buildChatParams(q),
            });
            return data;
        },
        staleTime: 15_000,
        retry: false,
    });
}

export function useChatLog(id: number | null) {
    return useQuery<{ data: ChatLogRow }>({
        queryKey: ['admin', 'logs', 'chat', 'show', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: ChatLogRow }>(`/api/admin/logs/chat/${id}`);
            return data;
        },
        enabled: id !== null,
        staleTime: 30_000,
        retry: false,
    });
}

// ---------------------------------------------------------------------------
// Canonical audit
// ---------------------------------------------------------------------------

export interface AuditLogRow {
    id: number;
    project_key: string;
    doc_id: string | null;
    slug: string | null;
    event_type: string;
    actor: string;
    before_json: Record<string, unknown> | null;
    after_json: Record<string, unknown> | null;
    metadata_json: Record<string, unknown> | null;
    created_at: string | null;
}

export interface AuditLogsQuery {
    project?: string;
    event_type?: string;
    actor?: string;
    from?: string;
    to?: string;
    page?: number;
}

function buildAuditParams(q: AuditLogsQuery): Record<string, string> {
    const p: Record<string, string> = {};
    if (q.project && q.project.trim() !== '') p.project = q.project.trim();
    if (q.event_type && q.event_type.trim() !== '') p.event_type = q.event_type.trim();
    if (q.actor && q.actor.trim() !== '') p.actor = q.actor.trim();
    if (q.from && q.from.trim() !== '') p.from = q.from.trim();
    if (q.to && q.to.trim() !== '') p.to = q.to.trim();
    if (typeof q.page === 'number') p.page = String(q.page);
    return p;
}

export const AUDIT_LOGS_KEY = ['admin', 'logs', 'canonical-audit'] as const;

export function useAuditLogs(q: AuditLogsQuery = {}) {
    return useQuery<LogsPaginated<AuditLogRow>>({
        queryKey: [...AUDIT_LOGS_KEY, q],
        queryFn: async () => {
            const { data } = await api.get<LogsPaginated<AuditLogRow>>(
                '/api/admin/logs/canonical-audit',
                { params: buildAuditParams(q) },
            );
            return data;
        },
        staleTime: 15_000,
        retry: false,
    });
}

// ---------------------------------------------------------------------------
// Application log tail
// ---------------------------------------------------------------------------

export interface ApplicationLogResponse {
    file: string;
    level: string | null;
    requested_tail: number;
    lines: string[];
    truncated: boolean;
    total_scanned: number;
}

export interface ApplicationLogQuery {
    file: string;
    level?: string;
    tail?: number;
}

function buildApplicationParams(q: ApplicationLogQuery): Record<string, string> {
    const p: Record<string, string> = { file: q.file };
    if (q.level && q.level.trim() !== '') p.level = q.level.trim();
    if (typeof q.tail === 'number' && !Number.isNaN(q.tail)) p.tail = String(q.tail);
    return p;
}

export const APPLICATION_LOG_KEY = ['admin', 'logs', 'application'] as const;

export function useApplicationLog(q: ApplicationLogQuery, options: { live?: boolean } = {}) {
    return useQuery<ApplicationLogResponse>({
        queryKey: [...APPLICATION_LOG_KEY, q],
        queryFn: async () => {
            const { data } = await api.get<ApplicationLogResponse>(
                '/api/admin/logs/application',
                { params: buildApplicationParams(q) },
            );
            return data;
        },
        staleTime: options.live ? 0 : 15_000,
        refetchInterval: options.live ? 5_000 : false,
        retry: false,
    });
}

// ---------------------------------------------------------------------------
// Activity log (Spatie)
// ---------------------------------------------------------------------------

export interface ActivityLogRow {
    id: number;
    log_name: string | null;
    description: string;
    subject_type: string | null;
    subject_id: number | null;
    event: string | null;
    causer_type: string | null;
    causer_id: number | null;
    properties: Record<string, unknown> | null;
    attribute_changes: Record<string, unknown> | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface ActivityLogsQuery {
    subject_type?: string;
    subject_id?: number;
    causer_id?: number;
    page?: number;
}

function buildActivityParams(q: ActivityLogsQuery): Record<string, string> {
    const p: Record<string, string> = {};
    if (q.subject_type && q.subject_type.trim() !== '') p.subject_type = q.subject_type.trim();
    if (typeof q.subject_id === 'number') p.subject_id = String(q.subject_id);
    if (typeof q.causer_id === 'number') p.causer_id = String(q.causer_id);
    if (typeof q.page === 'number') p.page = String(q.page);
    return p;
}

export const ACTIVITY_LOG_KEY = ['admin', 'logs', 'activity'] as const;

export function useActivityLogs(q: ActivityLogsQuery = {}) {
    return useQuery<LogsPaginated<ActivityLogRow>>({
        queryKey: [...ACTIVITY_LOG_KEY, q],
        queryFn: async () => {
            const { data } = await api.get<LogsPaginated<ActivityLogRow>>(
                '/api/admin/logs/activity',
                { params: buildActivityParams(q) },
            );
            return data;
        },
        staleTime: 15_000,
        retry: false,
    });
}

// ---------------------------------------------------------------------------
// Failed jobs
// ---------------------------------------------------------------------------

export interface FailedJobRow {
    id: number;
    uuid: string | null;
    connection: string | null;
    queue: string | null;
    display_name: string | null;
    job_class: string | null;
    attempts: number | null;
    exception: string | null;
    failed_at: string | null;
}

export interface FailedJobsQuery {
    page?: number;
}

function buildFailedJobParams(q: FailedJobsQuery): Record<string, string> {
    const p: Record<string, string> = {};
    if (typeof q.page === 'number') p.page = String(q.page);
    return p;
}

export const FAILED_JOBS_KEY = ['admin', 'logs', 'failed-jobs'] as const;

export function useFailedJobs(q: FailedJobsQuery = {}) {
    return useQuery<LogsPaginated<FailedJobRow>>({
        queryKey: [...FAILED_JOBS_KEY, q],
        queryFn: async () => {
            const { data } = await api.get<LogsPaginated<FailedJobRow>>(
                '/api/admin/logs/failed-jobs',
                { params: buildFailedJobParams(q) },
            );
            return data;
        },
        staleTime: 15_000,
        retry: false,
    });
}
