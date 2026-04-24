import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../../lib/api';

/*
 * Phase H2 — admin Maintenance command runner HTTP + TanStack Query.
 *
 * Mirrors routes/api.php `/api/admin/commands/*` exactly. Keep this
 * module in lockstep with MaintenanceCommandController (R9 — docs
 * match code).
 *
 * Every destructive run flows through preview → confirm → run with the
 * server-issued confirm_token. The wizard holds that token in local
 * state; we never persist it — it lives for 5 minutes server-side and
 * is single-use on /run.
 */

// ---------------------------------------------------------------------------
// Catalogue
// ---------------------------------------------------------------------------

export interface CommandArgsSchemaRule {
    type: 'string' | 'int' | 'bool';
    required?: boolean;
    nullable?: boolean;
    min?: number;
    max?: number;
    enum?: Array<string | number>;
}

export interface CatalogueEntry {
    description: string;
    destructive: boolean;
    args_schema: Record<string, CommandArgsSchemaRule>;
    requires_permission: string;
}

export interface CatalogueResponse {
    data: Record<string, CatalogueEntry>;
}

export const COMMAND_CATALOGUE_KEY = ['admin', 'commands', 'catalogue'] as const;

export function useCommandCatalogue() {
    return useQuery<CatalogueResponse>({
        queryKey: COMMAND_CATALOGUE_KEY,
        queryFn: async () => {
            const { data } = await api.get<CatalogueResponse>(
                '/api/admin/commands/catalogue',
            );
            return data;
        },
        staleTime: 60_000,
        retry: false,
    });
}

// ---------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------

export interface PreviewRequest {
    command: string;
    args: Record<string, unknown>;
}

export interface PreviewResponse {
    command: string;
    args_validated: Record<string, unknown>;
    destructive: boolean;
    description: string;
    confirm_token?: string;
    confirm_token_expires_at?: string;
}

export function usePreviewCommand() {
    return useMutation<PreviewResponse, unknown, PreviewRequest>({
        mutationFn: async (body) => {
            const { data } = await api.post<PreviewResponse>(
                '/api/admin/commands/preview',
                body,
            );
            return data;
        },
    });
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

export interface RunRequest {
    command: string;
    args: Record<string, unknown>;
    confirm_token?: string;
}

export interface RunResponse {
    audit_id: number;
    exit_code: number;
    stdout_head: string;
    duration_ms: number;
}

export function useRunCommand() {
    const qc = useQueryClient();
    return useMutation<RunResponse, unknown, RunRequest>({
        mutationFn: async (body) => {
            const { data } = await api.post<RunResponse>(
                '/api/admin/commands/run',
                body,
            );
            return data;
        },
        onSuccess: () => {
            // Invalidate the history table so a new run appears at top.
            qc.invalidateQueries({ queryKey: ['admin', 'commands', 'history'] });
        },
    });
}

// ---------------------------------------------------------------------------
// History
// ---------------------------------------------------------------------------

export interface HistoryRow {
    id: number;
    user_id: number | null;
    command: string;
    args_json: Record<string, unknown>;
    status: 'started' | 'completed' | 'failed' | 'rejected';
    exit_code: number | null;
    stdout_head: string | null;
    error_message: string | null;
    started_at: string | null;
    completed_at: string | null;
    client_ip: string | null;
    user_agent: string | null;
}

export interface HistoryResponse {
    data: HistoryRow[];
    meta: {
        current_page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
}

export interface HistoryQuery {
    command?: string;
    status?: string;
    from?: string;
    to?: string;
    page?: number;
}

function buildHistoryParams(q: HistoryQuery): Record<string, string> {
    const p: Record<string, string> = {};
    if (q.command && q.command.trim() !== '') p.command = q.command.trim();
    if (q.status && q.status.trim() !== '') p.status = q.status.trim();
    if (q.from && q.from.trim() !== '') p.from = q.from.trim();
    if (q.to && q.to.trim() !== '') p.to = q.to.trim();
    if (typeof q.page === 'number') p.page = String(q.page);
    return p;
}

export const HISTORY_KEY = ['admin', 'commands', 'history'] as const;

export function useCommandHistory(q: HistoryQuery = {}, opts: { pollMs?: number } = {}) {
    return useQuery<HistoryResponse>({
        queryKey: [...HISTORY_KEY, q],
        queryFn: async () => {
            const { data } = await api.get<HistoryResponse>(
                '/api/admin/commands/history',
                { params: buildHistoryParams(q) },
            );
            return data;
        },
        staleTime: opts.pollMs ? 0 : 15_000,
        refetchInterval: opts.pollMs ?? false,
        retry: false,
    });
}

// ---------------------------------------------------------------------------
// Scheduler status
// ---------------------------------------------------------------------------

export interface ScheduledCommand {
    command: string;
    cron_time: string;
    description: string;
}

export interface SchedulerStatusResponse {
    data: ScheduledCommand[];
}

export const SCHEDULER_KEY = ['admin', 'commands', 'scheduler-status'] as const;

export function useSchedulerStatus() {
    return useQuery<SchedulerStatusResponse>({
        queryKey: SCHEDULER_KEY,
        queryFn: async () => {
            const { data } = await api.get<SchedulerStatusResponse>(
                '/api/admin/commands/scheduler-status',
            );
            return data;
        },
        staleTime: 60_000,
        retry: false,
    });
}
