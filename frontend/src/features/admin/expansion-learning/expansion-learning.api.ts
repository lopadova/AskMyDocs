import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../../lib/api';
import { toAdminError } from '../shared/errors';

/**
 * Admin "Expansion learning" client — the query-expansion learning loop.
 *
 * Wraps `/api/admin/kb/expansion-learning` (status / analyze / apply /
 * reject / training / model refresh-promote-retire). Unlike most admin
 * endpoints these answers are NOT wrapped in `{ data }`: the controller
 * returns the service's presented arrays verbatim.
 *
 * R43 — the whole loop sits behind `KB_EXPANSION_LEARNING_ENABLED`. With the
 * flag OFF every endpoint answers `404 { code: 'expansion_learning_disabled' }`;
 * this module turns that exact response into a typed
 * {@link ExpansionLearningDisabledError} so the page renders one clean
 * "unavailable" panel. Every other failure (403 `super_admin_required`,
 * 422 `refused`, 404 `not_found`, 503 `storage_failed`, 5xx) is rethrown
 * untouched; callers map it with {@link expansionLearningErrorMessage}.
 */

export const EXPANSION_LEARNING_URL = '/api/admin/kb/expansion-learning';
export const EXPANSION_LEARNING_QUERY_KEY = ['admin-kb-expansion-learning'] as const;

/** Mirror of `OpenAiFineTuningBackend::BASE_MODELS` (code-owned allow-list; the server stays authoritative). */
export const OPENAI_BASE_MODELS = ['gpt-4.1-mini-2025-04-14', 'gpt-4.1-nano-2025-04-14', 'gpt-4o-mini-2024-07-18'] as const;

export type TrainingBackend = 'export' | 'openai';
export type GateStatus = 'passed' | 'failed' | 'not_measurable' | 'skipped';

export interface ExpansionWeights {
    lex?: number | null;
    vec?: number | null;
    hyde?: number | null;
}

export interface FewShotExample {
    query?: string | null;
    lex?: string[] | null;
    vec?: string[] | null;
    hyde?: string | null;
}

export interface ExpansionGate {
    status?: GateStatus | string | null;
    [metric: string]: unknown;
}

export interface ExpansionTuning {
    id: number;
    project_key: string;
    status: 'proposed' | 'applied' | 'rejected' | 'superseded' | string;
    weights: ExpansionWeights | null;
    few_shot_count: number;
    few_shot: FewShotExample[] | null;
    evidence: Record<string, unknown> | null;
    gate: ExpansionGate | null;
    proposed_by: string | null;
    decided_by: string | null;
    decided_at: string | null;
    created_at: string | null;
}

export interface ExpansionModel {
    id: number;
    project_key: string | null;
    backend: TrainingBackend | string;
    base_model: string | null;
    dataset_rows: number | null;
    status: 'pending' | 'submitted' | 'training' | 'ready' | 'failed' | 'promoted' | 'retired' | string;
    failure_reason: string | null;
    model_id: string | null;
    created_by: string | null;
    promoted_by: string | null;
    promoted_at: string | null;
    created_at: string | null;
}

export interface ExpansionLearningStatus {
    enabled: boolean;
    auto_apply: boolean;
    training_consent: boolean;
    events: { total: number; last_30_days: number; with_feedback: number };
    applied: ExpansionTuning | null;
    proposals: ExpansionTuning[];
    models: ExpansionModel[];
}

export interface TrainPayload {
    project_key?: string;
    backend: TrainingBackend;
    base_model?: string;
    confirm: true;
}

export class ExpansionLearningDisabledError extends Error {
    constructor(message = 'The expansion learning loop is disabled on this deployment.') {
        super(message);
        this.name = 'ExpansionLearningDisabledError';
    }
}

/** True when `err` is the backend's feature-flag-OFF answer. */
export function isExpansionLearningDisabled(err: unknown): boolean {
    if (err instanceof ExpansionLearningDisabledError) return true;
    const response = (err as { response?: { status?: number; data?: { code?: string } } } | null)?.response;
    return response?.status === 404 && response.data?.code === 'expansion_learning_disabled';
}

/** Human-readable message for a failed call (server messages are shown verbatim, R14). */
export function expansionLearningErrorMessage(err: unknown): string {
    if (err instanceof ExpansionLearningDisabledError) return err.message;
    return toAdminError(err).message;
}

function rethrow(err: unknown): never {
    if (isExpansionLearningDisabled(err) && !(err instanceof ExpansionLearningDisabledError)) {
        throw new ExpansionLearningDisabledError();
    }
    throw err;
}

async function post<T>(path: string, body?: Record<string, unknown>): Promise<T> {
    try {
        const { data } = await api.post<T>(`${EXPANSION_LEARNING_URL}${path}`, body ?? {});
        return data;
    } catch (err) {
        return rethrow(err);
    }
}

export const adminExpansionLearningApi = {
    async status(projectKey?: string): Promise<ExpansionLearningStatus> {
        try {
            const { data } = await api.get<ExpansionLearningStatus>(
                EXPANSION_LEARNING_URL,
                projectKey ? { params: { project_key: projectKey } } : undefined,
            );
            return data;
        } catch (err) {
            return rethrow(err);
        }
    },

    analyze(projectKey: string): Promise<{ queued: boolean; project_key: string }> {
        return post('/analyze', { project_key: projectKey });
    },

    applyTuning(id: number): Promise<ExpansionTuning> {
        return post(`/tunings/${id}/apply`);
    },

    rejectTuning(id: number): Promise<ExpansionTuning> {
        return post(`/tunings/${id}/reject`);
    },

    train(payload: TrainPayload): Promise<ExpansionModel> {
        const body: Record<string, unknown> = { backend: payload.backend, confirm: true };
        if (payload.project_key) body.project_key = payload.project_key;
        if (payload.backend === 'openai' && payload.base_model) body.base_model = payload.base_model;
        return post('/training', body);
    },

    refreshModel(id: number): Promise<ExpansionModel> {
        return post(`/models/${id}/refresh`);
    },

    promoteModel(id: number): Promise<ExpansionModel> {
        return post(`/models/${id}/promote`, { confirm: true });
    },

    retireModel(id: number): Promise<ExpansionModel> {
        return post(`/models/${id}/retire`);
    },
};

export function useExpansionLearningStatus(projectKey: string) {
    return useQuery<ExpansionLearningStatus>({
        queryKey: [...EXPANSION_LEARNING_QUERY_KEY, projectKey],
        queryFn: () => adminExpansionLearningApi.status(projectKey === '' ? undefined : projectKey),
        staleTime: 30_000,
        // No `retry` override: the app-wide default already skips 4xx, so the
        // flag-OFF 404 is never retried.
    });
}

/**
 * Every mutation re-reads the status on success; on the flag-OFF 404 it
 * re-reads too, so a flag flipped off mid-session lands the page on the
 * "unavailable" panel instead of stale tables.
 */
function useStatusMutation<TVars, TResult>(fn: (vars: TVars) => Promise<TResult>) {
    const qc = useQueryClient();
    const refresh = () => qc.invalidateQueries({ queryKey: EXPANSION_LEARNING_QUERY_KEY });
    return useMutation<TResult, unknown, TVars>({
        mutationFn: fn,
        onSuccess: () => refresh(),
        onError: (err) => {
            if (isExpansionLearningDisabled(err)) void refresh();
        },
    });
}

export function useAnalyzeExpansion() {
    return useStatusMutation((projectKey: string) => adminExpansionLearningApi.analyze(projectKey));
}

export function useApplyTuning() {
    return useStatusMutation((id: number) => adminExpansionLearningApi.applyTuning(id));
}

export function useRejectTuning() {
    return useStatusMutation((id: number) => adminExpansionLearningApi.rejectTuning(id));
}

export function useStartTraining() {
    return useStatusMutation((payload: TrainPayload) => adminExpansionLearningApi.train(payload));
}

export function useRefreshModel() {
    return useStatusMutation((id: number) => adminExpansionLearningApi.refreshModel(id));
}

export function usePromoteModel() {
    return useStatusMutation((id: number) => adminExpansionLearningApi.promoteModel(id));
}

export function useRetireModel() {
    return useStatusMutation((id: number) => adminExpansionLearningApi.retireModel(id));
}
